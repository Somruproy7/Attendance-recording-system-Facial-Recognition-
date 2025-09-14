<?php
// check_attendance_status.php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$student_id = $_POST['student_id'] ?? '';
$session_instance_id = $_POST['session_instance_id'] ?? '';

if (empty($student_id) || empty($session_instance_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

// Verify student matches session user
if ($student_id != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Student ID mismatch']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if attendance has been marked
    $stmt = $conn->prepare('
        SELECT id, check_in_time, status 
        FROM attendance_records 
        WHERE student_id = :student_id AND session_instance_id = :session_id
    ');
    $stmt->execute([
        'student_id' => $student_id,
        'session_id' => $session_instance_id
    ]);
    
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($attendance) {
        echo json_encode([
            'success' => true,
            'attendance_marked' => true,
            'attendance_time' => $attendance['check_in_time'],
            'status' => $attendance['status']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'attendance_marked' => false
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
