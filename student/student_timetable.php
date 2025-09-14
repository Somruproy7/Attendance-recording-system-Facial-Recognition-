<?php
session_start();
require_once '../config/database.php';

// Ensure student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

try {
    $stmt = $conn->prepare("SELECT 
            c.class_name,
            ts.session_title,
            ts.session_type,
            ts.day_of_week,
            ts.start_time,
            ts.end_time,
            ts.room_location,
            ts.instructor
        FROM student_enrollments se
        JOIN classes c ON se.class_id = c.id
        JOIN timetable_sessions ts ON c.id = ts.class_id
        WHERE se.student_id = :student_id AND se.status = 'enrolled' AND c.status = 'active'
        ORDER BY FIELD(ts.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), ts.start_time");
    $stmt->execute(['student_id' => $user_id]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    $rows = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timetable</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../student.css?v=<?php echo time(); ?>">
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            setInterval(() => { window.location.reload(); }, 60000);
        });
    </script>
</head>
<body class="student-portal">
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        <main class="dashboard-content">
            <div style="display:flex;justify-content:space-between;align-items:center; margin-bottom:16px;">
                <div>
                    <h1>My Weekly Timetable</h1>
                    <p class="muted">Auto-refreshes every minute to reflect admin updates</p>
                </div>
                <div class="toolbar">
                    <button class="btn" onclick="window.location.reload()">Refresh</button>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div style="background:#fee;color:#c33;padding:10px;border-radius:6px;max-width:900px;">&<?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($rows)): ?>
                <div class="scanning-status">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Class</th>
                                <th>Session</th>
                                <th>Type</th>
                                <th>Time</th>
                                <th>Room</th>
                                <th>Instructor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['day_of_week']); ?></td>
                                    <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['session_title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['session_type']); ?></td>
                                    <td><?php echo date('H:i', strtotime($row['start_time'])) . ' - ' . date('H:i', strtotime($row['end_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['room_location']); ?></td>
                                    <td><?php echo htmlspecialchars($row['instructor']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="scanning-status">
                    <p style="text-align:center; color:#6b7280; padding:20px;">No timetable found. You may not be enrolled in any classes.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
