<?php
// ss/mark_attendance_api.php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$student_id = $input['student_id'] ?? '';
$session_instance_id = $input['session_instance_id'] ?? '';

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
    
    // Validate session exists, student is enrolled, and session is active and started by lecturer
    $current_time = date('H:i:s');
    $stmt = $conn->prepare('
        SELECT 
            si.id, 
            si.status, 
            ts.session_title, 
            c.class_name,
            ts.start_time,
            ts.end_time,
            ADDTIME(TIME(ts.end_time), "00:15:00") as grace_period_end,
            (SELECT COUNT(*) FROM attendance_records ar 
             WHERE ar.session_instance_id = si.id 
             AND ar.attendance_type = 'lecturer_start') as is_started_by_lecturer
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN student_enrollments se ON se.class_id = c.id
        WHERE si.id = :session_id 
          AND se.student_id = :student_id 
          AND se.status = "enrolled"
          AND si.status = "in_progress"
          AND CURDATE() = si.session_date
          AND :current_time <= ADDTIME(TIME(ts.end_time), "00:15:00")
    ');
    $stmt->execute([
        'session_id' => $session_instance_id,
        'student_id' => $student_id,
        'current_time' => $current_time
    ]);
    
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        // Check if session exists but has ended
        $check_ended = $conn->prepare('
            SELECT 1 
            FROM session_instances si
            JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
            WHERE si.id = :session_id
            AND CURDATE() = si.session_date
            AND :current_time > ADDTIME(TIME(ts.end_time), "00:15:00")
        ');
        $check_ended->execute([
            'session_id' => $session_instance_id,
            'current_time' => $current_time
        ]);
        
        if ($check_ended->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This session has already ended.']);
        } else {
            // Check if session exists but not started by lecturer
            $check_not_started = $conn->prepare('
                SELECT 1 
                FROM session_instances si
                WHERE si.id = :session_id
                AND si.status = "scheduled"
                AND CURDATE() = si.session_date
            ');
            $check_not_started->execute(['session_id' => $session_instance_id]);
            
            if ($check_not_started->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Attendance cannot be marked until the lecturer starts the session.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid session or not enrolled']);
            }
        }
        exit();
    }
    
    // Check if session has been started by lecturer
    if (!$session['is_started_by_lecturer']) {
        echo json_encode(['success' => false, 'message' => 'Attendance cannot be marked until the lecturer starts the session.']);
        exit();
    }
    
    // Check if attendance already marked
    $stmt = $conn->prepare('
        SELECT id FROM attendance_records 
        WHERE student_id = :student_id AND session_instance_id = :session_id
    ');
    $stmt->execute([
        'student_id' => $student_id,
        'session_id' => $session_instance_id
    ]);
    
    $existing_record = $stmt->fetch();
    
    if ($existing_record) {
        echo json_encode(['success' => false, 'message' => 'Attendance already marked for this session']);
        exit();
    }
    
    // Insert attendance record
    $stmt = $conn->prepare('
        INSERT INTO attendance_records (student_id, session_instance_id, status, check_in_time, recognition_confidence)
        VALUES (:student_id, :session_id, :status, NOW(), 0.95)
    ');
    
    $result = $stmt->execute([
        'student_id' => $student_id,
        'session_id' => $session_instance_id,
        'status' => 'present'
    ]);
    
    if ($result) {
        // Update session status to in_progress if needed
        $stmt = $conn->prepare('
            UPDATE session_instances 
            SET status = :status 
            WHERE id = :session_id AND status = :old_status
        ');
        $stmt->execute([
            'status' => 'in_progress',
            'session_id' => $session_instance_id,
            'old_status' => 'scheduled'
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Attendance marked successfully',
            'time' => $current_time,
            'date' => $current_date
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to mark attendance']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
