<?php
// lecturer_dashboard.php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lecturer') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Get lecturer's classes and sessions
try {
    // Get lecturer's basic info
    $stmt = $conn->prepare("
        SELECT id, first_name, last_name, email, user_id 
        FROM users 
        WHERE id = :user_id AND user_type = 'lecturer' AND status = 'active'");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $lecturer = $stmt->fetch();

    if (!$lecturer) {
        throw new Exception("Lecturer not found or inactive");
    }

    // Get lecturer's assigned classes
    $stmt = $conn->prepare("
        SELECT c.id, c.class_code, c.class_name
        FROM classes c
        JOIN class_lecturers cl ON c.id = cl.class_id
        WHERE cl.lecturer_id = :lecturer_id
        AND c.status = 'active'
        ORDER BY c.class_code");
    $stmt->execute(['lecturer_id' => $lecturer['id']]);
    $classes = $stmt->fetchAll();

    // Get today's sessions
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT 
            si.id as instance_id,
            ts.session_title,
            ts.start_time,
            ts.end_time,
            c.class_code,
            c.class_name,
            si.status,
            (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_instance_id = si.id) as attendance_count
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN class_lecturers cl ON c.id = cl.class_id
        WHERE cl.lecturer_id = :lecturer_id
        AND si.session_date = :today
        ORDER BY ts.start_time");
    $stmt->execute([
        'lecturer_id' => $lecturer['id'],
        'today' => $today
    ]);
    $today_sessions = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $classes = [];
    $today_sessions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard - Full Attend</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .welcome-message h1 {
            margin: 0;
        }
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card h3 {
            margin-top: 0;
            color: #4f46e5;
        }
        .card .stat {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }
        .session-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        .btn-start {
            background: #10b981;
            color: white;
        }
        .btn-view {
            background: #3b82f6;
            color: white;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="lecturer_dashboard.php" class="active">Dashboard</a>
                <a href="sessions.php">My Sessions</a>
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
                <div class="welcome-message">
                    <h1>Welcome, <?php echo htmlspecialchars($lecturer['first_name']); ?></h1>
                    <p class="muted"><?php echo date('l, F j, Y'); ?></p>
                </div>
                <div class="user-info">
                    <span class="user-id">ID: <?php echo htmlspecialchars($lecturer['user_id']); ?></span>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="stats-cards">
                <div class="card">
                    <h3>Today's Sessions</h3>
                    <div class="stat"><?php echo count($today_sessions); ?></div>
                    <p class="muted">Scheduled for today</p>
                </div>
                <div class="card">
                    <h3>My Classes</h3>
                    <div class="stat"><?php echo count($classes); ?></div>
                    <p class="muted">Assigned classes</p>
                </div>
                <div class="card">
                    <h3>Active Now</h3>
                    <div class="stat">
                        <?php 
                        $active_sessions = array_filter($today_sessions, fn($s) => $s['status'] === 'in_progress');
                        echo count($active_sessions);
                        ?>
                    </div>
                    <p class="muted">Sessions in progress</p>
                </div>
            </div>

            <!-- Today's Sessions -->
            <section class="today-sessions">
                <h2>Today's Sessions</h2>
                <?php if (empty($today_sessions)): ?>
                    <p>No sessions scheduled for today.</p>
                <?php else: ?>
                    <?php foreach ($today_sessions as $session): ?>
                        <div class="session-card">
                            <div class="session-header">
                                <div>
                                    <h3><?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['session_title']); ?></h3>
                                    <div class="session-time">
                                        <?php echo date('h:i A', strtotime($session['start_time'])) . ' - ' . date('h:i A', strtotime($session['end_time'])); ?>
                                        <span class="status-badge">• <?php echo ucfirst($session['status']); ?></span>
                                    </div>
                                </div>
                                <div class="session-actions">
                                    <?php if ($session['status'] === 'scheduled'): ?>
                                        <a href="start_session.php?session_id=<?php echo $session['instance_id']; ?>" class="btn btn-start">Start Session</a>
                                    <?php elseif ($session['status'] === 'in_progress'): ?>
                                        <a href="session_attendance.php?session_id=<?php echo $session['instance_id']; ?>" class="btn btn-view">Take Attendance</a>
                                    <?php else: ?>
                                        <a href="session_report.php?session_id=<?php echo $session['instance_id']; ?>" class="btn btn-view">View Report</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="session-stats">
                                <span class="muted"><?php echo $session['attendance_count']; ?> students attended</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- Upcoming Classes -->
            <?php if (!empty($classes)): ?>
            <section class="my-classes">
                <h2>My Classes</h2>
                <div class="class-grid">
                    <?php foreach ($classes as $class): ?>
                        <a href="class_details.php?class_id=<?php echo $class['id']; ?>" class="class-card">
                            <h3><?php echo htmlspecialchars($class['class_code']); ?></h3>
                            <p class="muted"><?php echo htmlspecialchars($class['class_name']); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
