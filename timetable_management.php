<?php
// timetable_management.php
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

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_session':
                $class_id = $_POST['class_id'];
                $session_title = sanitize_input($_POST['session_title']);
                $session_type = $_POST['session_type'];
                $day_of_week = $_POST['day_of_week'];
                $start_time = $_POST['start_time'];
                $end_time = $_POST['end_time'];
                $room_location = sanitize_input($_POST['room_location']);
                $instructor = sanitize_input($_POST['instructor']);
                $recurring = isset($_POST['recurring']) ? 1 : 0;
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $notes = sanitize_input($_POST['notes']);
                
                $stmt = $conn->prepare("
                    INSERT INTO timetable_sessions (
                        class_id, session_title, session_type, day_of_week, start_time, end_time,
                        room_location, instructor, recurring, start_date, end_date, notes
                    ) VALUES (
                        :class_id, :session_title, :session_type, :day_of_week, :start_time, :end_time,
                        :room_location, :instructor, :recurring, :start_date, :end_date, :notes
                    )
                ");
                
                if ($stmt->execute([
                    'class_id' => $class_id,
                    'session_title' => $session_title,
                    'session_type' => $session_type,
                    'day_of_week' => $day_of_week,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'room_location' => $room_location,
                    'instructor' => $instructor,
                    'recurring' => $recurring,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'notes' => $notes
                ])) {
                    $message = "Session added successfully!";
                } else {
                    $error = "Failed to add session.";
                }
                break;
                
            case 'update_session':
                $session_id = $_POST['session_id'];
                $session_title = sanitize_input($_POST['session_title']);
                $session_type = $_POST['session_type'];
                $day_of_week = $_POST['day_of_week'];
                $start_time = $_POST['start_time'];
                $end_time = $_POST['end_time'];
                $room_location = sanitize_input($_POST['room_location']);
                $instructor = sanitize_input($_POST['instructor']);
                $recurring = isset($_POST['recurring']) ? 1 : 0;
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $notes = sanitize_input($_POST['notes']);
                
                $stmt = $conn->prepare("
                    UPDATE timetable_sessions SET 
                        session_title = :session_title,
                        session_type = :session_type,
                        day_of_week = :day_of_week,
                        start_time = :start_time,
                        end_time = :end_time,
                        room_location = :room_location,
                        instructor = :instructor,
                        recurring = :recurring,
                        start_date = :start_date,
                        end_date = :end_date,
                        notes = :notes
                    WHERE id = :session_id
                ");
                
                if ($stmt->execute([
                    'session_title' => $session_title,
                    'session_type' => $session_type,
                    'day_of_week' => $day_of_week,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'room_location' => $room_location,
                    'instructor' => $instructor,
                    'recurring' => $recurring,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'notes' => $notes,
                    'session_id' => $session_id
                ])) {
                    $message = "Session updated successfully!";
                } else {
                    $error = "Failed to update session.";
                }
                break;
                
            case 'delete_session':
                $session_id = $_POST['session_id'];
                
                $stmt = $conn->prepare("DELETE FROM timetable_sessions WHERE id = :session_id");
                if ($stmt->execute(['session_id' => $session_id])) {
                    $message = "Session deleted successfully!";
                } else {
                    $error = "Failed to delete session.";
                }
                break;
                
            case 'generate_instances':
                $session_id = $_POST['session_id'];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                
                // Get session details
                $stmt = $conn->prepare("SELECT * FROM timetable_sessions WHERE id = :session_id");
                $stmt->execute(['session_id' => $session_id]);
                $session = $stmt->fetch();
                
                if ($session) {
                    $current_date = new DateTime($start_date);
                    $end = new DateTime($end_date);
                    $day_of_week = $session['day_of_week'];
                    
                    while ($current_date <= $end) {
                        if ($current_date->format('l') === $day_of_week) {
                            // Check if instance already exists
                            $stmt = $conn->prepare("
                                SELECT id FROM session_instances 
                                WHERE timetable_session_id = :session_id AND session_date = :session_date
                            ");
                            $stmt->execute([
                                'session_id' => $session_id,
                                'session_date' => $current_date->format('Y-m-d')
                            ]);
                            
                            if ($stmt->rowCount() == 0) {
                                // Create new instance
                                $stmt = $conn->prepare("
                                    INSERT INTO session_instances (timetable_session_id, session_date, status)
                                    VALUES (:session_id, :session_date, 'scheduled')
                                ");
                                $stmt->execute([
                                    'session_id' => $session_id,
                                    'session_date' => $current_date->format('Y-m-d')
                                ]);
                            }
                        }
                        $current_date->add(new DateInterval('P1D'));
                    }
                    $message = "Session instances generated successfully!";
                } else {
                    $error = "Session not found.";
                }
                break;
        }
    }
}

// Ensure today's session instances are present
ensure_todays_session_instances($conn);

// Get all classes
$stmt = $conn->query("SELECT id, class_code, class_name FROM classes WHERE status = 'active' ORDER BY class_code");
$classes = $stmt->fetchAll();

// Get all timetable sessions with class info
$stmt = $conn->query("
    SELECT 
        ts.*,
        c.class_code,
        c.class_name,
        COUNT(si.id) as instance_count
    FROM timetable_sessions ts
    JOIN classes c ON ts.class_id = c.id
    LEFT JOIN session_instances si ON ts.id = si.timetable_session_id
    WHERE c.status = 'active'
    GROUP BY ts.id
    ORDER BY c.class_code, ts.day_of_week, ts.start_time
");
$sessions = $stmt->fetchAll();

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
        si.id as instance_id
    FROM timetable_sessions ts
    JOIN classes c ON ts.class_id = c.id
    LEFT JOIN session_instances si ON ts.id = si.timetable_session_id AND si.session_date = CURDATE()
    WHERE ts.day_of_week = DAYNAME(CURDATE())
    ORDER BY ts.start_time
");
$stmt->execute();
$todays_schedule = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Management - Full Attend</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="student.css?v=<?php echo time(); ?>">
</head>
<body class="admin-portal">
    <div class="dashboard">
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="class_management.php">Class Management</a>
                <a href="student_directory.php">Student Directory</a>
                <a href="student_registration.php">Student Management</a>
                <a href="admin/lecturer_management.php">Lecturer Management</a>
                <a href="timetable_management.php" class="active">Timetable</a>
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
                    <h1>Timetable Management</h1>
                    <p class="muted">Plan, view, and manage all sessions</p>
                </div>
                <div class="toolbar">
                    <span class="pill">Today: <?php echo date('M d, Y'); ?></span>
                </div>
            </div>

        <?php if ($message): ?>
            <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Add New Session -->
        <div class="form-section">
            <h2>Add New Session</h2>
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="add_session">
                
                <div class="form-group">
                    <label for="class_id">Class:</label>
                    <select id="class_id" name="class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="session_title">Session Title:</label>
                    <input type="text" id="session_title" name="session_title" required>
                </div>
                
                <div class="form-group">
                    <label for="session_type">Session Type:</label>
                    <select id="session_type" name="session_type" required>
                        <option value="Lecture">Lecture</option>
                        <option value="Lab">Lab</option>
                        <option value="Tutorial">Tutorial</option>
                        <option value="Exam">Exam</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="day_of_week">Day of Week:</label>
                    <select id="day_of_week" name="day_of_week" required>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="start_time">Start Time:</label>
                    <input type="time" id="start_time" name="start_time" required>
                </div>
                
                <div class="form-group">
                    <label for="end_time">End Time:</label>
                    <input type="time" id="end_time" name="end_time" required>
                </div>
                
                <div class="form-group">
                    <label for="room_location">Room Location:</label>
                    <input type="text" id="room_location" name="room_location" required>
                </div>
                
                <div class="form-group">
                    <label for="instructor">Instructor:</label>
                    <input type="text" id="instructor" name="instructor" required>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="recurring" name="recurring">
                        Recurring Session
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" required>
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" required>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes:</label>
                    <textarea id="notes" name="notes" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn-primary">Add Session</button>
                </div>
            </form>
        </div>

        <!-- Today's Schedule -->
        <div class="form-section">
            <h2>Today's Schedule (<?php echo date('l, F d, Y'); ?>)</h2>
            <?php if (empty($todays_schedule)): ?>
                <p>No sessions scheduled for today.</p>
            <?php else: ?>
                <div class="session-list">
                    <?php foreach ($todays_schedule as $session): ?>
                        <div class="session-card">
                            <h3><?php echo htmlspecialchars($session['session_title']); ?></h3>
                            <p><strong>Class:</strong> <?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></p>
                            <p><strong>Time:</strong> <?php echo date('H:i', strtotime($session['start_time'])) . ' - ' . date('H:i', strtotime($session['end_time'])); ?></p>
                            <p><strong>Room:</strong> <?php echo htmlspecialchars($session['room_location']); ?></p>
                            <p><strong>Type:</strong> <?php echo htmlspecialchars($session['session_type']); ?></p>
                            <p><strong>Status:</strong> 
                                <span class="status-<?php echo $session['session_status'] ?? 'scheduled'; ?>">
                                    <?php echo ucfirst($session['session_status'] ?? 'scheduled'); ?>
                                </span>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Weekly Timetable View -->
        <div class="form-section">
            <h2>Weekly Timetable</h2>
            <div class="timetable-grid">
                <?php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                foreach ($days as $day):
                    $day_sessions = array_filter($sessions, function($s) use ($day) {
                        return $s['day_of_week'] === $day;
                    });
                ?>
                    <div class="day-column">
                        <div class="day-header"><?php echo $day; ?></div>
                        <?php foreach ($day_sessions as $session): ?>
                            <div class="session-item <?php echo strtolower($session['session_type']); ?>">
                                <strong><?php echo htmlspecialchars($session['class_code']); ?></strong><br>
                                <?php echo htmlspecialchars($session['session_title']); ?><br>
                                <?php echo date('H:i', strtotime($session['start_time'])) . ' - ' . date('H:i', strtotime($session['end_time'])); ?><br>
                                <small><?php echo htmlspecialchars($session['room_location']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- All Sessions -->
        <div class="form-section">
            <h2>All Sessions</h2>
            <div class="session-list">
                <?php foreach ($sessions as $session): ?>
                    <div class="session-card">
                        <h3><?php echo htmlspecialchars($session['session_title']); ?></h3>
                        <p><strong>Class:</strong> <?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></p>
                        <p><strong>Day:</strong> <?php echo htmlspecialchars($session['day_of_week']); ?></p>
                        <p><strong>Time:</strong> <?php echo date('H:i', strtotime($session['start_time'])) . ' - ' . date('H:i', strtotime($session['end_time'])); ?></p>
                        <p><strong>Room:</strong> <?php echo htmlspecialchars($session['room_location']); ?></p>
                        <p><strong>Type:</strong> <?php echo htmlspecialchars($session['session_type']); ?></p>
                        <p><strong>Instructor:</strong> <?php echo htmlspecialchars($session['instructor']); ?></p>
                        <p><strong>Instances:</strong> <?php echo $session['instance_count']; ?></p>
                        
                        <div style="margin-top: 10px;">
                            <button onclick="editSession(<?php echo $session['id']; ?>)" class="btn-secondary">Edit</button>
                            <button onclick="deleteSession(<?php echo $session['id']; ?>)" class="btn-danger">Delete</button>
                            <button onclick="generateInstances(<?php echo $session['id']; ?>)" class="btn-primary">Generate Instances</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        </main>
    </div>

    <script>
        function editSession(sessionId) {
            // Implement edit functionality
            alert('Edit functionality for session ' + sessionId + ' - to be implemented');
        }
        
        function deleteSession(sessionId) {
            if (confirm('Are you sure you want to delete this session?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_session">
                    <input type="hidden" name="session_id" value="${sessionId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function generateInstances(sessionId) {
            const startDate = prompt('Enter start date (YYYY-MM-DD):');
            const endDate = prompt('Enter end date (YYYY-MM-DD):');
            
            if (startDate && endDate) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="generate_instances">
                    <input type="hidden" name="session_id" value="${sessionId}">
                    <input type="hidden" name="start_date" value="${startDate}">
                    <input type="hidden" name="end_date" value="${endDate}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
