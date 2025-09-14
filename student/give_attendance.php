<?php
// student/give_attendance.php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../ensure_today_sessions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$student_id = $_SESSION['user_id'];

// Face match threshold (default 0.6)
$face_threshold = 0.6;
try {
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'face_recognition_threshold'");
    $st->execute();
    $val = $st->fetchColumn();
    if ($val !== false && $val !== null) { $face_threshold = (float)$val; }
} catch (PDOException $e) { /* ignore */ }

// Ensure today's instances exist so dropdowns populate
ensure_todays_session_instances($conn);

// Get today's sessions for this student that are in progress
$stmt = $conn->prepare('
    SELECT si.id as instance_id, ts.session_title, ts.start_time, ts.end_time, c.class_code, c.class_name, si.status
    FROM student_enrollments se
    JOIN classes c ON se.class_id = c.id
    JOIN timetable_sessions ts ON ts.class_id = c.id
    JOIN session_instances si ON si.timetable_session_id = ts.id AND si.session_date = CURDATE()
    WHERE se.student_id = :sid 
      AND se.status = "enrolled" 
      AND c.status = "active"
      AND si.status = "in_progress"
    ORDER BY ts.start_time
');
$stmt->execute(['sid' => $student_id]);
$sessions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Give Attendance - Full Attend</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../student.css?v=<?php echo time(); ?>">
</head>
<body class="student-portal">
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>

        <main class="dashboard-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div>
                    <h1>Give Attendance</h1>
                    <p class="muted">Join an in-progress session and check in</p>
                </div>
                <div class="toolbar">
                    <span class="pill">Today: <?php echo date('M d, Y'); ?></span>
                </div>
            </div>

            <?php if (isset($_GET['success']) && $_GET['success'] === 'attendance_marked'): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    ✅ Attendance marked successfully!
                </div>
            <?php endif; ?>
            
            <div class="form-section">
                <h2>Available Sessions</h2>
                <?php if (empty($sessions)): ?>
                    <p class="muted">No sessions available right now.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Class</th>
                                <th>Session</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $s): ?>
                                <tr>
                                    <td><?php echo date('H:i', strtotime($s['start_time'])) . ' - ' . date('H:i', strtotime($s['end_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($s['class_code'] . ' - ' . $s['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['session_title']); ?></td>
                                    <td><span class="chip <?php echo $s['status']; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                                    <td>
                                        <button class="btn" 
                                                onclick="openCheckIn('<?php echo $s['instance_id']; ?>')"
                                                data-session="<?php echo $s['instance_id']; ?>">
                                            <?php 
                                                $stmt = $conn->prepare('SELECT 1 FROM attendance_records WHERE student_id = ? AND session_instance_id = ?');
                                                $stmt->execute([$student_id, $s['instance_id']]);
                                                echo $stmt->fetch() ? 'Attended' : 'Check In';
                                            ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <script>
        const currentStudentId = <?php echo json_encode($student_id); ?>;
        const markedSessions = new Set(<?php 
            $marked = [];
            foreach ($sessions as $s) {
                $stmt = $conn->prepare('SELECT 1 FROM attendance_records WHERE student_id = ? AND session_instance_id = ?');
                $stmt->execute([$student_id, $s['instance_id']]);
                if ($stmt->fetch()) {
                    $marked[] = $s['instance_id'];
                }
            }
            echo json_encode($marked);
        ?>);
        
        // Update button states on page load
        document.addEventListener('DOMContentLoaded', function() {
            markedSessions.forEach(sessionId => {
                const btn = document.querySelector(`button[data-session="${sessionId}"]`);
                if (btn) {
                    btn.textContent = 'Attended';
                    btn.disabled = true;
                    btn.classList.add('attended');
                }
            });
        });
        
        async function openCheckIn(instanceId) {
            // Don't allow checking in again if already attended
            if (markedSessions.has(instanceId)) {
                return;
            }
            // Open in a popup window
            const popup = window.open(
                '../ss/web_camera_checkin.php?session_id=' + encodeURIComponent(instanceId),
                'checkinPopup',
                'width=800,height=800,menubar=no,toolbar=no,location=no,status=no,scrollbars=yes,resizable=yes'
            );
            
            // Focus the popup
            if (window.focus) {
                popup.focus();
            }
        }
        
        // Listen for messages from popup
        window.addEventListener('message', function(event) {
            if (event.origin !== window.location.origin) return;
            
            if (event.data && event.data.type === 'attendanceMarked') {
                updateAttendanceButton(event.data.sessionId);
            }
        });
        
        // Function to update button after successful attendance
        function updateAttendanceButton(instanceId) {
            const btn = document.querySelector(`button[data-session="${instanceId}"]`);
            if (btn) {
                btn.textContent = 'Attended';
                btn.disabled = true;
                btn.classList.add('attended');
                markedSessions.add(instanceId);
            }
        }
    </script>
    <style>
        button.attended {
            background-color: #28a745 !important;
            cursor: not-allowed;
        }
    </style>
</body>
</html>
