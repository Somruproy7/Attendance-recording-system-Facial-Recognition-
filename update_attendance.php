<?php
// update_attendance.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$session_instance_id = $_POST['session_instance_id'] ?? null;
$status = $_POST['status'] ?? 'present';
$student_id = $_SESSION['user_type'] === 'student' ? $_SESSION['user_id'] : ($_POST['student_id'] ?? null);

if (!$session_instance_id || !$student_id) {
    http_response_code(400);
    echo 'Missing parameters';
    exit();
}

try {
    // Insert or update attendance record
    $stmt = $conn->prepare('SELECT id FROM attendance_records WHERE session_instance_id = :sid AND student_id = :uid');
    $stmt->execute(['sid' => $session_instance_id, 'uid' => $student_id]);
    $existing = $stmt->fetch();
    if ($existing) {
        $stmt = $conn->prepare('UPDATE attendance_records SET status = :status, updated_at = NOW(), marked_by = :by WHERE id = :id');
        $stmt->execute(['status' => $status, 'by' => $_SESSION['user_type'], 'id' => $existing['id']]);
    } else {
        $stmt = $conn->prepare('INSERT INTO attendance_records (session_instance_id, student_id, status, check_in_time, marked_by) VALUES (:sid, :uid, :status, NOW(), :by)');
        $stmt->execute(['sid' => $session_instance_id, 'uid' => $student_id, 'status' => $status, 'by' => $_SESSION['user_type']]);
    }
    echo 'OK';
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}


