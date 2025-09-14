<?php
// update_session_status.php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id = sanitize_input($_POST['session_id'] ?? '');
    $status = sanitize_input($_POST['status'] ?? '');
    
    if (!$session_id || !$status) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit();
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $current_time = date('Y-m-d H:i:s');
        $user_type = $_SESSION['user_type'] ?? '';

        if ($user_type === 'admin') {
            if ($status === 'in_progress') {
                $stmt = $conn->prepare("
                    UPDATE session_instances 
                    SET status = :status, actual_start_time = :start_time 
                    WHERE id = :session_id
                ");
                $stmt->execute([
                    'status' => $status,
                    'start_time' => $current_time,
                    'session_id' => $session_id
                ]);
            } elseif ($status === 'completed') {
                $stmt = $conn->prepare("
                    UPDATE session_instances 
                    SET status = :status, actual_end_time = :end_time 
                    WHERE id = :session_id
                ");
                $stmt->execute([
                    'status' => $status,
                    'end_time' => $current_time,
                    'session_id' => $session_id
                ]);
            }
        } elseif ($user_type === 'student') {
            // Students may only start (in_progress) sessions they are enrolled in, for today
            if ($status !== 'in_progress') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit();
            }

            // Verify the session belongs to a class the student is enrolled in and is for today
            $verify = $conn->prepare("
                SELECT si.id
                FROM session_instances si
                JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
                JOIN student_enrollments se ON se.class_id = ts.class_id AND se.status = 'enrolled'
                WHERE si.id = :sid AND si.session_date = CURDATE() AND se.student_id = :student_id
            ");
            $verify->execute(['sid' => $session_id, 'student_id' => $_SESSION['user_id']]);
            if (!$verify->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit();
            }

            $stmt = $conn->prepare("
                UPDATE session_instances 
                SET status = :status, actual_start_time = :start_time 
                WHERE id = :session_id
            ");
            $stmt->execute([
                'status' => 'in_progress',
                'start_time' => $current_time,
                'session_id' => $session_id
            ]);
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }
        
        echo json_encode(['success' => true]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>