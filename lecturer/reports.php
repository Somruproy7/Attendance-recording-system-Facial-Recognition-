<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || 
    (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') && 
    (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'lecturer')) {
    header('Location: ../login.php');
    exit();
}

require_once 'includes/header.php';

$db = new Database();
$conn = $db->getConnection();

// Get filter parameters
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Default to end of current month
$student_search = trim($_GET['student_search'] ?? '');

try {
    // Get lecturer's basic info
    $stmt = $conn->prepare("
        SELECT id, first_name, last_name, user_id 
        FROM users 
        WHERE id = :user_id AND user_type = 'lecturer' AND status = 'active'");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $lecturer = $stmt->fetch();

    if (!$lecturer) {
        throw new Exception("Lecturer not found or inactive");
    }

    // Get lecturer's classes for filter dropdown
    $stmt = $conn->prepare("
        SELECT c.id, c.class_code, c.class_name
        FROM classes c
        JOIN class_lecturers cl ON c.id = cl.class_id
        WHERE cl.lecturer_id = :lecturer_id
        AND c.status = 'active'
        ORDER BY c.class_code");
    $stmt->execute(['lecturer_id' => $lecturer['id']]);
    $classes = $stmt->fetchAll();

    // Get report data based on filters
    $report_data = [];
    $class_stats = [];
    $total_sessions = 0;
    $total_present = 0;
    $total_absences = 0;
    $attendance_rate = 0;

    if ($class_id > 0) {
        // Validate that lecturer has access to this class
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM class_lecturers 
            WHERE lecturer_id = :lecturer_id AND class_id = :class_id");
        $stmt->execute([
            'lecturer_id' => $lecturer['id'],
            'class_id' => $class_id
        ]);
        
        if ($stmt->fetch()['count'] > 0) {
            // Get class details
            $stmt = $conn->prepare("
                SELECT c.*, 
                    (SELECT COUNT(*) FROM student_enrollments WHERE class_id = c.id AND status = 'enrolled') as total_students
                FROM classes c 
                WHERE c.id = :class_id");
            $stmt->execute(['class_id' => $class_id]);
            $class_stats = $stmt->fetch();

            // Get all sessions for this class in date range
            $stmt = $conn->prepare("
                SELECT 
                    si.id,
                    si.session_date,
                    ts.session_title,
                    ts.start_time,
                    ts.end_time,
                    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_instance_id = si.id) as present_count
                FROM session_instances si
                JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                WHERE ts.class_id = :class_id
                AND si.session_date BETWEEN :start_date AND :end_date
                ORDER BY si.session_date, ts.start_time");
            
            $stmt->execute([
                'class_id' => $class_id,
                'start_date' => $start_date,
                'end_date' => $end_date
            ]);
            $sessions = $stmt->fetchAll();
            $total_sessions = count($sessions);

            // Get all enrolled students with their attendance
            $sql = "
                SELECT 
                    u.id,
                    u.user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    (
                        SELECT COUNT(*)
                        FROM attendance_records ar
                        JOIN session_instances si ON ar.session_instance_id = si.id
                        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                        WHERE ar.student_id = u.id
                        AND ts.class_id = :class_id
                        AND si.session_date BETWEEN :start_date AND :end_date
                    ) as present_count
                FROM users u
                JOIN student_enrollments se ON u.id = se.student_id
                WHERE se.class_id = :class_id
                AND se.status = 'enrolled'
            ";

            $params = [
                'class_id' => $class_id,
                'start_date' => $start_date,
                'end_date' => $end_date
            ];

            // Add search filter if provided
            if (!empty($student_search)) {
                $sql .= " AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.user_id LIKE :search)";
                $params['search'] = "%$student_search%";
            }

            $sql .= " ORDER BY u.last_name, u.first_name";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll();

            // Calculate attendance stats
            $total_present = 0;
            $total_possible = $total_sessions * count($students);
            
            foreach ($students as $student) {
                $student_attendance = [
                    'id' => $student['id'],
                    'user_id' => $student['user_id'],
                    'name' => $student['first_name'] . ' ' . $student['last_name'],
                    'email' => $student['email'],
                    'present' => (int)$student['present_count'],
                    'absent' => $total_sessions - (int)$student['present_count'],
                    'attendance_rate' => $total_sessions > 0 ? round(($student['present_count'] / $total_sessions) * 100) : 0
                ];
                $report_data[] = $student_attendance;
                $total_present += $student['present_count'];
            }

            // Calculate overall attendance rate
            if ($total_possible > 0) {
                $attendance_rate = round(($total_present / $total_possible) * 100);
            }
        }
    }

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $report_data = [];
    $class_stats = [];
}
?>

<div class="page-header">
    <div class="header-content">
        <h1>Attendance Reports</h1>
        <p>View and analyze class attendance data</p>
    </div>
    <div class="header-actions">
        <?php if (!empty($report_data)): ?>
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <button type="button" class="btn btn-outline-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<style>
        .report-filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4b5563;
        }
        .filter-group select,
        .filter-group input[type="date"],
        .filter-group input[type="text"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #4f46e5;
            margin: 5px 0;
        }
        .stat-label {
            color: #6b7280;
            font-size: 0.9em;
        }
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .attendance-table th,
        .attendance-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f3f4f6;
        }
        .attendance-table th {
            background: #f9fafb;
            color: #4b5563;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75em;
            letter-spacing: 0.05em;
        }
        .attendance-table tbody tr:hover {
            background: #f9fafb;
        }
        .attendance-rate {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 500;
            font-size: 0.85em;
        }
        .rate-high { background: #dcfce7; color: #166534; }
        .rate-medium { background: #fef9c3; color: #854d0e; }
        .rate-low { background: #fee2e2; color: #991b1b; }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        .empty-state p {
            margin: 10px 0 0;
        }
        .btn-export {
            background: #4f46e5;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
    </style>

<!-- Filters Card -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Report Filters</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="class_id" class="form-label">Class</label>
                <select id="class_id" name="class_id" class="form-select" required>
                    <option value="">Select a class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" 
                            <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" id="start_date" name="start_date" class="form-control" 
                       value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="col-md-3">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" id="end_date" name="end_date" class="form-control" 
                       value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="col-md-2">
                <label for="student_search" class="form-label">Search</label>
                <input type="text" id="student_search" name="student_search" class="form-control" 
                       placeholder="Name or ID" value="<?php echo htmlspecialchars($student_search); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Statistics -->
<?php if ($class_id > 0 && !empty($class_stats)): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-0 bg-primary bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-primary mb-1"><?php echo $class_stats['total_students']; ?></div>
                <div class="text-muted">Total Students</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-info bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-info mb-1"><?php echo $total_sessions; ?></div>
                <div class="text-muted">Sessions</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-success bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-success mb-1"><?php echo $total_present; ?></div>
                <div class="text-muted">Present Records</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-warning bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-warning mb-1"><?php echo $attendance_rate; ?>%</div>
                <div class="text-muted">Overall Attendance</div>
            </div>
        </div>
    </div>
</div>

<!-- Report Table -->
<?php if (!empty($report_data)): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Student Attendance Report</h5>
        <small class="text-muted"><?= count($report_data) ?> students</small>
    </div>
    
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Attendance Rate</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><span class="badge bg-success"><?php echo $student['present']; ?></span></td>
                            <td><span class="badge bg-danger"><?php echo $student['absent']; ?></span></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $student['attendance_rate'] >= 80 ? 'success' : 
                                         ($student['attendance_rate'] >= 50 ? 'warning' : 'danger'); 
                                ?>">
                                    <?php echo $student['attendance_rate']; ?>%
                                </span>
                            </td>
                            <td>
                                <a href="student_attendance.php?student_id=<?php echo $student['id']; ?>&class_id=<?php echo $class_id; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-eye"></i> Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <h4>No Records Found</h4>
                <p>There are no attendance records for the selected filters.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($class_id === 0 && !empty($_GET)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-filter"></i>
                <h4>Select a Class</h4>
                <p>Please choose a class from the dropdown above to generate attendance reports.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-chalkboard-teacher"></i>
                <h4>No Classes Assigned</h4>
                <p>You don't have any classes assigned to you yet. Please contact your administrator.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function exportToExcel() {
    const table = document.querySelector('.table');
    if (!table) {
        alert('No data to export');
        return;
    }
    
    let html = `
        <html>
        <head>
            <meta charset="utf-8">
            <title>Attendance Report</title>
        </head>
        <body>
            ${table.outerHTML}
        </body>
        </html>
    `;
    
    let blob = new Blob([html], {type: 'application/vnd.ms-excel'});
    let url = URL.createObjectURL(blob);
    
    let a = document.createElement('a');
    a.href = url;
    a.download = 'attendance_report_' + new Date().toISOString().split('T')[0] + '.xls';
    a.click();
    
    URL.revokeObjectURL(url);
}

// Auto-submit form when class changes
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('class_id').addEventListener('change', function() {
        if (this.value) {
            this.closest('form').submit();
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
