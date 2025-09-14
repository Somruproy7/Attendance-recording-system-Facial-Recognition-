<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$lecturer_id = $_GET['lecturer_id'] ?? '';

if (!$lecturer_id || !is_numeric($lecturer_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid lecturer ID']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get assigned class IDs
    $stmt = $conn->prepare("SELECT class_id FROM class_lecturers WHERE lecturer_id = ?");
    $stmt->execute([(int)$lecturer_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'class_ids' => $assignments
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
