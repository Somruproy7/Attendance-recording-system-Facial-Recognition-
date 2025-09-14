<?php
// class_management.php
session_start();
require_once 'config/database.php';
require_once __DIR__ . '/ensure_today_sessions.php';
require_once __DIR__ . '/ensure_enrollment_requests.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();
ensure_enrollment_requests_table($conn);

// Get selected session or default to current one
$session_id = sanitize_input($_GET['session_id'] ?? '');
$selected_class = sanitize_input($_GET['class'] ?? '');

// Handle add class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    $class_code = sanitize_input($_POST['class_code'] ?? '');
    $class_name = sanitize_input($_POST['class_name'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $instructor_id = sanitize_input($_POST['instructor_id'] ?? '');
    $semester = sanitize_input($_POST['semester'] ?? '2025-S1');
    $year = (int)($_POST['year'] ?? date('Y'));

    if ($class_code && $class_name) {
        try {
            $stmt = $conn->prepare("INSERT INTO classes (class_code, class_name, description, instructor_id, semester, year, status) VALUES (:class_code, :class_name, :description, :instructor_id, :semester, :year, 'active')");
            $stmt->execute([
                'class_code' => $class_code,
                'class_name' => $class_name,
                'description' => $description,
                'instructor_id' => $instructor_id ?: null,
                'semester' => $semester,
                'year' => $year
            ]);
            $success_message = 'Class added successfully';
            header('Location: class_management.php');
            exit();
        } catch (PDOException $e) {
            $error = 'Failed to add class: ' . $e->getMessage();
        }
    } else {
        $error = 'Class code and class name are required';
    }
}

// Handle create new timetable session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    $class_id = sanitize_input($_POST['class_id'] ?? '');
    $session_title = sanitize_input($_POST['session_title'] ?? '');
    $day_of_week = sanitize_input($_POST['day_of_week'] ?? '');
    $start_time = sanitize_input($_POST['start_time'] ?? '');
    $end_time = sanitize_input($_POST['end_time'] ?? '');
    $room_location = sanitize_input($_POST['room_location'] ?? '');
    $session_type = sanitize_input($_POST['session_type'] ?? 'Lecture');
    $instructor = sanitize_input($_POST['instructor'] ?? '');
    $session_date = sanitize_input($_POST['session_date'] ?? ''); // optional single date instance

    try {
        // Insert into timetable_sessions
        $stmt = $conn->prepare("INSERT INTO timetable_sessions 
            (class_id, session_title, day_of_week, start_time, end_time, room_location, session_type, instructor)
            VALUES (:class_id, :session_title, :day_of_week, :start_time, :end_time, :room_location, :session_type, :instructor)");
        $stmt->execute([
            'class_id' => $class_id,
            'session_title' => $session_title,
            'day_of_week' => $day_of_week,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'room_location' => $room_location,
            'session_type' => $session_type,
            'instructor' => $instructor
        ]);
        $new_ts_id = (int)$conn->lastInsertId();

        // Optionally create a concrete session instance for a provided date
        if (!empty($session_date)) {
            $stmt = $conn->prepare("INSERT INTO session_instances (timetable_session_id, session_date, status) VALUES (:ts_id, :session_date, 'scheduled')");
            $stmt->execute(['ts_id' => $new_ts_id, 'session_date' => $session_date]);
        }

        $success_message = 'Session created successfully';
        header('Location: class_management.php');
        exit();
    } catch (PDOException $e) {
        $error = 'Failed to create session: ' . $e->getMessage();
    }
}

try {
    // Ensure today's session instances are present
    ensure_todays_session_instances($conn);

    // Get today's sessions
    $stmt = $conn->prepare("
        SELECT 
            si.id,
            c.id as class_id,
            c.class_code,
            c.class_name,
            ts.session_title,
            ts.start_time,
            ts.end_time,
            ts.room_location,
            si.status,
            si.actual_start_time
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        WHERE si.session_date = CURDATE()
        ORDER BY ts.start_time
    ");
    $stmt->execute();
    $sessions = $stmt->fetchAll();
    // Fetch active classes with analytics
    $stmt = $conn->prepare("SELECT 
            c.id,
            c.class_code,
            c.class_name,
            COUNT(DISTINCT se.student_id) AS enrolled_count,
            ROUND(
                CASE WHEN COUNT(ar.id) > 0 
                     THEN (SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(ar.id)) * 100 
                     ELSE 0 END, 1
            ) AS avg_attendance,
            MAX(si.session_date) AS last_session_date
        FROM classes c
        LEFT JOIN student_enrollments se ON se.class_id = c.id AND se.status = 'enrolled'
        LEFT JOIN timetable_sessions ts ON ts.class_id = c.id
        LEFT JOIN session_instances si ON si.timetable_session_id = ts.id AND si.status = 'completed'
        LEFT JOIN attendance_records ar ON ar.session_instance_id = si.id
        WHERE c.status = 'active'
        GROUP BY c.id, c.class_code, c.class_name
        ORDER BY c.class_code");
    $stmt->execute();
    $active_classes = $stmt->fetchAll();

    // If no session_id provided, use the first available session
    if (!$session_id && !empty($sessions)) {
        $session_id = $sessions[0]['id'];
    }
    
    if ($session_id) {
        // Get session details
        $stmt = $conn->prepare("
            SELECT 
                si.id,
                c.id as class_id,
                c.class_code,
                c.class_name,
                ts.session_title,
                ts.start_time,
                ts.end_time,
                ts.room_location,
                si.status,
                si.session_date,
                si.actual_start_time
            FROM session_instances si
            JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
            JOIN classes c ON ts.class_id = c.id
            WHERE si.id = :session_id
        ");
        $stmt->execute(['session_id' => $session_id]);
        $current_session = $stmt->fetch();
        
        if ($current_session) {
            // Get students enrolled in this class with their attendance
            $stmt = $conn->prepare("
                SELECT 
                    u.id,
                    u.user_id,
                    u.first_name,
                    u.last_name,
                    u.profile_image,
                    ar.status,
                    ar.check_in_time,
                    ar.notes,
                    ar.recognition_confidence
                FROM users u
                JOIN student_enrollments se ON u.id = se.student_id
                LEFT JOIN attendance_records ar ON u.id = ar.student_id AND ar.session_instance_id = :session_id
                WHERE se.class_id = :class_id AND se.status = 'enrolled' AND u.status = 'active'
                ORDER BY u.last_name, u.first_name
            ");
            $stmt->execute([
                'session_id' => $session_id,
                'class_id' => $current_session['class_id']
            ]);
            $students = $stmt->fetchAll();
            
            // Calculate statistics
            $total_students = count($students);
            $present_count = count(array_filter($students, fn($s) => $s['status'] === 'present'));
            $absent_count = count(array_filter($students, fn($s) => $s['status'] === 'absent'));
            $late_count = count(array_filter($students, fn($s) => $s['status'] === 'late'));
            $not_marked = count(array_filter($students, fn($s) => $s['status'] === null));
        }
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle manual attendance update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    $student_id = sanitize_input($_POST['student_id']);
    $new_status = sanitize_input($_POST['status']);
    $notes = sanitize_input($_POST['notes'] ?? '');
    
    try {
        // Check if record exists
        $stmt = $conn->prepare("
            SELECT id FROM attendance_records 
            WHERE session_instance_id = :session_id AND student_id = :student_id
        ");
        $stmt->execute(['session_id' => $session_id, 'student_id' => $student_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing record
            $stmt = $conn->prepare("
                UPDATE attendance_records 
                SET status = :status, notes = :notes, marked_by = 'admin', updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $new_status,
                'notes' => $notes,
                'id' => $existing['id']
            ]);
        } else {
            // Insert new record
            $check_in_time = ($new_status !== 'absent') ? date('Y-m-d H:i:s') : null;
            $stmt = $conn->prepare("
                INSERT INTO attendance_records 
                (session_instance_id, student_id, status, check_in_time, notes, marked_by)
                VALUES (:session_id, :student_id, :status, :check_in_time, :notes, 'admin')
            ");
            $stmt->execute([
                'session_id' => $session_id,
                'student_id' => $student_id,
                'status' => $new_status,
                'check_in_time' => $check_in_time,
                'notes' => $notes
            ]);
        }
        
        $success_message = "Attendance updated successfully!";
        // Refresh the page to show updates
        header("Location: class_management.php?session_id=$session_id");
        exit();
        
    } catch (PDOException $e) {
        $error = "Error updating attendance: " . $e->getMessage();
    }
}

// Handle enrollment request actions (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_request'])) {
    $req_id = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action_type'] ?? '';
    if ($req_id && in_array($action, ['approve','reject'], true)) {
        try {
            if ($action === 'approve') {
                // Approve: set request approved, enroll student if not already
                $stmt = $conn->prepare("SELECT student_id, class_id FROM enrollment_requests WHERE id = :id AND status = 'pending'");
                $stmt->execute(['id' => $req_id]);
                $req = $stmt->fetch();
                if ($req) {
                    $conn->beginTransaction();
                    // update request
                    $upd = $conn->prepare("UPDATE enrollment_requests SET status = 'approved', processed_at = NOW(), processed_by = :admin WHERE id = :id");
                    $upd->execute(['admin' => $_SESSION['user_id'], 'id' => $req_id]);
                    // enroll student (ignore duplicate via unique key)
                    $ins = $conn->prepare("INSERT INTO student_enrollments (student_id, class_id, status) VALUES (:sid, :cid, 'enrolled') ON DUPLICATE KEY UPDATE status = 'enrolled'");
                    $ins->execute(['sid' => $req['student_id'], 'cid' => $req['class_id']]);
                    $conn->commit();
                    $success_message = 'Enrollment approved and student enrolled';
                }
            } else {
                $upd = $conn->prepare("UPDATE enrollment_requests SET status = 'rejected', processed_at = NOW(), processed_by = :admin WHERE id = :id AND status = 'pending'");
                $upd->execute(['admin' => $_SESSION['user_id'], 'id' => $req_id]);
                $success_message = 'Enrollment request rejected';
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = 'Failed to process request: ' . $e->getMessage();
        }
    }
}

function getInitials($firstName, $lastName) {
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}

function getStatusClass($status) {
    switch ($status) {
        case 'present': return 'present';
        case 'absent': return 'absent';
        case 'late': return 'late';
        default: return 'absent';
    }
}

function getStatusLabel($status) {
    return $status ? ucfirst($status) : 'Not Marked';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Class Management - Full Attend</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>"/>
    <link rel="stylesheet" href="student.css?v=<?php echo time(); ?>"/>
</head>
<body class="admin-portal">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="class_management.php" class="active">Class Management</a>
                <a href="student_directory.php">Student Directory</a>
                <a href="student_registration.php">Student Management</a>
                <a href="admin/lecturer_management.php">Lecturer Management</a>
                <a href="timetable_management.php">Timetable</a>
                <a href="reports.php">Reports</a>
                <a href="settings.php">Settings</a>
                <a href="logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="images/fullattend_logo.png" alt="FullAttend Logo"/>
            </div>
        </aside>

        <!-- Main -->
        <main class="main-content">
            <?php if (isset($error)): ?>
                <div style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success_message)): ?>
                <div style="background: #efe; color: #383; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <!-- Header row -->
            <div class="class-header">
                <div>
                    <h3>Class Management</h3>
                    <?php if (isset($current_session)): ?>
                    <h2><?php echo htmlspecialchars($current_session['class_name']); ?><br>
                        <small><?php echo getStatusLabel($current_session['status']); ?> Session</small>
                    </h2>
                    <?php else: ?>
                    <h2>No Session Selected</h2>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if (isset($current_session)): ?>
                    <span class="status-label"><?php echo ucfirst($current_session['status']); ?></span>
                    <div class="muted" style="margin-top:8px;">
                        <?php echo date('M d, Y', strtotime($current_session['session_date'])) . ' • ' . 
                                   date('H:i', strtotime($current_session['start_time'])) . ' - ' . 
                                   date('H:i', strtotime($current_session['end_time'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="session-select" class="muted">Select Session</label><br>
                    <select id="session-select" class="class-selector" onchange="changeSession(this.value)">
                        <option value="">Select a session...</option>
                        <?php foreach ($sessions as $session): ?>
                        <option value="<?php echo $session['id']; ?>" 
                                <?php echo $session['id'] == $session_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['session_title'] . 
                                   ' (' . date('H:i', strtotime($session['start_time'])) . '-' . 
                                   date('H:i', strtotime($session['end_time'])) . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Classes + Create Session -->
            <div class="student-list" style="margin-bottom:20px;">
                <!-- Left: Active classes -->
                <div class="students">
                    <div style="padding:16px; border-bottom:1px solid #f3f4f6; font-weight:700;">Active Classes</div>
                    <?php if (!empty($active_classes)): ?>
                        <?php foreach ($active_classes as $cls): ?>
                        <div class="student" onclick="window.location.href='timetable_management.php?class_id=<?php echo $cls['id']; ?>'">
                            <div class="student-name">
                                <span class="avatar"><?php echo htmlspecialchars(substr($cls['class_code'],0,2)); ?></span>
                                <div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($cls['class_code'].' - '.$cls['class_name']); ?></div>
                                    <div class="muted" style="font-size:12px;">Enrolled: <?php echo (int)$cls['enrolled_count']; ?> · Avg: <?php echo $cls['avg_attendance']; ?>% · Last: <?php echo $cls['last_session_date'] ?: '—'; ?></div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="pill">Open</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:16px; color:#6b7280;">No active classes</div>
                    <?php endif; ?>
                </div>

                <!-- Right: Add Class + Create new timetable session -->
                <div class="students" style="padding:16px;">
                    <h3 style="margin-bottom:12px;">Add New Class</h3>
                    <form method="POST" class="form-grid" style="margin-bottom:20px;">
                        <input type="hidden" name="add_class" value="1" />
                        <div class="form-group">
                            <label>Class Code</label>
                            <input name="class_code" required />
                        </div>
                        <div class="form-group">
                            <label>Class Name</label>
                            <input name="class_name" required />
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input name="description" />
                        </div>
                        <div class="form-group">
                            <label>Instructor (User ID)</label>
                            <input name="instructor_id" type="number" />
                        </div>
                        <div class="form-group">
                            <label>Semester</label>
                            <input name="semester" value="2025-S1" />
                        </div>
                        <div class="form-group">
                            <label>Year</label>
                            <input name="year" type="number" value="<?php echo date('Y'); ?>" />
                        </div>
                        <div style="grid-column:1/-1; text-align:right;">
                            <button class="btn" type="submit">Add Class</button>
                        </div>
                    </form>
                    <h3 style="margin-bottom:12px;">Add New Class Session</h3>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="create_session" value="1" />
                        <div class="form-group">
                            <label>Class</label>
                            <select name="class_id" required class="class-selector" style="width:100%">
                                <option value="">Select class</option>
                                <?php foreach ($active_classes as $cls): ?>
                                    <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_code'].' - '.$cls['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Session Title</label>
                            <input name="session_title" required />
                        </div>
                        <div class="form-group">
                            <label>Day of Week</label>
                            <select name="day_of_week" required class="class-selector" style="width:100%">
                                <option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" required />
                        </div>
                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" name="end_time" required />
                        </div>
                        <div class="form-group">
                            <label>Room</label>
                            <input name="room_location" />
                        </div>
                        <div class="form-group">
                            <label>Type</label>
                            <select name="session_type" class="class-selector" style="width:100%">
                                <option>Lecture</option><option>Lab</option><option>Tutorial</option><option>Seminar</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Instructor</label>
                            <input name="instructor" />
                        </div>
                        <div class="form-group">
                            <label>Optional: Create Instance for Date</label>
                            <input type="date" name="session_date" />
                        </div>
                        <div style="grid-column:1/-1; text-align:right;">
                            <button class="btn" type="submit">Create Session</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($current_session) && isset($students)): ?>
            <!-- Statistics Cards -->
            <div class="class-cards" style="margin: 20px 0;">
                <div class="card">
                    <h3>Total Students</h3>
                    <h2><?php echo $total_students; ?></h2>
                </div>
                <div class="card">
                    <h3>Present</h3>
                    <h2 style="color: #10b981;"><?php echo $present_count; ?></h2>
                </div>
                <div class="card">
                    <h3>Absent</h3>
                    <h2 style="color: #ef4444;"><?php echo $absent_count; ?></h2>
                </div>
                <div class="card">
                    <h3>Late</h3>
                    <h2 style="color: #f59e0b;"><?php echo $late_count; ?></h2>
                </div>
                <div class="card">
                    <h3>Not Marked</h3>
                    <h2 style="color: #6b7280;"><?php echo $not_marked; ?></h2>
                </div>
            </div>

            <!-- Two-column layout: student list + classroom image -->
            <div class="student-list">
                <!-- Left: students with status -->
                <div class="students">
                    <?php foreach ($students as $student): ?>
                    <div class="student" onclick="openAttendanceModal('<?php echo $student['id']; ?>', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>', '<?php echo $student['status']; ?>')">
                        <div class="student-name">
                            <?php if ($student['profile_image'] && file_exists('uploads/profiles/' . $student['profile_image'])): ?>
                                <img src="uploads/profiles/<?php echo htmlspecialchars($student['profile_image']); ?>" 
                                     class="avatar" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <span class="avatar"><?php echo getInitials($student['first_name'], $student['last_name']); ?></span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                            <br><small class="muted"><?php echo htmlspecialchars($student['user_id']); ?></small>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="status-dot <?php echo getStatusClass($student['status']); ?>"></span>
                            <?php if ($student['check_in_time']): ?>
                                <small class="muted"><?php echo date('H:i', strtotime($student['check_in_time'])); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Right: classroom image -->
                <div class="class-image">
                    <img src="images/classroom.jpg" alt="Classroom" style="width: 100%; height: auto; border-radius: 10px;"/>
                    <div style="margin-top: 15px; text-align: center;">
                        <h4><?php echo htmlspecialchars($current_session['session_title']); ?></h4>
                        <p class="muted"><?php echo htmlspecialchars($current_session['room_location'] ?? 'Room TBA'); ?></p>
                        <div style="margin-top: 10px;">
                            <?php if ($current_session['status'] === 'scheduled'): ?>
                                <button class="btn" onclick="startSession()" style="background: #10b981;">Start Session</button>
                            <?php elseif ($current_session['status'] === 'in_progress'): ?>
                                <button class="btn" onclick="endSession()" style="background: #ef4444;">End Session</button>
                                <p class="muted" style="margin-top: 5px;">
                                    Started: <?php echo $current_session['actual_start_time'] ? date('H:i', strtotime($current_session['actual_start_time'])) : 'Unknown'; ?>
                                </p>
                            <?php else: ?>
                                <span class="chip completed">Session Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Enrollment Requests (Admin) -->
            <div class="form-section" style="margin-top: 20px;">
                <h2>Enrollment Requests</h2>
                <?php
                // fetch pending requests
                try {
                    $r = $conn->query("SELECT er.id, er.created_at, u.first_name, u.last_name, u.user_id, c.class_code, c.class_name FROM enrollment_requests er JOIN users u ON er.student_id = u.id JOIN classes c ON er.class_id = c.id WHERE er.status = 'pending' ORDER BY er.created_at ASC");
                    $pending_requests = $r->fetchAll();
                } catch (PDOException $e) { $pending_requests = []; }
                ?>
                <div class="scanning-status">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Requested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_requests)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#6b7280; padding:12px;">No pending requests</td></tr>
                            <?php else: foreach ($pending_requests as $pr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name'] . ' (' . $pr['user_id'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($pr['class_code'] . ' - ' . $pr['class_name']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($pr['created_at'])); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="process_request" value="1" />
                                        <input type="hidden" name="request_id" value="<?php echo $pr['id']; ?>" />
                                        <input type="hidden" name="action_type" value="approve" />
                                        <button class="btn" type="submit">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline; margin-left:8px;">
                                        <input type="hidden" name="process_request" value="1" />
                                        <input type="hidden" name="request_id" value="<?php echo $pr['id']; ?>" />
                                        <input type="hidden" name="action_type" value="reject" />
                                        <button class="btn-danger" type="submit">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Legend -->
            <div class="legend">
                <div class="legend-item"><span class="status-dot present"></span> Present</div>
                <div class="legend-item"><span class="status-dot absent"></span> Absent</div>
                <div class="legend-item"><span class="status-dot late"></span> Late</div>
                <div class="legend-item"><span class="status-dot" style="background: #6b7280;"></span> Not Marked</div>
            </div>

            <!-- Analytics Summary -->
            <div class="class-cards" style="margin-top:20px;">
                <div class="card">
                    <h3>Total Active Classes</h3>
                    <h2><?php echo isset($active_classes) ? count($active_classes) : 0; ?></h2>
                </div>
                <div class="card">
                    <h3>Total Enrollments</h3>
                    <h2><?php echo array_sum(array_map(fn($c)=> (int)$c['enrolled_count'], $active_classes ?? [])); ?></h2>
                </div>
                <div class="card">
                    <h3>Average Attendance</h3>
                    <h2><?php echo $active_classes ? number_format(array_sum(array_map(fn($c)=> (float)$c['avg_attendance'], $active_classes))/max(count($active_classes),1),1) : 0; ?>%</h2>
                </div>
            </div>
        </main>
    </div>

    <!-- Attendance Update Modal -->
    <div id="attendanceModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 10px; min-width: 400px;">
            <h3 id="modalStudentName">Update Attendance</h3>
            <form method="POST" id="attendanceForm">
                <input type="hidden" name="update_attendance" value="1">
                <input type="hidden" name="student_id" id="modalStudentId">
                
                <div style="margin: 20px 0;">
                    <label>Status:</label><br>
                    <select name="status" id="modalStatus" class="class-selector" style="width: 100%; margin-top: 5px;">
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="late">Late</option>
                    </select>
                </div>
                
                <div style="margin: 20px 0;">
                    <label>Notes (optional):</label><br>
                    <textarea name="notes" id="modalNotes" rows="3" style="width: 100%; margin-top: 5px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Add any notes..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeAttendanceModal()" style="background: #e5e7eb; color: #111; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">Cancel</button>
                    <button type="submit" style="background: #4f46e5; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">Update Attendance</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function changeSession(sessionId) {
            if (sessionId) {
                window.location.href = 'class_management.php?session_id=' + sessionId;
            }
        }
        
        function openAttendanceModal(studentId, studentName, currentStatus) {
            document.getElementById('modalStudentId').value = studentId;
            document.getElementById('modalStudentName').textContent = 'Update Attendance for ' + studentName;
            document.getElementById('modalStatus').value = currentStatus || 'present';
            document.getElementById('attendanceModal').style.display = 'block';
        }
        
        function closeAttendanceModal() {
            document.getElementById('attendanceModal').style.display = 'none';
        }
        
        function startSession() {
            if (confirm('Start this session? This will update the session status to "In Progress".')) {
                fetch('update_session_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'session_id=<?php echo $session_id; ?>&status=in_progress'
                }).then(() => location.reload());
            }
        }
        
        function endSession() {
            if (confirm('End this session? This will mark the session as completed.')) {
                fetch('update_session_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'session_id=<?php echo $session_id; ?>&status=completed'
                }).then(() => location.reload());
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('attendanceModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAttendanceModal();
            }
        });
    </script>
</body>
</html>