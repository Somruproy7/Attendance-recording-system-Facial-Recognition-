<?php
session_start();
require_once '../../config/database.php';
require_once '../includes/header.php';

$db = new Database();
$conn = $db->getConnection();

// Get classes taught by this lecturer
$stmt = $conn->prepare("
    SELECT c.id, c.class_code, c.class_name 
    FROM classes c
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = :lecturer_id
    AND c.status = 'active'
    ORDER BY c.class_code
");

$stmt->execute(['lecturer_id' => $_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Locations will be entered as text input instead of selecting from a table

$errors = [];
$formData = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'class_id' => $_POST['class_id'] ?? '',
        'title' => trim($_POST['title'] ?? ''),
        'date' => $_POST['date'] ?? '',
        'start_time' => $_POST['start_time'] ?? '',
        'end_time' => $_POST['end_time'] ?? '',
        'room_location' => trim($_POST['room_location'] ?? ''),
        'notes' => trim($_POST['notes'] ?? '')
    ];
    
    // Validate inputs
    if (empty($formData['class_id']) || !is_numeric($formData['class_id'])) {
        $errors[] = 'Please select a valid class';
    }
    
    if (empty($formData['title'])) {
        $errors[] = 'Session title is required';
    }
    
    if (empty($formData['date']) || !strtotime($formData['date'])) {
        $errors[] = 'Please select a valid date';
    }
    
    if (empty($formData['start_time']) || empty($formData['end_time']) || 
        !preg_match('/^\d{2}:\d{2}$/', $formData['start_time']) || 
        !preg_match('/^\d{2}:\d{2}$/', $formData['end_time'])) {
        $errors[] = 'Please enter valid start and end times';
    } elseif (strtotime($formData['start_time']) >= strtotime($formData['end_time'])) {
        $errors[] = 'End time must be after start time';
    }
    
    if (empty($formData['room_location'])) {
        $errors[] = 'Please enter a room location';
    }
    
    // If no errors, proceed with database operations
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Check for overlapping sessions
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM session_instances si
                JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                WHERE ts.class_id = :class_id
                AND si.status != 'cancelled'
                AND si.session_date = :session_date
                AND ts.start_time < :end_time 
                AND ts.end_time > :start_time
            ");
            
            $stmt->execute([
                'class_id' => $formData['class_id'],
                'session_date' => $formData['date'],
                'start_time' => $formData['start_time'],
                'end_time' => $formData['end_time']
            ]);
            
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                throw new Exception('This session overlaps with an existing session for this class');
            }
            
            // Create timetable session
            $stmt = $conn->prepare("
                INSERT INTO timetable_sessions 
                (class_id, session_title, start_time, end_time, room_location, created_by)
                VALUES (:class_id, :title, :start_time, :end_time, :room_location, :user_id)
            ");
            
            $stmt->execute([
                'class_id' => $formData['class_id'],
                'title' => $formData['title'],
                'start_time' => $formData['start_time'],
                'end_time' => $formData['end_time'],
                'room_location' => $formData['room_location'],
                'user_id' => $_SESSION['user_id']
            ]);
            
            $timetableSessionId = $conn->lastInsertId();
            
            // Create session instance
            $stmt = $conn->prepare("
                INSERT INTO session_instances 
                (timetable_session_id, session_date, status, notes, created_by)
                VALUES (:timetable_id, :session_date, 'scheduled', :notes, :user_id)
            ");
            
            $stmt->execute([
                'timetable_id' => $timetableSessionId,
                'session_date' => $formData['date'],
                'notes' => $formData['notes'],
                'user_id' => $_SESSION['user_id']
            ]);
            
            $sessionId = $conn->lastInsertId();
            
            $conn->commit();
            
            $_SESSION['success_message'] = 'Session created successfully!';
            header('Location: view.php?id=' . $sessionId);
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Error creating session: ' . $e->getMessage();
        }
    }
}
?>

<div class="container mt-4">
    <div class="card">
        <div class="card-header">
            <h2>Create New Session</h2>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="class_id" class="form-label">Class</label>
                        <select class="form-select" id="class_id" name="class_id" required>
                            <option value="">Select a class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= ($formData['class_id'] ?? '') == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="title" class="form-label">Session Title</label>
                        <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($formData['title'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($formData['date'] ?? '') ?>" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="start_time" class="form-label">Start Time</label>
                        <input type="time" class="form-control" id="start_time" name="start_time" value="<?= htmlspecialchars($formData['start_time'] ?? '09:00') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="end_time" class="form-label">End Time</label>
                        <input type="time" class="form-control" id="end_time" name="end_time" value="<?= htmlspecialchars($formData['end_time'] ?? '10:00') ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="room_location" class="form-label">Room Location</label>
                    <input type="text" class="form-control" id="room_location" name="room_location" value="<?= htmlspecialchars($formData['room_location'] ?? '') ?>" placeholder="e.g., Main Building - Room 101" required>
                    <div class="form-text">Enter the building and room number for this session</div>
                </div>
                
                <div class="mb-3">
                    <label for="notes" class="form-label">Notes (Optional)</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?= 
                        htmlspecialchars($formData['notes'] ?? '') 
                    ?></textarea>
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Sessions
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set default time values if not set
    if (!document.getElementById('start_time').value) {
        const now = new Date();
        const hours = now.getHours().toString().padStart(2, '0');
        const minutes = Math.ceil(now.getMinutes() / 15) * 15; // Round to nearest 15 minutes
        const timeString = `${hours}:${minutes.toString().padStart(2, '0')}`;
        
        document.getElementById('start_time').value = timeString;
        
        // Set end time to 1 hour later
        const endTime = new Date(now.getTime() + 60 * 60 * 1000);
        const endHours = endTime.getHours().toString().padStart(2, '0');
        const endMinutes = endTime.getMinutes().toString().padStart(2, '0');
        document.getElementById('end_time').value = `${endHours}:${endMinutes}`;
    }
    
    // Set default date to today if not set
    if (!document.getElementById('date').value) {
        document.getElementById('date').valueAsDate = new Date();
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
