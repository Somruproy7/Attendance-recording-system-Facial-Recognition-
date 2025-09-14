<?php
session_start();
require_once '../../config/database.php';
require_once '../includes/header.php';

$db = new Database();
$conn = $db->getConnection();

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$class_id = $_GET['class_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+30 days'));

// Build the query
$query = "
    SELECT 
        si.id as instance_id,
        c.id as class_id,
        c.class_code,
        c.class_name,
        ts.session_title,
        si.session_date,
        ts.start_time,
        ts.end_time,
        ts.room_location,
        si.status,
        si.attendance_code,
        (SELECT COUNT(*) FROM attendance_records ar
         WHERE ar.session_instance_id = si.id AND ar.status = 'present') as present_count,
        (SELECT COUNT(*) FROM student_enrollments se 
         WHERE se.class_id = c.id AND se.status = 'enrolled') as total_students
    FROM session_instances si
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = :lecturer_id
    AND si.session_date BETWEEN :start_date AND :end_date
";

$params = [
    'lecturer_id' => $_SESSION['user_id'],
    'start_date' => $start_date,
    'end_date' => $end_date
];

// Add status filter
if ($status !== 'all') {
    $query .= " AND si.status = :status";
    $params['status'] = $status;
}

// Add class filter
if (!empty($class_id)) {
    $query .= " AND c.id = :class_id";
    $params['class_id'] = $class_id;
}

$query .= " ORDER BY si.session_date DESC, ts.start_time DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter dropdown
$stmt = $conn->prepare("
    SELECT c.id, CONCAT(c.class_code, ' - ', c.class_name) as class_name
    FROM classes c
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = :lecturer_id
    ORDER BY c.class_code
");
$stmt->execute(['lecturer_id' => $_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <div class="header-content">
        <h1>My Sessions</h1>
        <p>View and manage your class sessions</p>
    </div>
    <a href="create.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Session
    </a>
</div>

<!-- Session Filters -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Filters</h2>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label for="class_id" class="form-label">Class</label>
                <select name="class_id" id="class_id" class="form-select">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($class['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" 
                       value="<?= htmlspecialchars($start_date) ?>">
            </div>
            
            <div class="col-md-3">
                <label for="end_date" class="form-label">End Date</label>
                <div class="input-group">
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($end_date) ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Sessions List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2>Session List</h2>
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-outline-secondary" id="exportPdf">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <button type="button" class="btn btn-outline-success" id="exportExcel">
                <i class="fas fa-file-excel"></i> Excel
            </button>
        </div>
    </div>
    
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p>No sessions found matching your criteria</p>
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Session
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date & Time</th>
                            <th>Class</th>
                            <th>Session</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Attendance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): 
                            $isPast = strtotime($session['session_date'] . ' ' . $session['end_time']) < time();
                            $isNow = !$isPast && strtotime($session['session_date'] . ' ' . $session['start_time']) <= time();
                        ?>
                            <tr class="<?= $isNow ? 'table-primary' : '' ?>">
                                <td>
                                    <div class="fw-bold"><?= date('D, M j, Y', strtotime($session['session_date'])) ?></div>
                                    <div class="text-muted small">
                                        <?= date('g:i A', strtotime($session['start_time'])) ?> - 
                                        <?= date('g:i A', strtotime($session['end_time'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($session['class_code']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($session['class_name']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($session['session_title']) ?></td>
                                <td><?= htmlspecialchars($session['room_location']) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $session['status'] === 'completed' ? 'success' : 
                                        ($session['status'] === 'in_progress' ? 'primary' : 
                                        ($session['status'] === 'cancelled' ? 'danger' : 'secondary')) 
                                    ?>">
                                        <?= ucfirst(str_replace('_', ' ', $session['status'])) ?>
                                    </span>
                                    <?php if ($session['status'] === 'in_progress' && !empty($session['attendance_code'])): ?>
                                        <div class="mt-1">
                                            <small class="text-muted">Code: </small>
                                            <span class="badge bg-info"><?= htmlspecialchars($session['attendance_code']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($session['status'] !== 'scheduled'): ?>
                                        <div class="progress" style="height: 20px;">
                                            <?php 
                                            $percentage = $session['total_students'] > 0 
                                                ? round(($session['present_count'] / $session['total_students']) * 100) 
                                                : 0;
                                            ?>
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                 style="width: <?= $percentage ?>%" 
                                                 aria-valuenow="<?= $percentage ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?= $percentage ?>%
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?= $session['present_count'] ?> of <?= $session['total_students'] ?> students
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">Not started</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="view.php?id=<?= $session['instance_id'] ?>" 
                                           class="btn btn-outline-primary" 
                                           title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if ($session['status'] === 'scheduled'): ?>
                                            <a href="start.php?id=<?= $session['instance_id'] ?>" 
                                               class="btn btn-outline-success" 
                                               title="Start Session"
                                               onclick="return confirm('Start this session? Attendance will be available to students.')">
                                                <i class="fas fa-play"></i>
                                            </a>
                                        <?php elseif ($session['status'] === 'in_progress'): ?>
                                            <a href="end.php?id=<?= $session['instance_id'] ?>" 
                                               class="btn btn-outline-danger" 
                                               title="End Session"
                                               onclick="return confirm('End this session? Students will no longer be able to mark attendance.')">
                                                <i class="fas fa-stop"></i>
                                            </a>
                                            <a href="../attendance/take.php?session=<?= $session['instance_id'] ?>" 
                                               class="btn btn-success" 
                                               title="Take Attendance">
                                                <i class="fas fa-user-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($session['status'] === 'completed'): ?>
                                            <a href="../reports/session.php?id=<?= $session['instance_id'] ?>" 
                                               class="btn btn-outline-info" 
                                               title="View Report">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($sessions)): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-muted">
                Showing <?= count($sessions) ?> session(s)
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item disabled">
                        <a class="page-link" href="#" tabindex="-1" aria-disabled="true">Previous</a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item">
                        <a class="page-link" href="#">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Export to PDF
    document.getElementById('exportPdf').addEventListener('click', function() {
        const params = new URLSearchParams(window.location.search);
        window.open(`../api/export/sessions.php?format=pdf&${params.toString()}`, '_blank');
    });
    
    // Export to Excel
    document.getElementById('exportExcel').addEventListener('click', function() {
        const params = new URLSearchParams(window.location.search);
        window.open(`../api/export/sessions.php?format=excel&${params.toString()}`, '_blank');
    });
    
    // Auto-submit form when filters change
    document.querySelectorAll('#status, #class_id').forEach(select => {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
