<?php
// student/student_dashboard.php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../ensure_enrollment_requests.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$student_id = $_SESSION['user_id'];

// Ensure requests table exists
ensure_enrollment_requests_table($conn);

// Handle new enrollment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_enroll'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $reason = sanitize_input($_POST['reason'] ?? '');
    if ($class_id > 0) {
        try {
            // avoid duplicates: if already enrolled or pending/approved exists
            $chk = $conn->prepare("SELECT 1 FROM student_enrollments WHERE student_id = :sid AND class_id = :cid AND status = 'enrolled'");
            $chk->execute(['sid' => $student_id, 'cid' => $class_id]);
            if ($chk->fetch()) {
                $error = 'You are already enrolled in this class';
            } else {
                $ins = $conn->prepare("INSERT INTO enrollment_requests (student_id, class_id, reason, status) VALUES (:sid, :cid, :reason, 'pending')");
                $ins->execute(['sid' => $student_id, 'cid' => $class_id, 'reason' => $reason]);
            }
        } catch (PDOException $e) {
            $error = 'Could not submit request: ' . $e->getMessage();
        }
    }
}

try {
    // Get student info
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :student_id AND user_type = 'student'");
    $stmt->execute(['student_id' => $student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        header('Location: ../login.php');
        exit();
    }
    
    // Get attendance summary
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT ar.id) as total_sessions,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND(
                CASE 
                    WHEN COUNT(DISTINCT ar.id) > 0 
                    THEN (SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT ar.id)) * 100 
                    ELSE 0 
                END, 1
            ) as attendance_percentage
        FROM attendance_records ar
        JOIN session_instances si ON ar.session_instance_id = si.id
        WHERE ar.student_id = :student_id
    ");
    $stmt->execute(['student_id' => $student_id]);
    $attendance_summary = $stmt->fetch();
    
    // Get enrolled classes with attendance
    $stmt = $conn->prepare("
        SELECT 
            c.id,
            c.class_code,
            c.class_name,
            COUNT(DISTINCT ar.id) as total_sessions,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND(
                CASE 
                    WHEN COUNT(DISTINCT ar.id) > 0 
                    THEN (SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT ar.id)) * 100 
                    ELSE 0 
                END, 1
            ) as attendance_percentage
        FROM classes c
        JOIN student_enrollments se ON c.id = se.class_id
        LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
        LEFT JOIN session_instances si ON ts.id = si.timetable_session_id AND si.status = 'completed'
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id AND ar.student_id = :student_id_ar
        WHERE se.student_id = :student_id_se AND se.status = 'enrolled' AND c.status = 'active'
        GROUP BY c.id, c.class_code, c.class_name
        ORDER BY c.class_code
    ");
    $stmt->execute(['student_id_ar' => $student_id, 'student_id_se' => $student_id]);
    $enrolled_classes = $stmt->fetchAll();
    
    // Get today's schedule
    $stmt = $conn->prepare("
        SELECT 
            ts.session_title,
            ts.start_time,
            ts.end_time,
            ts.room_location,
            ts.session_type,
            c.class_code,
            c.class_name,
            si.status as session_status,
            ar.status as attendance_status,
            ar.check_in_time
        FROM timetable_sessions ts
        JOIN classes c ON ts.class_id = c.id
        JOIN student_enrollments se ON c.id = se.class_id
        LEFT JOIN session_instances si ON ts.id = si.timetable_session_id AND si.session_date = CURDATE()
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id AND ar.student_id = :student_id_ar
        WHERE se.student_id = :student_id_se 
        AND se.status = 'enrolled' 
        AND ts.day_of_week = DAYNAME(CURDATE())
        AND c.status = 'active'
        ORDER BY ts.start_time
    ");
    $stmt->execute(['student_id_ar' => $student_id, 'student_id_se' => $student_id]);
    $todays_schedule = $stmt->fetchAll();
    
    // Get recent attendance (last 5 sessions)
    
    // Get available classes to request
    $stmt = $conn->prepare("SELECT id, class_code, class_name FROM classes WHERE status = 'active' AND id NOT IN (SELECT class_id FROM student_enrollments WHERE student_id = :sid AND status = 'enrolled') ORDER BY class_code");
    $stmt->execute(['sid' => $student_id]);
    $available_classes = $stmt->fetchAll();
    
    // Get my pending requests
    $stmt = $conn->prepare("SELECT er.id, c.class_code, c.class_name, er.status, er.created_at FROM enrollment_requests er JOIN classes c ON er.class_id = c.id WHERE er.student_id = :sid ORDER BY er.created_at DESC");
    $stmt->execute(['sid' => $student_id]);
    $my_requests = $stmt->fetchAll();
    $stmt = $conn->prepare("
        SELECT 
            si.session_date,
            ts.session_title,
            c.class_code,
            ar.status,
            ar.check_in_time,
            ar.notes
        FROM attendance_records ar
        JOIN session_instances si ON ar.session_instance_id = si.id
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        WHERE ar.student_id = :student_id
        ORDER BY si.session_date DESC, ts.start_time DESC
        LIMIT 5
    ");
    $stmt->execute(['student_id' => $student_id]);
    $recent_attendance = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

function getAttendanceColor($percentage) {
    if ($percentage >= 80) return 'present';
    if ($percentage >= 60) return 'late';
    return 'absent';
}

function getStatusChip($status) {
    $classes = [
        'present' => 'present',
        'absent' => 'absent',
        'late' => 'late',
        null => 'absent'
    ];
    return $classes[$status] ?? 'absent';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Full Attend</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../student.css?v=<?php echo time(); ?>">
</head>
<body class="student-portal">
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="dashboard-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1>Welcome, <?php echo htmlspecialchars($student['first_name']); ?></h1>
                    <p class="muted">Here's your attendance overview and today's schedule</p>
                </div>
                <div class="toolbar">
                    <span class="pill"><?php echo date('l, M d, Y'); ?></span>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Attendance Summary Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <h4>Overall Attendance</h4>
                    <div class="kpi-value"><?php echo $attendance_summary['attendance_percentage'] ?? 0; ?>%</div>
                </div>
                <div class="kpi-card">
                    <h4>Present</h4>
                    <div class="kpi-value"><?php echo $attendance_summary['present_count'] ?? 0; ?></div>
                </div>
                <div class="kpi-card">
                    <h4>Absent</h4>
                    <div class="kpi-value"><?php echo $attendance_summary['absent_count'] ?? 0; ?></div>
                </div>
                <div class="kpi-card">
                    <h4>Late</h4>
                    <div class="kpi-value"><?php echo $attendance_summary['late_count'] ?? 0; ?></div>
                </div>
            </div>
            
            <!-- Today's Schedule -->
            <section style="margin-top: 30px;">
                <h2>Today's Schedule</h2>
                <?php if (empty($todays_schedule)): ?>
                    <div class="scanning-status">
                        <p style="text-align: center; color: #6b7280; padding: 20px;">No classes scheduled for today</p>
                    </div>
                <?php else: ?>
                <div class="scanning-status">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Class</th>
                                <th>Session</th>
                                <th>Room</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todays_schedule as $session): ?>
                            <tr>
                                <td><?php echo date('H:i', strtotime($session['start_time'])) . '-' . date('H:i', strtotime($session['end_time'])); ?></td>
                                <td><?php echo htmlspecialchars($session['class_code']); ?></td>
                                <td><?php echo htmlspecialchars($session['session_title']); ?></td>
                                <td><?php echo htmlspecialchars($session['room_location'] ?? 'TBA'); ?></td>
                                <td><?php echo htmlspecialchars($session['session_type']); ?></td>
                                <td>
                                    <?php if ($session['attendance_status']): ?>
                                        <span class="chip <?php echo getStatusChip($session['attendance_status']); ?>">
                                            <?php echo ucfirst($session['attendance_status']); ?>
                                            <?php if ($session['check_in_time']): ?>
                                                <br><small><?php echo date('H:i', strtotime($session['check_in_time'])); ?></small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="chip" style="background: #e5e7eb; color: #6b7280;">
                                            <?php echo $session['session_status'] === 'completed' ? 'Not Marked' : 'Scheduled'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>
            
            <!-- Enrolled Classes -->
            <section style="margin-top: 30px;">
                <h2>My Classes</h2>
                <div class="class-cards">
                    <?php if (empty($enrolled_classes)): ?>
                        <p style="color: #6b7280;">No classes enrolled</p>
                    <?php else: ?>
                    <?php foreach ($enrolled_classes as $class): ?>
                    <div class="card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <h3><?php echo htmlspecialchars($class['class_code']); ?></h3>
                            <span class="status-dot <?php echo getAttendanceColor($class['attendance_percentage']); ?>"></span>
                        </div>
                        <p style="font-weight: 600; margin-bottom: 5px;"><?php echo htmlspecialchars($class['class_name']); ?></p>
                        <p class="muted" style="margin-bottom: 10px;">
                            <?php echo $class['present_count']; ?>/<?php echo $class['total_sessions']; ?> sessions attended
                        </p>
                        <div style="font-size: 24px; font-weight: 700; color: #4f46e5;">
                            <?php echo $class['attendance_percentage']; ?>%
                        </div>
                        <p class="muted">Attendance Rate</p>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
            
            <!-- Recent Attendance -->
            <section style="margin-top: 30px;">
                <h2>Recent Attendance</h2>
                <div class="scanning-status">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Class</th>
                                <th>Session</th>
                                <th>Status</th>
                                <th>Check-in</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_attendance)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #6b7280; padding: 20px;">
                                    No attendance records found
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recent_attendance as $record): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($record['session_date'])); ?></td>
                                <td><?php echo htmlspecialchars($record['class_code']); ?></td>
                                <td><?php echo htmlspecialchars($record['session_title']); ?></td>
                                <td>
                                    <span class="chip <?php echo getStatusChip($record['status']); ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $record['check_in_time'] ? date('H:i A', strtotime($record['check_in_time'])) : '—'; ?>
                                </td>
                                <td><?php echo htmlspecialchars($record['notes'] ?: '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="my_attendance.php" class="btn">View Full Attendance History</a>
                </div>
            </section>

            <!-- Enrollment Requests -->
            <section style="margin-top: 30px;">
                <h2>Request Enrollment</h2>
                <div class="form-section">
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="request_enroll" value="1" />
                        <div class="form-group">
                            <label for="class_id">Class</label>
                            <select id="class_id" name="class_id" required>
                                <option value="">Select class</option>
                                <?php foreach ($available_classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_code'] . ' - ' . $cls['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reason">Reason (optional)</label>
                            <input id="reason" name="reason" placeholder="Why are you requesting this class?" />
                        </div>
                        <div class="form-group" style="grid-column:1/-1; text-align:right;">
                            <button type="submit" class="btn">Submit Request</button>
                        </div>
                    </form>
                </div>

                <h3 style="margin-top: 20px;">My Requests</h3>
                <div class="scanning-status">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Requested</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($my_requests)): ?>
                            <tr>
                                <td colspan="3" style="text-align:center; color:#6b7280; padding:12px;">No requests yet</td>
                            </tr>
                            <?php else: foreach ($my_requests as $req): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($req['class_code'] . ' - ' . $req['class_name']); ?></td>
                                <td><span class="chip <?php echo $req['status'] === 'approved' ? 'present' : ($req['status'] === 'rejected' ? 'absent' : 'late'); ?>"><?php echo ucfirst($req['status']); ?></span></td>
                                <td><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>