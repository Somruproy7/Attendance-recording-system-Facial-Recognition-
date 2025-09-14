<?php
// admin_dashboard.php
session_start();
require_once 'config/database.php';
require_once __DIR__ . '/ensure_today_sessions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    // Ensure today's session instances exist for sessions scheduled today
    ensure_todays_session_instances($conn);
    // Get class statistics
    $stmt = $conn->query("
        SELECT 
            c.id,
            c.class_code,
            c.class_name,
            COUNT(DISTINCT se.student_id) as total_students,
            COUNT(DISTINCT si.id) as total_sessions,
            COALESCE(AVG(
                CASE WHEN ar.status = 'present' THEN 100 
                     WHEN ar.status = 'late' THEN 50 
                     ELSE 0 END
            ), 0) as avg_attendance
        FROM classes c
        LEFT JOIN student_enrollments se ON c.id = se.class_id AND se.status = 'enrolled'
        LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
        LEFT JOIN session_instances si ON ts.id = si.timetable_session_id AND si.status = 'completed'
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
        WHERE c.status = 'active'
        GROUP BY c.id, c.class_code, c.class_name
        ORDER BY c.class_code
    ");
    $classes = $stmt->fetchAll();
    
    // Get today's sessions
    $stmt = $conn->prepare("
        SELECT 
            si.id,
            c.class_name,
            c.class_code,
            ts.session_title,
            ts.start_time,
            ts.end_time,
            ts.room_location,
            si.status,
            COUNT(ar.id) as total_attendance,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
        WHERE si.session_date = CURDATE()
        GROUP BY si.id, c.class_name, c.class_code, ts.session_title, ts.start_time, ts.end_time, ts.room_location, si.status
        ORDER BY ts.start_time
    ");
    $todaysSessions = $stmt->fetchAll();
    
    // Get overall statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(DISTINCT u.id) as total_students,
            COUNT(DISTINCT c.id) as total_classes,
            COUNT(DISTINCT si.id) as total_sessions_today,
            COALESCE(AVG(
                CASE WHEN ar.status = 'present' THEN 100 
                     WHEN ar.status = 'late' THEN 50 
                     ELSE 0 END
            ), 0) as overall_attendance
        FROM users u
        LEFT JOIN student_enrollments se ON u.id = se.student_id
        LEFT JOIN classes c ON se.class_id = c.id
        LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
        LEFT JOIN session_instances si ON ts.id = si.timetable_session_id 
            AND si.session_date = CURDATE()
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id 
            AND ar.student_id = u.id
        WHERE u.user_type = 'student' AND u.status = 'active'
    ");
    $stats = $stmt->fetch();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Full Attend</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body class="admin-portal">
    <div class="dashboard">
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="admin_dashboard.php" class="active">Dashboard</a>
                <a href="class_management.php">Class Management</a>
                <a href="student_directory.php">Student Directory</a>
                <a href="student_registration.php">Student Management</a>
                <a href="admin/lecturer_management.php">Lecturer Management</a>
                <a href="timetable_management.php">Timetable</a>
                <a href="reports.php">Reports</a>
                <a href="settings.php">Settings</a>
                <a href="logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="images/fullattend_logo.png" alt="FullAttend Logo">
            </div>
        </aside>
        
        <main class="dashboard-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1>Admin Dashboard</h1>
                    <p class="muted">Semester-1, 2025 - Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                </div>
                <div class="toolbar">
                    <span class="pill">Today: <?php echo date('M d, Y'); ?></span>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Overall Statistics -->
            <div class="class-cards">
                <div class="card">
                    <h3>Total Students</h3>
                    <p class="muted">Active enrollments</p>
                    <h2><?php echo number_format($stats['total_students'] ?? 0); ?></h2>
                </div>
                <div class="card">
                    <h3>Active Classes</h3>
                    <p class="muted">This semester</p>
                    <h2><?php echo number_format($stats['total_classes'] ?? 0); ?></h2>
                </div>
                <div class="card">
                    <h3>Today's Sessions</h3>
                    <p class="muted">Scheduled</p>
                    <h2><?php echo number_format($stats['total_sessions_today'] ?? 0); ?></h2>
                </div>
                <div class="card">
                    <h3>Overall Attendance</h3>
                    <p class="muted">This semester</p>
                    <h2><?php echo number_format($stats['overall_attendance'] ?? 0, 1); ?>%</h2>
                </div>
            </div>
            
            <!-- Class Cards -->
            <section style="margin-top: 30px;">
                <h2>Classes Overview</h2>
                <div class="class-cards">
                    <?php foreach ($classes as $class): ?>
                    <div class="card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <h3><?php echo htmlspecialchars($class['class_code']); ?></h3>
                            <span class="status-dot <?php echo $class['avg_attendance'] >= 80 ? 'present' : ($class['avg_attendance'] >= 60 ? 'late' : 'absent'); ?>"></span>
                        </div>
                        <p style="font-weight: 600; margin-bottom: 5px;"><?php echo htmlspecialchars($class['class_name']); ?></p>
                        <p class="muted" style="margin-bottom: 10px;"><?php echo $class['total_students']; ?> students • <?php echo $class['total_sessions']; ?> sessions</p>
                        <div style="font-size: 24px; font-weight: 700; color: #4f46e5;">
                            <?php echo number_format($class['avg_attendance'], 1); ?>%
                        </div>
                        <p class="muted">Avg Attendance</p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            
            <!-- Today's Sessions -->
            <section class="scanning-status" style="margin-top: 30px;">
                <h2>Today's Sessions</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Class</th>
                            <th>Session</th>
                            <th>Room</th>
                            <th>Status</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($todaysSessions)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #6b7280; padding: 20px;">
                                No sessions scheduled for today
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($todaysSessions as $session): ?>
                        <tr>
                            <td><?php echo date('H:i', strtotime($session['start_time'])) . '-' . date('H:i', strtotime($session['end_time'])); ?></td>
                            <td><?php echo htmlspecialchars($session['class_code']); ?></td>
                            <td><?php echo htmlspecialchars($session['session_title']); ?></td>
                            <td><?php echo htmlspecialchars($session['room_location'] ?? 'TBA'); ?></td>
                            <td>
                                <span class="chip <?php echo $session['status']; ?>">
                                    <?php echo ucfirst($session['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $session['present_count'] ?? 0; ?></td>
                            <td><?php echo $session['absent_count'] ?? 0; ?></td>
                            <td><?php echo $session['late_count'] ?? 0; ?></td>
                            <td>
                                <a href="class_management.php?session_id=<?php echo $session['id']; ?>" class="btn">
                                    Manage
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>