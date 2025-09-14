<?php
// session_attendance.php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lecturer') {
    header('Location: ../login.php');
    exit();
}

if (!isset($_GET['session_id'])) {
    header('Location: sessions.php');
    exit();
}

$session_id = (int)$_GET['session_id'];
$db = new Database();
$conn = $db->getConnection();

// Verify lecturer has access to this session
try {
    // Get session details
    $stmt = $conn->prepare("
        SELECT 
            si.id, 
            si.session_date,
            si.status,
            ts.session_title,
            ts.start_time,
            ts.end_time,
            c.id as class_id,
            c.class_code,
            c.class_name
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN class_lecturers cl ON c.id = cl.class_id
        WHERE si.id = :session_id 
        AND cl.lecturer_id = :lecturer_id
        AND si.status = 'in_progress'
    ");
    
    $stmt->execute([
        'session_id' => $session_id,
        'lecturer_id' => $_SESSION['user_id']
    ]);
    
    $session = $stmt->fetch();
    
    if (!$session) {
        throw new Exception("Session not found, already ended, or you don't have permission to access it.");
    }
    
    // Get enrolled students
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.user_id,
            u.first_name,
            u.last_name,
            u.profile_image,
            ar.status as attendance_status,
            ar.check_in_time
        FROM student_enrollments se
        JOIN users u ON se.student_id = u.id
        LEFT JOIN attendance_records ar ON ar.student_id = u.id AND ar.session_instance_id = :session_id
        WHERE se.class_id = :class_id
        AND se.status = 'enrolled'
        ORDER BY u.last_name, u.first_name
    ");
    
    $stmt->execute([
        'session_id' => $session_id,
        'class_id' => $session['class_id']
    ]);
    
    $students = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die($e->getMessage());
}

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Delete existing attendance records for this session
        $stmt = $conn->prepare("DELETE FROM attendance_records WHERE session_instance_id = :session_id");
        $stmt->execute(['session_id' => $session_id]);
        
        // Insert new attendance records
        if (!empty($_POST['attendance'])) {
            $stmt = $conn->prepare("
                INSERT INTO attendance_records 
                (session_instance_id, student_id, status, check_in_time, created_at)
                VALUES (:session_id, :student_id, 'present', NOW(), NOW())
            ");
            
            foreach ($_POST['attendance'] as $student_id) {
                $stmt->execute([
                    'session_id' => $session_id,
                    'student_id' => (int)$student_id
                ]);
            }
        }
        
        $conn->commit();
        $success = "Attendance saved successfully!";
        
        // Refresh student data
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.user_id,
                u.first_name,
                u.last_name,
                u.profile_image,
                ar.status as attendance_status,
                ar.check_in_time
            FROM student_enrollments se
            JOIN users u ON se.student_id = u.id
            LEFT JOIN attendance_records ar ON ar.student_id = u.id AND ar.session_instance_id = :session_id
            WHERE se.class_id = :class_id
            AND se.status = 'enrolled'
            ORDER BY u.last_name, u.first_name
        ");
        
        $stmt->execute([
            'session_id' => $session_id,
            'class_id' => $session['class_id']
        ]);
        
        $students = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Error saving attendance: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Attendance - Full Attend</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .attendance-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .attendance-header h1 {
            margin: 0 0 5px 0;
        }
        .session-info {
            color: #6b7280;
            margin-bottom: 15px;
        }
        .student-list {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .student-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #f3f4f6;
        }
        .student-item:last-child {
            border-bottom: none;
        }
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            overflow: hidden;
        }
        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .student-info {
            flex: 1;
        }
        .student-name {
            font-weight: 500;
            margin-bottom: 2px;
        }
        .student-id {
            color: #6b7280;
            font-size: 0.9em;
        }
        .attendance-toggle {
            position: relative;
            width: 50px;
            height: 26px;
        }
        .attendance-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e5e7eb;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #10b981;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        .attendance-actions {
            margin-top: 25px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .attendance-stats {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }
        .stat-item {
            text-align: center;
        }
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: #4f46e5;
        }
        .stat-label {
            font-size: 0.9em;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="lecturer_dashboard.php">Dashboard</a>
                <a href="sessions.php">My Sessions</a>
                <a href="attendance_report.php" class="active">Attendance</a>
                <a href="reports.php">Reports</a>
                <a href="../logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="../images/fullattend_logo.png" alt="FullAttend Logo">
            </div>
        </aside>

        <!-- Main Content -->
        <main class="dashboard-content">
            <div class="attendance-header">
                <h1>Take Attendance</h1>
                <div class="session-info">
                    <strong><?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></strong> • 
                    <?php echo date('l, F j, Y', strtotime($session['session_date'])); ?> • 
                    <?php echo date('h:i A', strtotime($session['start_time'])) . ' - ' . date('h:i A', strtotime($session['end_time'])); ?>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" id="attendanceForm">
                <div class="student-list">
                    <?php 
                    $present_count = 0;
                    $total_students = count($students);
                    
                    foreach ($students as $student): 
                        $is_present = $student['attendance_status'] === 'present';
                        if ($is_present) $present_count++;
                    ?>
                        <div class="student-item">
                            <div class="student-avatar">
                                <?php if ($student['profile_image'] && file_exists('../uploads/profiles/' . $student['profile_image'])): ?>
                                    <img src="../uploads/profiles/<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Profile">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="student-info">
                                <div class="student-name">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                </div>
                                <div class="student-id">
                                    <?php echo htmlspecialchars($student['user_id']); ?>
                                    <?php if ($is_present && $student['check_in_time']): ?>
                                        • Checked in at <?php echo date('h:i A', strtotime($student['check_in_time'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <label class="attendance-toggle">
                                <input type="checkbox" name="attendance[]" value="<?php echo $student['id']; ?>" 
                                    <?php echo $is_present ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="attendance-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $present_count; ?></div>
                        <div class="stat-label">Present</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $total_students - $present_count; ?></div>
                        <div class="stat-label">Absent</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $total_students > 0 ? round(($present_count / $total_students) * 100) : 0; ?>%</div>
                        <div class="stat-label">Attendance</div>
                    </div>
                </div>

                <div class="attendance-actions">
                    <a href="sessions.php" class="btn btn-outline">Back to Sessions</a>
                    <button type="submit" class="btn">Save Attendance</button>
                </div>
            </form>
        </main>
    </div>
    
    <script>
        // Auto-submit form when any toggle changes
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('attendanceForm');
            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            
            // Update stats when any checkbox changes
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateStats);
            });
            
            function updateStats() {
                const presentCount = form.querySelectorAll('input[type="checkbox"]:checked').length;
                const totalStudents = checkboxes.length;
                const absentCount = totalStudents - presentCount;
                const attendancePercent = totalStudents > 0 ? Math.round((presentCount / totalStudents) * 100) : 0;
                
                // Update the stats display
                const stats = form.querySelectorAll('.stat-value');
                if (stats.length >= 3) {
                    stats[0].textContent = presentCount;
                    stats[1].textContent = absentCount;
                    stats[2].textContent = attendancePercent + '%';
                }
            }
        });
    </script>
</body>
</html>
