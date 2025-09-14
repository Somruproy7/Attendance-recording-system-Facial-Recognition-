<?php
// reports.php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$report_type = $_GET['type'] ?? 'attendance';
$class_id = $_GET['class_id'] ?? '';
$student_query = $_GET['student_query'] ?? '';
$status_filter = $_GET['status_filter'] ?? 'all';
$date_range = $_GET['date_range'] ?? 'this_month';

// Set date range based on selection
switch ($date_range) {
    case 'today':
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
        break;
    case 'yesterday':
        $date_from = date('Y-m-d', strtotime('-1 day'));
        $date_to = $date_from;
        break;
    case 'this_week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d');
        break;
    case 'last_week':
        $date_from = date('Y-m-d', strtotime('monday last week'));
        $date_to = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('last month'));
        $date_to = date('Y-m-t', strtotime('last month'));
        break;
    default: // this_month
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-d');
        break;
}

// Override with custom dates if provided
$date_from = $_GET['date_from'] ?? $date_from;
$date_to = $_GET['date_to'] ?? $date_to;

try {
    // Get all classes for filter
    $stmt = $conn->query("SELECT id, class_code, class_name FROM classes WHERE status = 'active' ORDER BY class_code");
    $classes = $stmt->fetchAll();
    
    // Get report data based on type
    switch ($report_type) {
        case 'attendance':
            // Build where conditions
            $where_conditions = ["si.session_date BETWEEN :date_from AND :date_to"];
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($class_id) {
                $where_conditions[] = "c.id = :class_id";
                $params['class_id'] = $class_id;
            }
            
            if (!empty($student_query)) {
                $where_conditions[] = "(u.first_name LIKE :student_query OR u.last_name LIKE :student_query OR u.user_id LIKE :student_query OR u.email LIKE :student_query)";
                $params['student_query'] = "%$student_query%";
            }
            
            if ($status_filter !== 'all') {
                $where_conditions[] = "ar.status = :status_filter";
                $params['status_filter'] = $status_filter;
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // Build the base query
            $query = "
                SELECT 
                    c.class_code,
                    c.class_name,
                    u.user_id,
                    u.first_name,
                    u.last_name,
                    COUNT(DISTINCT si.id) as total_sessions,
                    SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    ROUND(
                        CASE 
                            WHEN COUNT(DISTINCT si.id) > 0 
                            THEN (SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT si.id)) * 100 
                            ELSE 0 
                        END, 1
                    ) as attendance_percentage
                FROM classes c
                JOIN student_enrollments se ON c.id = se.class_id
                JOIN users u ON se.student_id = u.id
                LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
                LEFT JOIN session_instances si ON ts.id = si.timetable_session_id 
                    AND si.session_date BETWEEN :date_from AND :date_to
                LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id AND ar.student_id = u.id
                WHERE se.status = 'enrolled' AND u.status = 'active'
            ";
            
            // Add conditions based on filters
            $conditions = [];
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($class_id) {
                $conditions[] = 'c.id = :class_id';
                $params['class_id'] = $class_id;
            }
            
            if (!empty($student_query)) {
                $conditions[] = '(u.first_name LIKE :student_query OR u.last_name LIKE :student_query OR u.user_id LIKE :student_query OR u.email LIKE :student_query)';
                $params['student_query'] = "%$student_query%";
            }
            
            if ($status_filter !== 'all') {
                $conditions[] = 'ar.status = :status_filter';
                $params['status_filter'] = $status_filter;
            }
            
            // Add conditions to query
            if (!empty($conditions)) {
                $query .= ' AND ' . implode(' AND ', $conditions);
            }
            
            // Add grouping and ordering
            $query .= " GROUP BY c.id, c.class_code, c.class_name, u.id, u.user_id, u.first_name, u.last_name
                       ORDER BY c.class_code, u.last_name, u.first_name";
            
            // Execute the query
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $attendance_data = $stmt->fetchAll();
            break;
            
        case 'class_summary':
            // Build the base query
            $query = "
                SELECT 
                    c.class_code,
                    c.class_name,
                    COUNT(DISTINCT se.student_id) as total_students,
                    COUNT(DISTINCT si.id) as total_sessions,
                    COALESCE(AVG(
                        CASE WHEN ar.status = 'present' THEN 100 
                             WHEN ar.status = 'late' THEN 50 
                             ELSE 0 END
                    ), 0) as avg_attendance,
                    COUNT(DISTINCT CASE WHEN ar.status = 'present' THEN ar.student_id END) as students_with_attendance
                FROM classes c
                LEFT JOIN student_enrollments se ON c.id = se.class_id AND se.status = 'enrolled'
                LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
                LEFT JOIN session_instances si ON ts.id = si.timetable_session_id 
                    AND si.session_date BETWEEN :date_from AND :date_to
                LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
                WHERE c.status = 'active'
            ";
            
            // Add conditions based on filters
            $conditions = [];
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($class_id) {
                $conditions[] = 'c.id = :class_id';
                $params['class_id'] = $class_id;
            }
            
            if (!empty($student_query)) {
                $query .= " LEFT JOIN users u ON se.student_id = u.id";
                $conditions[] = '(u.first_name LIKE :student_query OR u.last_name LIKE :student_query OR u.user_id LIKE :student_query OR u.email LIKE :student_query)';
                $params['student_query'] = "%$student_query%";
            }
            
            if ($status_filter !== 'all') {
                $conditions[] = 'ar.status = :status_filter';
                $params['status_filter'] = $status_filter;
            }
            
            // Add conditions to query
            if (!empty($conditions)) {
                $query .= ' AND ' . implode(' AND ', $conditions);
            }
            
            // Add grouping and ordering
            $query .= " GROUP BY c.id, c.class_code, c.class_name
                       ORDER BY c.class_code";
            
            // Execute the query
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $class_summary = $stmt->fetchAll();
            break;
            
        case 'daily_attendance':
            // Build the base query
            $query = "
                SELECT 
                    si.session_date,
                    c.class_code,
                    c.class_name,
                    ts.session_title,
                    ts.start_time,
                    ts.end_time,
                    COUNT(DISTINCT se.student_id) as total_enrolled,
                    COUNT(DISTINCT ar.student_id) as total_attended,
                    SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    ROUND(
                        CASE 
                            WHEN COUNT(DISTINCT se.student_id) > 0 
                            THEN (COUNT(DISTINCT ar.student_id) / COUNT(DISTINCT se.student_id)) * 100 
                            ELSE 0 
                        END, 1
                    ) as attendance_rate
                FROM session_instances si
                JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                JOIN classes c ON ts.class_id = c.id
                LEFT JOIN student_enrollments se ON c.id = se.class_id AND se.status = 'enrolled'
                LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
                WHERE si.session_date BETWEEN :date_from AND :date_to
            ";
            
            // Add conditions based on filters
            $conditions = [];
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($class_id) {
                $conditions[] = 'c.id = :class_id';
                $params['class_id'] = $class_id;
            }
            
            if (!empty($student_query)) {
                $query .= " LEFT JOIN users u ON ar.student_id = u.id";
                $conditions[] = '(u.first_name LIKE :student_query OR u.last_name LIKE :student_query OR u.user_id LIKE :student_query)';
                $params['student_query'] = "%$student_query%";
            }
            
            if ($status_filter !== 'all') {
                $conditions[] = 'ar.status = :status_filter';
                $params['status_filter'] = $status_filter;
            }
            
            // Add conditions to query
            if (!empty($conditions)) {
                $query .= ' AND ' . implode(' AND ', $conditions);
            }
            
            // Add grouping and ordering
            $query .= " GROUP BY si.session_date, c.id, c.class_code, c.class_name, ts.session_title, ts.start_time, ts.end_time
                       ORDER BY si.session_date DESC, ts.start_time";
            
            // Execute the query
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $daily_attendance = $stmt->fetchAll();
            break;
            
        case 'student_performance':
            // Build the base query
            $query = "
                SELECT 
                    u.user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    COUNT(DISTINCT se.class_id) as enrolled_classes,
                    COUNT(DISTINCT ar.id) as total_attendance_records,
                    SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    ROUND(
                        CASE 
                            WHEN COUNT(DISTINCT ar.id) > 0 
                            THEN (SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT ar.id)) * 100 
                            ELSE 0 
                        END, 1
                    ) as overall_attendance,
                    MIN(ar.check_in_time) as first_attendance,
                    MAX(ar.check_in_time) as last_attendance
                FROM users u
                LEFT JOIN student_enrollments se ON u.id = se.student_id AND se.status = 'enrolled'
                LEFT JOIN attendance_records ar ON u.id = ar.student_id
                LEFT JOIN session_instances si ON ar.session_instance_id = si.id
                LEFT JOIN timetable_sessions ts2 ON si.timetable_session_id = ts2.id
                LEFT JOIN classes c ON se.class_id = c.id
                WHERE u.user_type = 'student' AND u.status = 'active'
            ";
            
            // Add conditions based on filters
            $conditions = [];
            $params = [];
            
            if (!empty($student_query)) {
                $conditions[] = '(u.first_name LIKE :student_query OR u.last_name LIKE :student_query OR u.user_id LIKE :student_query OR u.email LIKE :student_query)';
                $params['student_query'] = "%$student_query%";
            }
            
            if ($class_id) {
                $conditions[] = 'c.id = :class_id';
                $params['class_id'] = $class_id;
            }
            
            if ($status_filter !== 'all') {
                $conditions[] = 'ar.status = :status_filter';
                $params['status_filter'] = $status_filter;
            }
            
            // Add date range filter for attendance records
            $conditions[] = '(si.session_date IS NULL OR (si.session_date BETWEEN :date_from AND :date_to))';
            $params['date_from'] = $date_from;
            $params['date_to'] = $date_to;
            
            // Add WHERE clause if we have conditions
            if (!empty($conditions)) {
                $query .= ' AND ' . implode(' AND ', $conditions);
            }
            
            // Add grouping
            $query .= " GROUP BY u.id, u.user_id, u.first_name, u.last_name, u.email";
            
            // Add HAVING clause for class filter if needed
            $having_conditions = [];
            if ($class_id) {
                $having_conditions[] = "COUNT(DISTINCT CASE WHEN se.class_id = :class_id2 THEN se.class_id END) > 0";
                $params['class_id2'] = $class_id;
            }
            
            // Add HAVING clause if we have conditions
            if (!empty($having_conditions)) {
                $query .= ' HAVING ' . implode(' AND ', $having_conditions);
            }
            
            // Add ordering
            $query .= " ORDER BY overall_attendance DESC, u.last_name, u.first_name";
            
            // Execute the query
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $student_performance = $stmt->fetchAll();
            break;
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Full Attend</title>
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
                <a href="timetable_management.php">Timetable</a>
                <a href="reports.php" class="active">Reports</a>
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
                    <h1>Reports & Analytics</h1>
                    <p class="muted">Generate insights across classes and students</p>
                </div>
                <div class="toolbar">
                    <span class="pill">From <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></span>
                </div>
            </div>

        <!-- Report Navigation -->
        <div class="report-nav">
            <a href="?type=attendance" class="<?php echo $report_type === 'attendance' ? 'active' : ''; ?>">Attendance Report</a>
            <a href="?type=class_summary" class="<?php echo $report_type === 'class_summary' ? 'active' : ''; ?>">Class Summary</a>
            <a href="?type=daily_attendance" class="<?php echo $report_type === 'daily_attendance' ? 'active' : ''; ?>">Daily Attendance</a>
            <a href="?type=student_performance" class="<?php echo $report_type === 'student_performance' ? 'active' : ''; ?>">Student Performance</a>
        </div>

        <!-- Filters -->
        <div class="filters" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <form method="GET" class="filter-form" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                        <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Class</label>
                            <select name="class_id" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Student</label>
                            <input type="text" name="student_query" value="<?php echo htmlspecialchars($student_query); ?>" 
                                   placeholder="Name or ID" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Status</label>
                            <select name="status_filter" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Date Range</label>
                            <select name="date_range" class="form-control" onchange="updateDateRangeFields(this.value)" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $date_range === 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="this_week" <?php echo $date_range === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="last_week" <?php echo $date_range === 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                                <option value="this_month" <?php echo $date_range === 'this_month' || empty($date_range) ? 'selected' : ''; ?>>This Month</option>
                                <option value="last_month" <?php echo $date_range === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                <option value="custom" <?php echo $date_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="custom-date-range" style="display: <?php echo $date_range === 'custom' ? 'block' : 'none'; ?>; grid-column: 1 / -1;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">From</label>
                                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">To</label>
                                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end; gap: 10px;">
                            <button type="submit" class="btn btn-primary" style="padding: 8px 15px; background: #4a6baf; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                Apply Filters
                            </button>
                            <a href="?type=<?php echo $report_type; ?>" class="btn btn-secondary" style="padding: 8px 15px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;">
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <script>
                function updateDateRangeFields(range) {
                    const customRange = document.getElementById('custom-date-range');
                    if (range === 'custom') {
                        customRange.style.display = 'block';
                    } else {
                        customRange.style.display = 'none';
                    }
                }
                </script>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <button onclick="exportToCSV()" class="btn-secondary" type="button">Export to CSV</button>
            <button onclick="printReport()" class="btn-secondary" type="button">Print Report</button>
        </div>

        <!-- Report Content -->
        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php else: ?>
            <?php switch ($report_type): 
                case 'attendance': ?>
                    <h2>Attendance Report</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Total Sessions</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_data as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['class_code'] . ' - ' . $row['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['user_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo $row['total_sessions']; ?></td>
                                    <td><?php echo $row['present_count']; ?></td>
                                    <td><?php echo $row['late_count']; ?></td>
                                    <td><?php echo $row['absent_count']; ?></td>
                                    <td class="<?php 
                                        echo $row['attendance_percentage'] >= 80 ? 'attendance-high' : 
                                            ($row['attendance_percentage'] >= 60 ? 'attendance-medium' : 'attendance-low'); 
                                    ?>">
                                        <?php echo $row['attendance_percentage']; ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php break; ?>

                <?php case 'class_summary': ?>
                    <h2>Class Summary Report</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Total Students</th>
                                <th>Total Sessions</th>
                                <th>Students with Attendance</th>
                                <th>Average Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_summary as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['class_code'] . ' - ' . $row['class_name']); ?></td>
                                    <td><?php echo $row['total_students']; ?></td>
                                    <td><?php echo $row['total_sessions']; ?></td>
                                    <td><?php echo $row['students_with_attendance']; ?></td>
                                    <td class="<?php 
                                        echo $row['avg_attendance'] >= 80 ? 'attendance-high' : 
                                            ($row['avg_attendance'] >= 60 ? 'attendance-medium' : 'attendance-low'); 
                                    ?>">
                                        <?php echo round($row['avg_attendance'], 1); ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php break; ?>

                <?php case 'daily_attendance': ?>
                    <h2>Daily Attendance Report</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Class</th>
                                <th>Session</th>
                                <th>Time</th>
                                <th>Enrolled</th>
                                <th>Attended</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Rate %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_attendance as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['session_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['class_code'] . ' - ' . $row['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['session_title']); ?></td>
                                    <td><?php echo date('H:i', strtotime($row['start_time'])) . ' - ' . date('H:i', strtotime($row['end_time'])); ?></td>
                                    <td><?php echo $row['total_enrolled']; ?></td>
                                    <td><?php echo $row['total_attended']; ?></td>
                                    <td><?php echo $row['present_count']; ?></td>
                                    <td><?php echo $row['late_count']; ?></td>
                                    <td><?php echo $row['absent_count']; ?></td>
                                    <td class="<?php 
                                        echo $row['attendance_rate'] >= 80 ? 'attendance-high' : 
                                            ($row['attendance_rate'] >= 60 ? 'attendance-medium' : 'attendance-low'); 
                                    ?>">
                                        <?php echo $row['attendance_rate']; ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php break; ?>

                <?php case 'student_performance': ?>
                    <h2>Student Performance Report</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Enrolled Classes</th>
                                <th>Total Records</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Overall %</th>
                                <th>First Attendance</th>
                                <th>Last Attendance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_performance as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['user_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo $row['enrolled_classes']; ?></td>
                                    <td><?php echo $row['total_attendance_records']; ?></td>
                                    <td><?php echo $row['present_count']; ?></td>
                                    <td><?php echo $row['late_count']; ?></td>
                                    <td><?php echo $row['absent_count']; ?></td>
                                    <td class="<?php 
                                        echo $row['overall_attendance'] >= 80 ? 'attendance-high' : 
                                            ($row['overall_attendance'] >= 60 ? 'attendance-medium' : 'attendance-low'); 
                                    ?>">
                                        <?php echo $row['overall_attendance']; ?>%
                                    </td>
                                    <td><?php echo $row['first_attendance'] ? date('M d, Y H:i', strtotime($row['first_attendance'])) : 'N/A'; ?></td>
                                    <td><?php echo $row['last_attendance'] ? date('M d, Y H:i', strtotime($row['last_attendance'])) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php break; ?>
            <?php endswitch; ?>
        <?php endif; ?>
        </main>
    </div>

    <script>
        function exportToCSV() {
            const table = document.querySelector('.table');
            const rows = table.querySelectorAll('tr');
            let csv = [];
            
            for (let row of rows) {
                const cols = row.querySelectorAll('td, th');
                const rowData = [];
                for (let col of cols) {
                    rowData.push('"' + col.textContent.replace(/"/g, '""') + '"');
                }
                csv.push(rowData.join(','));
            }
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'attendance_report_<?php echo $report_type; ?>_<?php echo date('Y-m-d'); ?>.csv';
            a.click();
        }
        
        function printReport() {
            window.print();
        }
    </script>
</body>
</html>
