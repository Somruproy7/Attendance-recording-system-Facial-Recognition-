<?php
// student/my_attendance.php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$student_id = $_SESSION['user_id'];

// Handle filters
$search = sanitize_input($_GET['q'] ?? '');
$class_filter = sanitize_input($_GET['class'] ?? '');
$status_filter = sanitize_input($_GET['status'] ?? '');
$from_date = sanitize_input($_GET['from_date'] ?? '');
$to_date = sanitize_input($_GET['to_date'] ?? '');

try {
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
                END, 0
            ) as attendance_percentage
        FROM attendance_records ar
        JOIN session_instances si ON ar.session_instance_id = si.id
        WHERE ar.student_id = :student_id
    ");
    $stmt->execute(['student_id' => $student_id]);
    $summary = $stmt->fetch();
    
    // Build query with filters
    $where_conditions = ["ar.student_id = :student_id"];
    $params = ['student_id' => $student_id];
    
    if ($search) {
        $where_conditions[] = "(c.class_code LIKE :search OR ts.session_title LIKE :search OR DATE_FORMAT(si.session_date, '%Y-%m-%d') LIKE :search)";
        $params['search'] = "%$search%";
    }
    
    if ($class_filter) {
        $where_conditions[] = "c.class_code = :class_filter";
        $params['class_filter'] = $class_filter;
    }
    
    if ($status_filter) {
        $where_conditions[] = "ar.status = :status_filter";
        $params['status_filter'] = $status_filter;
    }
    
    if ($from_date) {
        $where_conditions[] = "si.session_date >= :from_date";
        $params['from_date'] = $from_date;
    }
    
    if ($to_date) {
        $where_conditions[] = "si.session_date <= :to_date";
        $params['to_date'] = $to_date;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get detailed attendance records
    $stmt = $conn->prepare("
        SELECT 
            si.session_date,
            c.class_code,
            ts.session_title,
            ar.status,
            ar.check_in_time,
            ar.notes
        FROM attendance_records ar
        JOIN session_instances si ON ar.session_instance_id = si.id
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        WHERE $where_clause
        ORDER BY si.session_date DESC, ts.start_time DESC
    ");
    
    $stmt->execute($params);
    $attendance_records = $stmt->fetchAll();
    
    // Get enrolled classes for filter dropdown
    $stmt = $conn->prepare("
        SELECT DISTINCT c.class_code, c.class_name
        FROM classes c
        JOIN student_enrollments se ON c.id = se.class_id
        WHERE se.student_id = :student_id AND se.status = 'enrolled' AND c.status = 'active'
        ORDER BY c.class_code
    ");
    $stmt->execute(['student_id' => $student_id]);
    $enrolled_classes = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $attendance_records = [];
    $enrolled_classes = [];
    $summary = ['total_sessions' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'attendance_percentage' => 0];
}

function formatCheckInTime($check_in_time) {
    return $check_in_time ? date('h:i A', strtotime($check_in_time)) : '—';
}

function getStatusClass($status) {
    switch ($status) {
        case 'present': return 'present';
        case 'absent': return 'absent';
        case 'late': return 'late';
        default: return 'absent';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>My Attendance - Full Attend</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../student.css?v=<?php echo time(); ?>">
</head>
<body class="student-portal">
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        <!-- Main -->
        <main class="dashboard-content">
            <!-- Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h1>My Attendance</h1>
                    <p class="muted">See your attendance by class and session (<?php echo count($attendance_records); ?> records)</p>
                </div>
                <div class="toolbar">
                    <a class="btn" id="btnCsv" href="#" onclick="exportCSV()">Export CSV</a>
                    <a class="btn" id="btnPrint" href="#" onclick="window.print()">Print / PDF</a>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-top: 16px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- KPI / Progress -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <h4>Overall Attendance</h4>
                    <div class="kpi-value"><?php echo $summary['attendance_percentage']; ?>%</div>
                </div>
                <div class="kpi-card">
                    <h4>Present</h4>
                    <div class="kpi-value"><?php echo $summary['present_count']; ?></div>
                </div>
                <div class="kpi-card">
                    <h4>Absent</h4>
                    <div class="kpi-value"><?php echo $summary['absent_count']; ?></div>
                </div>
                <div class="kpi-card">
                    <h4>Late</h4>
                    <div class="kpi-value"><?php echo $summary['late_count']; ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="toolbar" style="margin-top:16px;">
                <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input 
                        name="q" 
                        class="search" 
                        type="search" 
                        placeholder="Search by class, topic or date…"
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                    <select name="class" class="class-selector">
                        <option value="">All Classes</option>
                        <?php foreach ($enrolled_classes as $class): ?>
                        <option value="<?php echo htmlspecialchars($class['class_code']); ?>" 
                                <?php echo $class_filter === $class['class_code'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="class-selector">
                        <option value="">Any Status</option>
                        <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                        <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>Absent</option>
                        <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
                    </select>
                    <input name="from_date" type="date" class="search" value="<?php echo htmlspecialchars($from_date); ?>" title="From Date">
                    <input name="to_date" type="date" class="search" value="<?php echo htmlspecialchars($to_date); ?>" title="To Date">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="my_attendance.php" class="btn" style="background: #e5e7eb; color: #111;">Clear</a>
                </form>
            </div>

            <!-- Legend -->
            <div class="legend" style="justify-content:flex-start;margin-top:10px;">
                <div class="legend-item"><span class="status-dot present"></span> Present</div>
                <div class="legend-item"><span class="status-dot absent"></span> Absent</div>
                <div class="legend-item"><span class="status-dot late"></span> Late</div>
            </div>

            <!-- Attendance Table -->
            <section style="margin-top:12px;">
                <table class="attendance-table" id="attTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Class</th>
                            <th>Session / Topic</th>
                            <th>Status</th>
                            <th>Check-in</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendance_records)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #6b7280; padding: 30px;">
                                <?php echo $search || $class_filter || $status_filter || $from_date || $to_date ? 'No records found matching your criteria.' : 'No attendance records found.'; ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($attendance_records as $record): ?>
                        <tr>
                            <td class="c-date"><?php echo date('Y-m-d', strtotime($record['session_date'])); ?></td>
                            <td class="c-class"><?php echo htmlspecialchars($record['class_code']); ?></td>
                            <td><?php echo htmlspecialchars($record['session_title']); ?></td>
                            <td class="c-status">
                                <span class="chip <?php echo getStatusClass($record['status']); ?>">
                                    <?php echo ucfirst($record['status']); ?>
                                </span>
                            </td>
                            <td><?php echo formatCheckInTime($record['check_in_time']); ?></td>
                            <td><?php echo htmlspecialchars($record['notes'] ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <!-- Summary Statistics -->
            <?php if (!empty($attendance_records)): ?>
            <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px;">
                <h3 style="margin: 0 0 10px 0;">Attendance Summary</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <strong>Total Sessions:</strong> <?php echo $summary['total_sessions']; ?>
                    </div>
                    <div>
                        <strong>Present:</strong> <?php echo $summary['present_count']; ?> 
                        (<?php echo $summary['total_sessions'] > 0 ? round(($summary['present_count'] / $summary['total_sessions']) * 100, 1) : 0; ?>%)
                    </div>
                    <div>
                        <strong>Late:</strong> <?php echo $summary['late_count']; ?> 
                        (<?php echo $summary['total_sessions'] > 0 ? round(($summary['late_count'] / $summary['total_sessions']) * 100, 1) : 0; ?>%)
                    </div>
                    <div>
                        <strong>Absent:</strong> <?php echo $summary['absent_count']; ?> 
                        (<?php echo $summary['total_sessions'] > 0 ? round(($summary['absent_count'] / $summary['total_sessions']) * 100, 1) : 0; ?>%)
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function exportCSV() {
            const table = document.getElementById('attTable');
            let csv = [];
            
            // Add headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push('"' + th.textContent.replace(/"/g, '""') + '"');
            });
            csv.push(headers.join(','));
            
            // Add data rows
            table.querySelectorAll('tbody tr').forEach(row => {
                if (row.style.display !== 'none') {
                    const cols = [];
                    row.querySelectorAll('td').forEach(td => {
                        cols.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
                    });
                    csv.push(cols.join(','));
                }
            });
            
            // Download CSV
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'my_attendance_<?php echo date('Y-m-d'); ?>.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>