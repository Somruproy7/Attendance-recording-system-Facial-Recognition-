<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || 
    (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') && 
    (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'lecturer')) {
    header('Location: ../login.php');
    exit();
}

require_once 'includes/header.php';

$db = new Database();
$conn = $db->getConnection();
$lecturer_id = $_SESSION['user_id'];

// Get lecturer's profile information
$stmt = $conn->prepare("
    SELECT id, user_id, first_name, last_name, email, phone, department, bio, 
           profile_image, status, created_at
    FROM users 
    WHERE id = ? AND user_type IN ('lecturer', 'admin')
");
$stmt->execute([$lecturer_id]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturer) {
    $_SESSION['error_message'] = 'Lecturer profile not found.';
    header('Location: ../login.php');
    exit();
}

// Get lecturer's classes
$classes = [];
$stmt = $conn->prepare("
    SELECT c.id, c.class_code, c.class_name, c.description,
           c.semester, c.academic_year
    FROM classes c
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = ? AND c.status = 'active'
    ORDER BY c.academic_year DESC, c.semester, c.class_code
");
$stmt->execute([$lecturer_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming sessions (next 7 days)
$upcomingSessions = [];
$today = date('Y-m-d');
$nextWeek = date('Y-m-d', strtotime('+7 days'));

$stmt = $conn->prepare("
    SELECT 
        si.id as instance_id,
        c.id as class_id,
        c.class_code,
        c.class_name,
        ts.session_title,
        si.session_date,
        ts.start_time,
        ts.end_time,
        ts.room_location,
        si.status,
        (SELECT COUNT(*) FROM attendance_records ar 
         WHERE ar.session_instance_id = si.id AND ar.status = 'present') as present_count,
        (SELECT COUNT(*) FROM student_enrollments se 
         WHERE se.class_id = c.id AND se.status = 'enrolled') as total_students
    FROM session_instances si
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = ?
    AND si.session_date BETWEEN ? AND ?
    AND si.status != 'cancelled'
    ORDER BY si.session_date, ts.start_time
    LIMIT 5
");

$stmt->execute([$lecturer_id, $today, $nextWeek]);
$upcomingSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent attendance
$recentAttendance = [];
$stmt = $conn->prepare("
    SELECT 
        ar.id,
        u.first_name,
        u.last_name,
        u.user_id as student_id,
        c.class_code,
        c.class_name,
        ar.status,
        ar.check_in_time,
        ar.recognition_confidence,
        si.session_date,
        ts.session_title
    FROM attendance_records ar
    JOIN users u ON ar.student_id = u.id
    JOIN session_instances si ON ar.session_instance_id = si.id
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = ?
    ORDER BY ar.check_in_time DESC
    LIMIT 5
");

$stmt->execute([$lecturer_id]);
$recentAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <div class="header-content d-flex align-items-center">
        <div class="lecturer-avatar me-3">
            <?php if (!empty($lecturer['profile_image']) && file_exists('../uploads/profiles/' . $lecturer['profile_image'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_image']) ?>" 
                     alt="<?= htmlspecialchars($lecturer['first_name'] . ' ' . $lecturer['last_name']) ?>"
                     class="rounded-circle" width="60" height="60" style="object-fit: cover;">
            <?php else: ?>
                <div class="avatar-placeholder rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" 
                     style="width: 60px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                    <?= strtoupper(substr($lecturer['first_name'], 0, 1) . substr($lecturer['last_name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
        </div>
        <div>
            <h1>Welcome back, <?= htmlspecialchars($lecturer['first_name'] . ' ' . $lecturer['last_name']) ?>!</h1>
            <p class="mb-0 text-muted">
                <?= date('l, F j, Y') ?>
                <?php if (!empty($lecturer['department'])): ?>
                    • <?= htmlspecialchars($lecturer['department']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="header-actions">
        <a href="sessions/create.php" class="btn btn-primary me-2">
            <i class="fas fa-plus me-1"></i> New Session
        </a>
        <a href="profile.php" class="btn btn-outline-secondary">
            <i class="fas fa-user-edit me-1"></i> Edit Profile
        </a>
    </div>
</div>

<div class="container-fluid">

    <!-- Quick Stats -->
    <div class="row mb-4">
        <?php
        // Get stats
        $stats = [
            'total_classes' => count($classes),
            'active_sessions' => 0,
            'total_students' => 0,
            'avg_attendance' => 0
        ];

        // Count active sessions
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM session_instances si
            JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
            JOIN class_lecturers cl ON ts.class_id = cl.class_id
            WHERE cl.lecturer_id = ? AND si.status = 'in_progress'
        ");
        $stmt->execute([$lecturer_id]);
        $stats['active_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Count total students across all classes
        if (!empty($classes)) {
            $class_ids = array_column($classes, 'id');
            $placeholders = str_repeat('?,', count($class_ids) - 1) . '?';
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT student_id) as count 
                FROM student_enrollments 
                WHERE class_id IN ($placeholders) AND status = 'enrolled'
            ");
            $stmt->execute($class_ids);
            $stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Calculate average attendance
        $stmt = $conn->prepare("
            SELECT AVG(
                (SELECT COUNT(*) FROM attendance_records ar 
                 WHERE ar.session_instance_id = si.id AND ar.status = 'present') * 100.0 /
                NULLIF((SELECT COUNT(*) FROM student_enrollments se 
                       WHERE se.class_id = c.id AND se.status = 'enrolled'), 0)
            ) as avg_attendance
            FROM session_instances si
            JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
            JOIN classes c ON ts.class_id = c.id
            JOIN class_lecturers cl ON c.id = cl.class_id
            WHERE cl.lecturer_id = ?
            AND si.status = 'completed'
        ");
        $stmt->execute([$lecturer_id]);
        $stats['avg_attendance'] = round($stmt->fetch(PDO::FETCH_ASSOC)['avg_attendance'] ?? 0, 1);
        ?>
        
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 bg-primary bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Classes</h6>
                            <h2 class="mb-0"><?= $stats['total_classes'] ?></h2>
                        </div>
                        <div class="icon-shape bg-primary bg-opacity-25 rounded-3 p-3">
                            <i class="fas fa-chalkboard-teacher text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 bg-success bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Active Sessions</h6>
                            <h2 class="mb-0"><?= $stats['active_sessions'] ?></h2>
                        </div>
                        <div class="icon-shape bg-success bg-opacity-25 rounded-3 p-3">
                            <i class="fas fa-user-clock text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 bg-info bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Total Students</h6>
                            <h2 class="mb-0"><?= $stats['total_students'] ?></h2>
                        </div>
                        <div class="icon-shape bg-info bg-opacity-25 rounded-3 p-3">
                            <i class="fas fa-users text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 bg-warning bg-opacity-10 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase text-muted mb-1">Avg. Attendance</h6>
                            <h2 class="mb-0"><?= $stats['avg_attendance'] ?>%</h2>
                        </div>
                        <div class="icon-shape bg-warning bg-opacity-25 rounded-3 p-3">
                            <i class="fas fa-percentage text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Upcoming Sessions -->
        <div class="col-lg-8 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Upcoming Sessions</h5>
                    <a href="sessions/" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingSessions)): ?>
                    <i class="fas fa-calendar-day"></i>
                    <p>No upcoming sessions in the next 7 days</p>
                </div>
            <?php else: ?>
                <div class="session-list">
                    <?php foreach ($upcomingSessions as $session): ?>
                        <div class="session-item">
                            <div class="session-date">
                                <div class="date">
                                    <?php echo date('M j', strtotime($session['session_date'])); ?>
                                </div>
                                <div class="time">
                                    <?php echo date('g:i A', strtotime($session['start_time'])); ?>
                                </div>
                            </div>
                            <div class="session-details">
                                <h4><?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></h4>
                                <p class="muted">
                                    <?php echo htmlspecialchars($session['session_title']); ?> • 
                                    <?php echo htmlspecialchars($session['room_location']); ?>
                                </p>
                                <div class="session-stats">
                                    <span class="badge bg-<?php echo $session['status'] === 'in_progress' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $session['status'])); ?>
                                    </span>
                                    <span class="muted">
                                        <?php echo $session['present_count'] . '/' . $session['total_students']; ?> students
                                    </span>
                                </div>
                            </div>
                            <div class="session-actions">
                                <?php if ($session['status'] === 'scheduled'): ?>
                                    <a href="sessions/start.php?id=<?php echo $session['instance_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        Start
                                    </a>
                                <?php elseif ($session['status'] === 'in_progress'): ?>
                                    <a href="attendance/take.php?session=<?php echo $session['instance_id']; ?>" 
                                       class="btn btn-sm btn-success">
                                        Take Attendance
                                    </a>
                                    <a href="sessions/end.php?id=<?php echo $session['instance_id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Are you sure you want to end this session?');">
                                        End
                                    </a>
                                <?php else: ?>
                                    <a href="sessions/view.php?id=<?php echo $session['instance_id']; ?>" 
                                       class="btn btn-sm btn-outline-secondary">
                                        View
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Attendance Card -->
    <div class="card">
        <div class="card-header">
            <h2>Recent Attendance</h2>
            <a href="reports/" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentAttendance)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-clock"></i>
                    <p>No recent attendance records found</p>
                </div>
            <?php else: ?>
                <div class="attendance-list">
                    <?php foreach ($recentAttendance as $record): ?>
                        <div class="attendance-item">
                            <div class="student-avatar">
                                <?php echo strtoupper(substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1)); ?>
                            </div>
                            <div class="attendance-details">
                                <h4>
                                    <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>
                                    <span class="badge bg-<?php echo $record['status'] === 'present' ? 'success' : ($record['status'] === 'late' ? 'warning' : 'danger'); ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </h4>
                                <p class="muted">
                                    <?php echo htmlspecialchars($record['class_code'] . ' • ' . date('M j, g:i A', strtotime($record['check_in_time']))); ?>
                                    <?php if ($record['recognition_confidence']): ?>
                                        <span class="confidence" title="Recognition Confidence">
                                            <?php echo round($record['recognition_confidence'] * 100); ?>%
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Stats -->
<div class="stats-container">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chalkboard-teacher"></i>
        </div>
        <div class="stat-content">
            <h3>Active Classes</h3>
            <div class="stat-value">
                <?php
                $stmt = $conn->prepare("
                    SELECT COUNT(DISTINCT c.id) as count
                    FROM classes c
                    JOIN class_lecturers cl ON c.id = cl.class_id
                    WHERE cl.lecturer_id = :lecturer_id
                    AND c.status = 'active'
                ");
                $stmt->execute(['lecturer_id' => $_SESSION['user_id']]);
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo $count;
                ?>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-content">
            <h3>Sessions This Week</h3>
            <div class="stat-value">
                <?php
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count
                    FROM session_instances si
                    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                    JOIN class_lecturers cl ON ts.class_id = cl.class_id
                    WHERE cl.lecturer_id = :lecturer_id
                    AND si.session_date BETWEEN :start_week AND :end_week
                ");
                $stmt->execute([
                    'lecturer_id' => $_SESSION['user_id'],
                    'start_week' => date('Y-m-d', strtotime('monday this week')),
                    'end_week' => date('Y-m-d', strtotime('sunday this week'))
                ]);
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo $count;
                ?>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-content">
            <h3>Students Enrolled</h3>
            <div class="stat-value">
                <?php
                $stmt = $conn->prepare("
                    SELECT COUNT(DISTINCT se.student_id) as count
                    FROM student_enrollments se
                    JOIN class_lecturers cl ON se.class_id = cl.class_id
                    WHERE cl.lecturer_id = :lecturer_id
                    AND se.status = 'enrolled'
                ");
                $stmt->execute(['lecturer_id' => $_SESSION['user_id']]);
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo $count;
                ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
