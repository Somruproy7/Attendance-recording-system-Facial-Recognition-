<?php
// sessions.php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lecturer') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Handle session actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['session_id'])) {
        try {
            $session_id = (int)$_POST['session_id'];
            $action = $_POST['action'];
            
            // Verify lecturer owns this session
            $stmt = $conn->prepare("
                SELECT si.id 
                FROM session_instances si
                JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                JOIN class_lecturers cl ON ts.class_id = cl.class_id
                WHERE si.id = :session_id 
                AND cl.lecturer_id = :lecturer_id
            ");
            $stmt->execute([
                'session_id' => $session_id,
                'lecturer_id' => $_SESSION['user_id']
            ]);
            
            if ($stmt->rowCount() > 0) {
                switch ($action) {
                    case 'start':
                        // Start transaction
                        $conn->beginTransaction();
                        
                        try {
                            // Update session status
                            $stmt = $conn->prepare("
                                UPDATE session_instances 
                                SET status = 'in_progress', 
                                    actual_start_time = NOW() 
                                WHERE id = :session_id
                                AND status = 'scheduled'
                            ");
                            $stmt->execute(['session_id' => $session_id]);
                            
                            if ($stmt->rowCount() > 0) {
                                // Record that lecturer has started the session
                                $stmt = $conn->prepare("
                                    INSERT INTO attendance_records 
                                    (session_instance_id, lecturer_id, attendance_type, recorded_at)
                                    VALUES (:session_id, :lecturer_id, 'lecturer_start', NOW())
                                ");
                                $stmt->execute([
                                    'session_id' => $session_id,
                                    'lecturer_id' => $_SESSION['user_id']
                                ]);
                                
                                $conn->commit();
                                $success = "Session started successfully.";
                            } else {
                                throw new Exception("Failed to start session");
                            }
                        } catch (Exception $e) {
                            $conn->rollBack();
                            throw $e;
                        }
                        break;
                        
                    case 'end':
                        $stmt = $conn->prepare("
                            UPDATE session_instances 
                            SET status = 'completed', 
                                actual_end_time = NOW() 
                            WHERE id = :session_id
                            AND status = 'in_progress'
                        ");
                        break;
                }
                
                if (isset($stmt)) {
                    $stmt->execute(['session_id' => $session_id]);
                    $success = "Session updated successfully.";
                }
            } else {
                $error = "Session not found or access denied.";
            }
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get lecturer's upcoming sessions
try {
    // Get lecturer info
    $stmt = $conn->prepare("
        SELECT id, first_name, last_name, user_id 
        FROM users 
        WHERE id = :user_id AND user_type = 'lecturer' AND status = 'active'");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $lecturer = $stmt->fetch();

    if (!$lecturer) {
        throw new Exception("Lecturer not found or inactive");
    }

    // Get upcoming sessions (next 7 days)
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime('+7 days'));
    
    $stmt = $conn->prepare("
        SELECT 
            si.id as instance_id,
            si.session_date,
            si.status,
            si.actual_start_time,
            si.actual_end_time,
            ts.session_title,
            ts.start_time,
            ts.end_time,
            c.class_code,
            c.class_name,
            (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_instance_id = si.id) as attendance_count
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN class_lecturers cl ON c.id = cl.class_id
        WHERE cl.lecturer_id = :lecturer_id
        AND si.session_date BETWEEN :start_date AND :end_date
        ORDER BY si.session_date, ts.start_time");
        
    $stmt->execute([
        'lecturer_id' => $lecturer['id'],
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    $sessions = $stmt->fetchAll();
    
    // Group sessions by date
    $sessions_by_date = [];
    foreach ($sessions as $session) {
        $date = $session['session_date'];
        if (!isset($sessions_by_date[$date])) {
            $sessions_by_date[$date] = [];
        }
        $sessions_by_date[$date][] = $session;
    }

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $sessions_by_date = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sessions - Lecturer Portal</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .session-day {
            margin-bottom: 30px;
        }
        .session-day h3 {
            color: #4f46e5;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .session-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #4f46e5;
        }
        .session-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .session-time {
            color: #6b7280;
            font-size: 0.9em;
        }
        .session-actions {
            display: flex;
            gap: 10px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
            margin-left: 8px;
        }
        .status-scheduled { background: #e0e7ff; color: #4f46e5; }
        .status-in_progress { background: #dcfce7; color: #16a34a; }
        .status-completed { background: #f3f4f6; color: #6b7280; }
        .btn-start { background: #10b981; color: white; }
        .btn-end { background: #ef4444; color: white; }
        .btn-view { background: #3b82f6; color: white; }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="lecturer_dashboard.php">Dashboard</a>
                <a href="sessions.php" class="active">My Sessions</a>
                <a href="attendance_report.php">Attendance</a>
                <a href="reports.php">Reports</a>
                <a href="../logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="../images/fullattend_logo.png" alt="FullAttend Logo">
            </div>
        </aside>

        <!-- Main Content -->
        <main class="dashboard-content">
            <div class="dashboard-header">
                <h1>My Sessions</h1>
                <p class="muted">Manage your class sessions for the next 7 days</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (empty($sessions_by_date)): ?>
                <div class="empty-state">
                    <p>No upcoming sessions found for the next 7 days.</p>
                </div>
            <?php else: ?>
                <?php foreach ($sessions_by_date as $date => $sessions): ?>
                    <div class="session-day">
                        <h3><?php echo date('l, F j, Y', strtotime($date)); ?></h3>
                        
                        <?php foreach ($sessions as $session): ?>
                            <div class="session-card">
                                <div class="session-header">
                                    <div>
                                        <h4><?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['session_title']); ?></h4>
                                        <span class="session-time">
                                <?php echo date('h:i A', strtotime($session['start_time'])); ?> - 
                                <?php echo date('h:i A', strtotime($session['end_time'])); ?>
                                <?php if ($session['status'] === 'in_progress'): ?>
                                    <span class="badge badge-success">In Progress</span>
                                <?php endif; ?>
                            </span>                <span class="status-badge status-<?php echo $session['status']; ?>">
                                                <?php 
                                                    $status_map = [
                                                        'scheduled' => 'Scheduled',
                                                        'in_progress' => 'In Progress',
                                                        'completed' => 'Completed'
                                                    ];
                                                    echo $status_map[$session['status']] ?? $session['status'];
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="session-actions">
                                <?php if ($session['status'] === 'scheduled'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to start this session? Students will be able to mark attendance after you start.');">
                                        <input type="hidden" name="action" value="start">
                                        <input type="hidden" name="session_id" value="<?php echo $session['instance_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">Start Session</button>
                                    </form>
                                <?php elseif ($session['status'] === 'in_progress'): ?>
                                    <form method="POST" style="display:inline; margin-right: 5px;" onsubmit="return confirm('Are you sure you want to end this session? Students will no longer be able to mark attendance.');">
                                        <input type="hidden" name="action" value="end">
                                        <input type="hidden" name="session_id" value="<?php echo $session['instance_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">End Session</button>
                                    </form>
                                    <a href="session_attendance.php?session_id=<?php echo $session['instance_id']; ?>" class="btn btn-sm btn-success">Take Attendance</a>
                                    <span class="text-success" style="margin-left: 10px;">
                                        <i class="fas fa-check-circle"></i> Session Active
                                    </span>
                                <?php elseif ($session['status'] === 'completed'): ?>
                                    <div class="session-stats">
                                        <span class="muted">
                                            <?php echo $session['attendance_count'] ?? 0; ?> students attended • 
                                            <?php 
                                                if (!empty($session['actual_start_time']) && !empty($session['actual_end_time'])) {
                                                    $start = new DateTime($session['actual_start_time']);
                                                    $end = new DateTime($session['actual_end_time']);
                                                    $duration = $start->diff($end);
                                                    echo $duration->format('%h hr %i min');
                                                } else {
                                                    echo 'N/A';
                                                }
                                            ?> duration
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
