<?php
session_start();
require_once '../../config/database.php';
require_once '../includes/header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Get session details
$stmt = $conn->prepare("
    SELECT 
        si.*,
        ts.session_title,
        ts.start_time,
        ts.end_time,
        ts.room_location,
        c.id as class_id,
        c.class_code,
        c.class_name,
        (SELECT COUNT(*) FROM attendance_records ar 
         WHERE ar.session_instance_id = si.id AND ar.status = 'present') as present_count,
        (SELECT COUNT(*) FROM student_enrollments se 
         WHERE se.class_id = c.id AND se.status = 'enrolled') as total_students
    FROM session_instances si
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE si.id = :id
    AND cl.lecturer_id = :lecturer_id
");

$stmt->execute([
    'id' => $_GET['id'],
    'lecturer_id' => $_SESSION['user_id']
]);

$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    $_SESSION['error_message'] = 'Session not found or access denied';
    header('Location: index.php');
    exit();
}

// Get attendance records for this session
$stmt = $conn->prepare("
    SELECT 
        ar.*,
        u.id as student_id,
        u.user_id as student_number,
        u.first_name,
        u.last_name,
        u.email,
        u.photo
    FROM attendance_records ar
    JOIN users u ON ar.student_id = u.id
    WHERE ar.session_instance_id = :session_id
    ORDER BY ar.status, u.last_name, u.first_name
");

$stmt->execute(['session_id' => $session['id']]);
$attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get session notes
$notes = $session['notes'] ?? 'No notes available for this session.';
?>

<div class="page-header">
    <div class="header-content">
        <h1>Session Details</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Sessions</a></li>
                <li class="breadcrumb-item active" aria-current="page">
                    <?= htmlspecialchars($session['session_title']) ?>
                </li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <?php if ($session['status'] === 'scheduled'): ?>
            <a href="start.php?id=<?= $session['id'] ?>" 
               class="btn btn-success"
               onclick="return confirm('Start this session? Attendance will be available to students.')">
                <i class="fas fa-play"></i> Start Session
            </a>
        <?php elseif ($session['status'] === 'in_progress'): ?>
            <a href="../attendance/take.php?session=<?= $session['id'] ?>" 
               class="btn btn-primary me-2">
                <i class="fas fa-user-check"></i> Take Attendance
            </a>
            <a href="end.php?id=<?= $session['id'] ?>" 
               class="btn btn-danger"
               onclick="return confirm('End this session? Students will no longer be able to mark attendance.')">
                <i class="fas fa-stop"></i> End Session
            </a>
        <?php endif; ?>
        <a href="edit.php?id=<?= $session['id'] ?>" class="btn btn-outline-secondary">
            <i class="fas fa-edit"></i> Edit
        </a>
    </div>
</div>

<div class="row">
    <!-- Session Details -->
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h2>Session Information</h2>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h5 class="text-muted">Class</h5>
                    <p class="mb-0">
                        <a href="../classes/view.php?id=<?= $session['class_id'] ?>">
                            <?= htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']) ?>
                        </a>
                    </p>
                </div>
                
                <div class="mb-3">
                    <h5 class="text-muted">Title</h5>
                    <p class="mb-0"><?= htmlspecialchars($session['session_title']) ?></p>
                </div>
                
                <div class="mb-3">
                    <h5 class="text-muted">Date & Time</h5>
                    <p class="mb-0">
                        <?= date('l, F j, Y', strtotime($session['session_date'])) ?><br>
                        <?= date('g:i A', strtotime($session['start_time'])) ?> - 
                        <?= date('g:i A', strtotime($session['end_time'])) ?>
                    </p>
                </div>
                
                <div class="mb-3">
                    <h5 class="text-muted">Location</h5>
                    <p class="mb-0"><?= htmlspecialchars($session['room_location']) ?></p>
                </div>
                
                <div class="mb-3">
                    <h5 class="text-muted">Status</h5>
                    <span class="badge bg-<?= 
                        $session['status'] === 'completed' ? 'success' : 
                        ($session['status'] === 'in_progress' ? 'primary' : 
                        ($session['status'] === 'cancelled' ? 'danger' : 'secondary')) 
                    ?>">
                        <?= ucfirst(str_replace('_', ' ', $session['status'])) ?>
                    </span>
                    
                    <?php if ($session['status'] === 'in_progress' && !empty($session['attendance_code'])): ?>
                        <div class="mt-2">
                            <h5 class="text-muted">Attendance Code</h5>
                            <div class="d-flex align-items-center">
                                <input type="text" class="form-control font-monospace fw-bold text-center" 
                                       value="<?= htmlspecialchars($session['attendance_code']) ?>" 
                                       id="attendanceCode" readonly>
                                <button class="btn btn-outline-secondary ms-2" onclick="copyAttendanceCode()">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <small class="text-muted">Share this code with students for attendance</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Session Notes -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2>Notes</h2>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editNotesModal">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
            <div class="card-body">
                <div class="session-notes">
                    <?= nl2br(htmlspecialchars($notes)) ?>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <h2>Quick Actions</h2>
            </div>
            <div class="list-group list-group-flush">
                <a href="../reports/session.php?id=<?= $session['id'] ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-chart-bar me-2"></i> View Attendance Report
                </a>
                <a href="../attendance/export.php?session_id=<?= $session['id'] ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-file-export me-2"></i> Export Attendance Data
                </a>
                <a href="duplicate.php?id=<?= $session['id'] ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-copy me-2"></i> Duplicate Session
                </a>
                <?php if ($session['status'] === 'scheduled'): ?>
                    <a href="cancel.php?id=<?= $session['id'] ?>" 
                       class="list-group-item list-group-item-action text-danger"
                       onclick="return confirm('Are you sure you want to cancel this session? This cannot be undone.')">
                        <i class="fas fa-times-circle me-2"></i> Cancel Session
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Attendance Summary -->
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2>Attendance Summary</h2>
                <div>
                    <span class="badge bg-success me-2">
                        <?= $session['present_count'] ?> Present
                    </span>
                    <span class="badge bg-danger">
                        <?= $session['total_students'] - $session['present_count'] ?> Absent
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="attendance-stats mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Attendance Rate</span>
                        <span class="fw-bold">
                            <?= $session['total_students'] > 0 
                                ? round(($session['present_count'] / $session['total_students']) * 100) 
                                : 0 
                            ?>%
                        </span>
                    </div>
                    <div class="progress" style="height: 20px;">
                        <?php 
                        $percentage = $session['total_students'] > 0 
                            ? ($session['present_count'] / $session['total_students']) * 100 
                            : 0;
                        ?>
                        <div class="progress-bar bg-success" role="progressbar" 
                             style="width: <?= $percentage ?>%" 
                             aria-valuenow="<?= $percentage ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Status</th>
                                <th>Check-in Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendance)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <div class="text-muted">No attendance records found</div>
                                        <?php if ($session['status'] === 'in_progress'): ?>
                                            <a href="../attendance/take.php?session=<?= $session['id'] ?>" class="btn btn-primary mt-2">
                                                <i class="fas fa-user-check"></i> Take Attendance
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendance as $record): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="student-avatar me-2">
                                                    <?php if (!empty($record['photo'])): ?>
                                                        <img src="<?= htmlspecialchars($record['photo']) ?>" 
                                                             alt="<?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?>"
                                                             class="rounded-circle" width="32">
                                                    <?php else: ?>
                                                        <div class="avatar-placeholder">
                                                            <?= strtoupper(substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold">
                                                        <?= htmlspecialchars($record['last_name'] . ', ' . $record['first_name']) ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars($record['student_number']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $record['status'] === 'present' ? 'success' : 
                                                ($record['status'] === 'late' ? 'warning' : 'danger') 
                                            ?>">
                                                <?= ucfirst($record['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $record['check_in_time'] 
                                                ? date('M j, g:i A', strtotime($record['check_in_time'])) 
                                                : '-' 
                                            ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" 
                                                        class="btn btn-outline-primary"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editAttendanceModal"
                                                        data-student-id="<?= $record['student_id'] ?>"
                                                        data-student-name="<?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?>"
                                                        data-status="<?= $record['status'] ?>"
                                                        data-notes="<?= htmlspecialchars($record['notes'] ?? '') ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Notes Modal -->
<div class="modal fade" id="editNotesModal" tabindex="-1" aria-labelledby="editNotesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="update_notes.php" method="post">
                <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="editNotesModalLabel">Edit Session Notes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="8"><?= htmlspecialchars($notes) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Attendance Modal -->
<div class="modal fade" id="editAttendanceModal" tabindex="-1" aria-labelledby="editAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="../attendance/update.php" method="post">
                <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                <input type="hidden" name="student_id" id="editStudentId">
                <div class="modal-header">
                    <h5 class="modal-title" id="editAttendanceModalLabel">Update Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Student</label>
                        <p class="form-control-static fw-bold" id="studentName"></p>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle edit attendance modal
    const editAttendanceModal = document.getElementById('editAttendanceModal');
    if (editAttendanceModal) {
        editAttendanceModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const studentId = button.getAttribute('data-student-id');
            const studentName = button.getAttribute('data-student-name');
            const status = button.getAttribute('data-status');
            const notes = button.getAttribute('data-notes');
            
            document.getElementById('editStudentId').value = studentId;
            document.getElementById('studentName').textContent = studentName;
            document.getElementById('status').value = status;
            document.getElementById('notes').value = notes || '';
        });
    }
    
    // Handle form submission
    const forms = document.querySelectorAll('form[data-ajax="true"]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(form);
            
            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Changes saved successfully', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message || 'An error occurred', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred while saving changes', 'error');
            });
        });
    });
});

function copyAttendanceCode() {
    const copyText = document.getElementById("attendanceCode");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand("copy");
    
    const tooltip = new bootstrap.Tooltip(copyText, {
        title: 'Copied!',
        trigger: 'manual'
    });
    
    tooltip.show();
    setTimeout(() => tooltip.hide(), 1000);
}
</script>

<?php require_once '../includes/footer.php'; ?>
