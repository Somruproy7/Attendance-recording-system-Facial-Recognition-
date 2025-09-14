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
    SELECT si.id, si.status, c.class_name
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

// Check if session can be started
if ($session['status'] !== 'scheduled') {
    $_SESSION['error_message'] = 'This session cannot be started. It may have already started or been completed.';
    header('Location: view.php?id=' . $_GET['id']);
    exit();
}

try {
    $conn->beginTransaction();
    
    // Generate a random 6-character attendance code
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $attendanceCode = '';
    
    do {
        $attendanceCode = '';
        for ($i = 0; $i < 6; $i++) {
            $attendanceCode .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        // Check if code is unique
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM session_instances WHERE attendance_code = :code");
        $stmt->execute(['code' => $attendanceCode]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
    } while ($exists);
    
    // Update session status and set attendance code
    $stmt = $conn->prepare("
        UPDATE session_instances 
        SET status = 'in_progress', 
            attendance_code = :code,
            actual_start_time = NOW()
        WHERE id = :id
    ");
    
    $stmt->execute([
        'id' => $_GET['id'],
        'code' => $attendanceCode
    ]);
    
    // Log the session start
    $stmt = $conn->prepare("
        INSERT INTO session_logs 
        (session_instance_id, action, performed_by, details)
        VALUES (:session_id, 'session_started', :user_id, :details)
    ");
    
    $details = json_encode([
        'attendance_code' => $attendanceCode,
        'start_time' => date('Y-m-d H:i:s')
    ]);
    
    $stmt->execute([
        'session_id' => $_GET['id'],
        'user_id' => $_SESSION['user_id'],
        'details' => $details
    ]);
    
    $conn->commit();
    
    $_SESSION['success_message'] = 'Session started successfully. Attendance code: ' . $attendanceCode;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error_message'] = 'An error occurred while starting the session: ' . $e->getMessage();
}

header('Location: view.php?id=' . $_GET['id']);
exit();
