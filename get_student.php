<?php
// get_student.php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit();
}

$student_id = (int)$_GET['id'];

try {
    // Get student data with enrollment and attendance counts
    $stmt = $conn->prepare("
        SELECT u.*, 
               COUNT(DISTINCT se.class_id) as enrolled_classes,
               COUNT(DISTINCT ar.id) as total_attendance
        FROM users u
        LEFT JOIN student_enrollments se ON u.id = se.student_id AND se.status = 'enrolled'
        LEFT JOIN attendance_records ar ON u.id = ar.student_id
        WHERE u.id = :student_id AND u.user_type = 'student'
        GROUP BY u.id
    ");
    
    $stmt->execute(['student_id' => $student_id]);
    $student = $stmt->fetch();
    
    if ($student) {
        // Format the data for JSON response
        $response = [
            'success' => true,
            'student' => [
                'id' => $student['id'],
                'user_id' => $student['user_id'],
                'username' => $student['username'],
                'email' => $student['email'],
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'phone' => $student['phone'],
                'status' => $student['status'],
                'enrolled_classes' => $student['enrolled_classes'],
                'total_attendance' => $student['total_attendance'],
                'created_at' => $student['created_at'],
                'updated_at' => $student['updated_at']
            ]
        ];
        
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
