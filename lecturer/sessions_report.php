<?php
session_start();
require_once '../config/database.php';

// Ensure lecturer is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lecturer') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$lecturer_id = $_SESSION['user_id'];

// Get filter parameters
$class_id = $_GET['class_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+30 days'));

// Get classes taught by this lecturer
$classes = [];
$classStmt = $conn->prepare("
    SELECT c.id, c.class_code, c.class_name 
    FROM classes c 
    JOIN class_lecturers cl ON c.id = cl.class_id 
    WHERE cl.lecturer_id = :lecturer_id
    ORDER BY c.class_name
");
$classStmt->execute(['lecturer_id' => $lecturer_id]);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

// Get sessions data
$sessions = [];
$whereClause = "WHERE ts.lecturer_id = :lecturer_id ";
$params = ['lecturer_id' => $lecturer_id];

if (!empty($class_id)) {
    $whereClause .= " AND c.id = :class_id ";
    $params['class_id'] = $class_id;
}

$whereClause .= " AND si.session_date BETWEEN :start_date AND :end_date ";
$params['start_date'] = $start_date;
$params['end_date'] = $end_date;

$stmt = $conn->prepare("
    SELECT 
        si.id as session_instance_id,
        c.class_code,
        c.class_name,
        ts.session_title,
        ts.session_type,
        ts.day_of_week,
        si.session_date,
        ts.start_time,
        ts.end_time,
        ts.room_location,
        COUNT(DISTINCT ar.student_id) as total_students,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0) as present_count,
        SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0) as absent_count,
        SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0) as late_count
    FROM timetable_sessions ts
    JOIN classes c ON ts.class_id = c.id
    JOIN session_instances si ON ts.id = si.timetable_session_id
    LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
    $whereClause
    GROUP BY si.id, c.class_code, c.class_name, ts.session_title, ts.session_type, 
             ts.day_of_week, si.session_date, ts.start_time, ts.end_time, ts.room_location
    ORDER BY si.session_date, ts.start_time
");

$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sessions - Lecturer Dashboard</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="lecturer.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .sessions-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 0;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4b5563;
        }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .btn {
            background-color: #4f46e5;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            align-self: flex-end;
        }
        .session-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f9fafb;
        }
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .session-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        .session-meta {
            display: flex;
            gap: 15px;
            color: #4b5563;
            font-size: 14px;
        }
        .session-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        .stat-item {
            text-align: center;
            padding: 8px 15px;
            background: white;
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .stat-value {
            font-size: 18px;
            font-weight: 600;
            display: block;
        }
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-present { color: #10b981; }
        .status-absent { color: #ef4444; }
        .status-late { color: #f59e0b; }
        .no-sessions {
            text-align: center;
            padding: 30px;
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body class="lecturer-portal">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="dashboard-content">
        <div class="page-header">
            <h1>My Sessions</h1>
            <div class="actions">
                <button class="btn" onclick="window.print()">Print Report</button>
            </div>
        </div>

        <div class="sessions-container">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="class_id">Filter by Class</label>
                    <select id="class_id" name="class_id" class="form-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                <?php echo ($class_id == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" 
                           value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control"
                           value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">Filter</button>
                </div>
            </form>
            
            <div class="sessions-list">
                <?php if (!empty($sessions)): ?>
                    <?php 
                    $currentDate = null;
                    foreach ($sessions as $session): 
                        $sessionDate = date('l, F j, Y', strtotime($session['session_date']));
                        if ($currentDate !== $sessionDate): 
                            $currentDate = $sessionDate;
                    ?>
                        <h3 style="margin: 20px 0 10px; color: #4b5563;"><?php echo $sessionDate; ?></h3>
                    <?php endif; ?>
                    
                    <div class="session-card">
                        <div class="session-header">
                            <div>
                                <h3 class="session-title">
                                    <?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?>
                                    <span style="font-size: 14px; color: #6b7280;">
                                        (<?php echo htmlspecialchars($session['session_type']); ?>)
                                    </span>
                                </h3>
                                <div class="session-meta">
                                    <span>📅 <?php echo date('h:i A', strtotime($session['start_time'])); ?> - <?php echo date('h:i A', strtotime($session['end_time'])); ?></span>
                                    <span>📍 <?php echo htmlspecialchars($session['room_location']); ?></span>
                                </div>
                            </div>
                            <a href="session_attendance.php?session_id=<?php echo $session['session_instance_id']; ?>" class="btn" style="text-decoration: none;">
                                View Attendance
                            </a>
                        </div>
                        
                        <div class="session-stats">
                            <div class="stat-item">
                                <span class="stat-value"><?php echo $session['total_students']; ?></span>
                                <span class="stat-label">Total Students</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value status-present"><?php echo $session['present_count'] ?? 0; ?></span>
                                <span class="stat-label">Present</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value status-absent"><?php echo $session['absent_count'] ?? 0; ?></span>
                                <span class="stat-label">Absent</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value status-late"><?php echo $session['late_count'] ?? 0; ?></span>
                                <span class="stat-label">Late</span>
                            </div>
                        </div>
                    </div>
                    
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-sessions">
                        <p>No sessions found for the selected criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });
    </script>
</body>
</html>
