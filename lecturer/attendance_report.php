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
$lecturer_id = $_SESSION['user_id'];

// Get filter parameters
$class_id = $_GET['class_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Default to end of current month

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

// Initialize report data
$reportData = [];
$summaryData = [];

if (!empty($class_id)) {
    // Get attendance data for the selected class and date range
    $stmt = $conn->prepare("
        SELECT 
            u.id as student_id,
            u.user_id as student_number,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            c.class_name,
            ts.session_title,
            si.session_date,
            ar.status,
            ar.check_in_time,
            ar.recognition_confidence,
            ar.notes
        FROM attendance_records ar
        JOIN session_instances si ON ar.session_instance_id = si.id
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN users u ON ar.student_id = u.id
        WHERE c.id = :class_id 
        AND si.session_date BETWEEN :start_date AND :end_date
        ORDER BY si.session_date DESC, u.last_name, u.first_name
    ");
    
    $stmt->execute([
        'class_id' => $class_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT si.id) as total_sessions,
            COUNT(DISTINCT ar.student_id) as total_students,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0) as present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0) as absent_count,
            SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0) as late_count
        FROM attendance_records ar
        JOIN session_instances si ON ar.session_instance_id = si.id
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        WHERE ts.class_id = :class_id 
        AND si.session_date BETWEEN :start_date AND :end_date
    ");
    
    $summaryStmt->execute([
        'class_id' => $class_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="page-header">
    <div class="header-content">
        <h1>Attendance Reports</h1>
        <p>View and analyze attendance records</p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Print Report
        </button>
        <button type="button" class="btn btn-outline-success" onclick="exportToExcel()">
            <i class="fas fa-file-excel me-2"></i>Export Excel
        </button>
    </div>
</div>

<style>
        .report-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
        }
        .summary-card h3 {
            margin: 0;
            font-size: 24px;
            color: #4f46e5;
        }
        .summary-card p {
            margin: 5px 0 0;
            color: #6b7280;
        }
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .attendance-table th, 
        .attendance-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .attendance-table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .status-present {
            color: #10b981;
            font-weight: 500;
        }
        .status-absent {
            color: #ef4444;
            font-weight: 500;
        }
        .status-late {
            color: #f59e0b;
            font-weight: 500;
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
        .btn-export {
            background-color: #10b981;
            margin-left: 10px;
        }
        .no-data {
            text-align: center;
            padding: 30px;
            color: #6b7280;
            font-style: italic;
        }
    </style>

<!-- Summary Statistics -->
<?php if (!empty($class_id) && !empty($summaryData)): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-0 bg-primary bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-primary mb-1"><?= $summaryData['total_sessions'] ?? 0 ?></div>
                <div class="text-muted">Total Sessions</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-info bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-info mb-1"><?= $summaryData['total_students'] ?? 0 ?></div>
                <div class="text-muted">Total Students</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 bg-success bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-success mb-1"><?= $summaryData['present_count'] ?? 0 ?></div>
                <div class="text-muted">Present</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 bg-warning bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-warning mb-1"><?= $summaryData['late_count'] ?? 0 ?></div>
                <div class="text-muted">Late</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 bg-danger bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-danger mb-1"><?= $summaryData['absent_count'] ?? 0 ?></div>
                <div class="text-muted">Absent</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters Card -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Filter Options</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label for="class_id" class="form-label">Select Class</label>
                <select id="class_id" name="class_id" class="form-select" required>
                    <option value="">-- Select Class --</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" 
                            <?php echo ($class_id == $class['id']) ? 'selected' : ''; ?>>
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
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search me-2"></i>Generate
                </button>
            </div>
        </form>
    </div>
</div>
            
<!-- Detailed Attendance Records -->
<?php if (!empty($class_id)): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Detailed Attendance Records</h5>
        <small class="text-muted"><?= count($reportData) ?> records found</small>
    </div>
    
    <div class="card-body p-0">
        <?php if (!empty($reportData)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Session</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Check-in Time</th>
                            <th>Confidence</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData as $record): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['student_number']); ?></td>
                                <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['session_title']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($record['session_date'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $record['status'] === 'present' ? 'success' : 
                                             ($record['status'] === 'late' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $record['check_in_time'] ? date('g:i A', strtotime($record['check_in_time'])) : 'N/A'; ?></td>
                                <td>
                                    <?php if ($record['recognition_confidence']): ?>
                                        <?php echo number_format($record['recognition_confidence'] * 100, 1) . '%'; ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($record['notes'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h4>No Records Found</h4>
                <p>No attendance records found for the selected criteria.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="fas fa-filter"></i>
            <h4>Select Filter Options</h4>
            <p>Please select a class and date range to generate the attendance report.</p>
        </div>
    </div>
</div>
<?php endif; ?>
<script>
// Export to Excel function
function exportToExcel() {
    let table = document.querySelector('.table');
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
