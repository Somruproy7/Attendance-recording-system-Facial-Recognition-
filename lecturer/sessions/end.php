<?php
session_start();
require_once '../../config/database.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = 'Invalid session ID';
    header('Location: index.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Verify the session exists and belongs to the lecturer
$stmt = $conn->prepare("
    SELECT si.id, si.status, c.class_name, si.attendance_code
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

// Check if session can be ended
if ($session['status'] !== 'in_progress') {
    $_SESSION['error_message'] = 'This session cannot be ended. It may have already been completed or cancelled.';
    header('Location: view.php?id=' . $_GET['id']);
    exit();
}

try {
    $conn->beginTransaction();
    
    // Update session status to completed
    $stmt = $conn->prepare("
        UPDATE session_instances 
        SET status = 'completed',
            actual_end_time = NOW()
        WHERE id = :id
    ");
    
    $stmt->execute(['id' => $_GET['id']]);
    
    // Log the session end
    $stmt = $conn->prepare("
        INSERT INTO session_logs 
        (session_instance_id, action, performed_by, details)
        VALUES (:session_id, 'session_ended', :user_id, :details)
    ");
    
    $details = json_encode([
        'end_time' => date('Y-m-d H:i:s'),
        'attendance_code' => $session['attendance_code']
    ]);
    
    $stmt->execute([
        'session_id' => $_GET['id'],
        'user_id' => $_SESSION['user_id'],
        'details' => $details
    ]);
    
    // Mark any remaining students as absent
    $stmt = $conn->prepare("
        INSERT INTO attendance_records 
        (session_instance_id, student_id, status, check_in_time, created_at)
        SELECT :session_id, se.student_id, 'absent', NULL, NOW()
        FROM student_enrollments se
        JOIN classes c ON se.class_id = c.id
        JOIN timetable_sessions ts ON c.id = ts.class_id
        JOIN session_instances si ON ts.id = si.timetable_session_id
        WHERE si.id = :session_id
        AND se.status = 'enrolled'
        AND se.student_id NOT IN (
            SELECT student_id 
            FROM attendance_records 
            WHERE session_instance_id = :session_id2
        )
        ON DUPLICATE KEY UPDATE status = 'absent'
    ");
    
    $stmt->execute([
        'session_id' => $_GET['id'],
        'session_id2' => $_GET['id']
    ]);
    
    $conn->commit();
    
    $_SESSION['success_message'] = 'Session ended successfully. Attendance has been finalized.';
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error_message'] = 'An error occurred while ending the session: ' . $e->getMessage();
}

header('Location: view.php?id=' . $_GET['id']);
exit();
