<?php
// start_face_recognition.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$student_id = $_SESSION['user_id'];
$session_instance_id = isset($_POST['session_instance_id']) ? intval($_POST['session_instance_id']) : null;

// Validate that the student is enrolled in this session
if ($session_instance_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare('
        SELECT si.id 
        FROM student_enrollments se
        JOIN classes c ON se.class_id = c.id
        JOIN timetable_sessions ts ON ts.class_id = c.id
        JOIN session_instances si ON si.timetable_session_id = ts.id
        WHERE se.student_id = :sid 
          AND si.id = :session_id
          AND se.status = "enrolled" 
          AND c.status = "active"
          AND si.status IN ("scheduled", "in_progress")
    ');
    $stmt->execute(['sid' => $student_id, 'session_id' => $session_instance_id]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Not enrolled in this session']);
        exit();
    }
}

// Get the path to Python executable (check for virtual environment first)
$venvPython = __DIR__ . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$python_path = file_exists($venvPython) ? $venvPython : 'python';
$script_path = __DIR__ . '/face_recognition_attendance.py';

// Build command
$command = escapeshellcmd($python_path) . ' "' . $script_path . '"';
if ($session_instance_id) {
    $command .= ' ' . $session_instance_id;
}

// Start the face recognition process in the background
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows
    $command = 'start /B ' . $command;
    pclose(popen($command, 'r'));
} else {
    // Linux/Mac
    $command .= ' > /dev/null 2>&1 &';
    exec($command);
}

echo json_encode([
    'success' => true, 
    'message' => 'Face recognition started. Look at the camera window for attendance marking.',
    'session_id' => $session_instance_id
]);
?>
