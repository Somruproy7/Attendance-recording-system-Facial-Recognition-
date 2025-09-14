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

// Get lecturer's classes with additional details
$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.class_code,
        c.class_name,
        c.semester,
        c.academic_year,
        c.description,
        c.status,
        COUNT(DISTINCT se.student_id) as enrolled_students,
        COUNT(DISTINCT si.id) as total_sessions,
        COUNT(DISTINCT CASE WHEN si.status = 'completed' THEN si.id END) as completed_sessions,
        COUNT(DISTINCT CASE WHEN si.status = 'in_progress' THEN si.id END) as active_sessions
    FROM classes c
    JOIN class_lecturers cl ON c.id = cl.class_id
    LEFT JOIN student_enrollments se ON c.id = se.class_id AND se.status = 'enrolled'
    LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
    LEFT JOIN session_instances si ON ts.id = si.timetable_session_id
    WHERE cl.lecturer_id = ?
    GROUP BY c.id, c.class_code, c.class_name, c.semester, c.academic_year, c.description, c.status
    ORDER BY c.academic_year DESC, c.semester, c.class_code
");
$stmt->execute([$lecturer_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$total_classes = count($classes);
$total_students = array_sum(array_column($classes, 'enrolled_students'));
$total_sessions = array_sum(array_column($classes, 'total_sessions'));
$active_classes = count(array_filter($classes, fn($c) => $c['status'] === 'active'));
?>

<div class="page-header">
    <div class="header-content">
        <h1>My Classes</h1>
        <p>Manage your assigned classes and view student enrollments</p>
    </div>
</div>

<!-- Summary Statistics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-0 bg-primary bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-primary mb-1"><?= $total_classes ?></div>
                <div class="text-muted">Total Classes</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-success bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-success mb-1"><?= $active_classes ?></div>
                <div class="text-muted">Active Classes</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-info bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-info mb-1"><?= $total_students ?></div>
                <div class="text-muted">Total Students</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-warning bg-opacity-10">
            <div class="card-body text-center">
                <div class="h2 text-warning mb-1"><?= $total_sessions ?></div>
                <div class="text-muted">Total Sessions</div>
            </div>
        </div>
    </div>
</div>

<!-- Classes Grid -->
<?php if (empty($classes)): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="fas fa-chalkboard-teacher"></i>
            <h4>No Classes Assigned</h4>
            <p>You don't have any classes assigned yet. Please contact your administrator.</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($classes as $class): ?>
        <div class="col-lg-6 mb-4">
            <div class="card h-100 class-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0"><?= htmlspecialchars($class['class_code']) ?></h5>
                        <small class="text-muted"><?= htmlspecialchars($class['academic_year']) ?> - Semester <?= $class['semester'] ?></small>
                    </div>
                    <span class="badge bg-<?= $class['status'] === 'active' ? 'success' : 'secondary' ?>">
                        <?= ucfirst($class['status']) ?>
                    </span>
                </div>
                
                <div class="card-body">
                    <h6 class="card-subtitle mb-2"><?= htmlspecialchars($class['class_name']) ?></h6>
                    
                    <?php if ($class['description']): ?>
                        <p class="card-text text-muted small">
                            <?= htmlspecialchars(substr($class['description'], 0, 120)) ?>
                            <?= strlen($class['description']) > 120 ? '...' : '' ?>
                        </p>
                    <?php endif; ?>
                    
                    <!-- Class Statistics -->
                    <div class="row text-center mb-3">
                        <div class="col-4">
                            <div class="fw-bold text-primary"><?= $class['enrolled_students'] ?></div>
                            <small class="text-muted">Students</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-info"><?= $class['total_sessions'] ?></div>
                            <small class="text-muted">Sessions</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-success"><?= $class['completed_sessions'] ?></div>
                            <small class="text-muted">Completed</small>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="btn-group w-100" role="group">
                        <a href="class_details.php?id=<?= $class['id'] ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <a href="sessions/?class_id=<?= $class['id'] ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-calendar"></i> Sessions
                        </a>
                        <a href="attendance_report.php?class_id=<?= $class['id'] ?>" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </div>
                </div>
                
                <?php if ($class['active_sessions'] > 0): ?>
                    <div class="card-footer bg-success bg-opacity-10 text-success">
                        <small>
                            <i class="fas fa-circle-dot me-1"></i>
                            <?= $class['active_sessions'] ?> session(s) currently in progress
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.class-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.class-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.btn-group .btn {
    font-size: 0.875rem;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6b7280;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.3;
}
</style>

<?php require_once 'includes/footer.php'; ?>
