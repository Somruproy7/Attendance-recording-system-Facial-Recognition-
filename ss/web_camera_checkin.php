<?php
// ss/web_camera_checkin.php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$session_instance_id = $_GET['session_id'] ?? '';

if (empty($session_instance_id)) {
    header('Location: give_attendance.php');
    exit();
}

try {
    // Validate session
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare('
        SELECT si.id, si.status, ts.session_title, c.class_name, c.class_code
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN student_enrollments se ON se.class_id = c.id
        WHERE si.id = :session_id 
          AND se.student_id = :student_id 
          AND se.status = "enrolled"
          AND si.status = "in_progress"
    ');
    $stmt->execute([
        'session_id' => $session_instance_id,
        'student_id' => $student_id
    ]);
    
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        header('Location: ../student/give_attendance.php?error=invalid_session');
        exit();
    }
    
} catch (PDOException $e) {
    header('Location: ../student/give_attendance.php?error=database_error');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Recognition Check-In - FullAttend</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .camera-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 600px;
            width: 100%;
        }
        
        .session-header {
            margin-bottom: 30px;
        }
        
        .session-header h1 {
            color: #333;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        
        .session-info {
            background: #f8f9ff;
            padding: 15px;
            border-radius: 8px;
            color: #666;
            margin-bottom: 20px;
        }
        
        #video {
            width: 100%;
            max-width: 480px;
            height: 360px;
            border-radius: 15px;
            background: #000;
            border: 4px solid #ddd;
            transition: all 0.3s ease;
        }
        
        #video.detecting {
            border-color: #ffc107;
            box-shadow: 0 0 20px rgba(255, 193, 7, 0.5);
        }
        
        #video.matched {
            border-color: #28a745;
            box-shadow: 0 0 20px rgba(40, 167, 69, 0.5);
        }
        
        .status-container {
            margin: 25px 0;
        }
        
        .status-display {
            padding: 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .status-waiting {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }
        
        .status-detecting {
            background: #fff3cd;
            color: #856404;
            border: 2px solid #ffc107;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        
        .status-error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #dc3545;
        }
        
        .match-progress {
            display: none;
            margin: 20px 0;
        }
        
        .progress-bar {
            width: 100%;
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            width: 0%;
            transition: width 0.5s ease;
        }
        
        .match-counter {
            font-size: 20px;
            font-weight: 700;
            color: #333;
        }
        
        .controls {
            margin: 25px 0;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .instructions {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
            margin: 25px 0;
            text-align: left;
        }
        
        .instructions h4 {
            margin-top: 0;
            color: #333;
        }
        
        .instructions ul {
            margin: 10px 0 0 20px;
            color: #666;
        }
        
        .success-animation {
            display: none;
            font-size: 48px;
            margin: 20px 0;
            animation: bounce 1s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
    </style>
</head>
<body>
    <div class="camera-container">
        <div class="session-header">
            <h1>🎥 Face Recognition Check-In</h1>
            <div class="session-info">
                <strong><?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></strong><br>
                <?php echo htmlspecialchars($session['session_title']); ?>
            </div>
        </div>
        
        <video id="video" autoplay muted playsinline></video>
        
        <div class="status-container">
            <div id="status" class="status-display status-waiting">
                Click "Start Camera" to begin face recognition
            </div>
            
            <div id="match-progress" class="match-progress">
                <div class="match-counter" id="match-counter">Matches: 0/5 (Need 1)</div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
            </div>
            
            <div id="success-animation" class="success-animation">🎉</div>
        </div>
        
        <div class="controls">
            <button id="startBtn" class="btn btn-primary" onclick="startCamera()">Start Camera</button>
            <button id="stopBtn" class="btn btn-secondary" onclick="stopCamera()" style="display: none;">Stop Camera</button>
            <a href="../student/give_attendance.php" class="btn btn-secondary">← Back to Sessions</a>
        </div>
        
        <div class="instructions">
            <h4>Instructions:</h4>
            <ul>
                <li>Click "Start Camera" to begin</li>
                <li>Position your face clearly in the camera view</li>
                <li>Need 1 match out of 5 attempts</li>
                <li>Attendance will be marked automatically</li>
                <li>Keep the camera steady for best results</li>
            </ul>
        </div>
    </div>

    <script>
        const studentId = <?php echo json_encode($student_id); ?>;
        const sessionId = <?php echo json_encode($session_instance_id); ?>;
        let stream = null;
        let isProcessing = false;
        let totalMatches = 0;
        let totalAttempts = 0;
        const maxAttempts = 5;
        const requiredMatches = 1;
        let processingInterval = null;
        let attendanceMarked = false;
        
        const video = document.getElementById('video');
        const status = document.getElementById('status');
        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const matchProgress = document.getElementById('match-progress');
        const matchCounter = document.getElementById('match-counter');
        const progressFill = document.getElementById('progress-fill');
        const successAnimation = document.getElementById('success-animation');
        
        async function startCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 640 }, 
                        height: { ideal: 480 },
                        facingMode: 'user'
                    } 
                });
                
                video.srcObject = stream;
                await video.play();
                
                startBtn.style.display = 'none';
                stopBtn.style.display = 'inline-block';
                matchProgress.style.display = 'block';
                
                updateStatus('Camera started. Looking for your face...', 'waiting');
                
                // Start face detection
                startFaceDetection();
                
            } catch (error) {
                console.error('Camera error:', error);
                updateStatus('Error accessing camera. Please allow camera permissions.', 'error');
            }
        }
        
        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            if (processingInterval) {
                clearInterval(processingInterval);
                processingInterval = null;
            }
            
            video.srcObject = null;
            startBtn.style.display = 'inline-block';
            stopBtn.style.display = 'none';
            matchProgress.style.display = 'none';
            
            totalMatches = 0;
            totalAttempts = 0;
            updateProgress();
            updateStatus('Camera stopped. Click "Start Camera" to begin again.', 'waiting');
            video.className = '';
        }
        
        function startFaceDetection() {
            processingInterval = setInterval(async () => {
                if (!isProcessing && video.readyState >= 2 && !attendanceMarked) {
                    await processFrame();
                }
            }, 2000); // Check every 2 seconds for better stability
        }
        
        async function processFrame() {
            if (isProcessing || attendanceMarked) return;
            isProcessing = true;
            
            try {
                // Capture frame from video
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0);
                
                const imageData = canvas.toDataURL('image/jpeg', 0.8);
                
                // Send to face recognition API
                const response = await fetch('simple_face_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        image: imageData,
                        student_id: studentId
                    })
                });
                
                const result = await response.json();
                console.log('Face recognition result:', result);
                
                totalAttempts++;
                
                if (result.success && result.matched) {
                    totalMatches++;
                    video.className = 'matched';
                    
                    // Show success message and update counter immediately
                    updateStatus(`✅ Face match found! (${totalMatches} matches found)`, 'success');
                    updateProgress(); // Update counter to show new match count
                    
                    // Brief animation pause to show the success
                    setTimeout(() => {
                        if (totalMatches >= requiredMatches) {
                            updateStatus('Face verified! Marking attendance...', 'success');
                            successAnimation.style.display = 'block';
                            
                            // Mark attendance
                            markAttendance();
                        } else {
                            updateStatus(`Keep looking... Need ${requiredMatches - totalMatches} more match(es)`, 'detecting');
                            video.className = 'detecting';
                        }
                    }, 1500); // Show success for 1.5 seconds
                    
                } else {
                    updateStatus(`Looking for your face... (Attempt ${totalAttempts}/${maxAttempts})`, 'waiting');
                    video.className = '';
                    updateProgress();
                    
                    // Stop after max attempts if no match found
                    if (totalAttempts >= maxAttempts && totalMatches === 0) {
                        updateStatus('No face match found after 5 attempts. Please try again.', 'error');
                        stopCamera();
                        return;
                    }
                }
                
            } catch (error) {
                console.error('Processing error:', error);
                updateStatus('Error processing image. Please try again.', 'error');
            }
            
            isProcessing = false;
        }
        
        async function markAttendance() {
            if (attendanceMarked) return;
            
            try {
                const response = await fetch('mark_attendance_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        student_id: studentId,
                        session_instance_id: sessionId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    attendanceMarked = true;
                    updateStatus('✅ Attendance marked successfully!', 'success');
                    
                    // Stop processing
                    if (processingInterval) {
                        clearInterval(processingInterval);
                    }
                    
                    stopBtn.textContent = 'Close';
                    
                    // Update the button immediately
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'attendanceMarked',
                            sessionId: sessionId
                        }, window.location.origin);
                    }
                    
                    // Redirect after 2 seconds
                    setTimeout(() => {
                        window.location.href = '../student/give_attendance.php?success=attendance_marked';
                    }, 2000);
                    
                } else {
                    updateStatus('Error: ' + (result.message || 'Failed to mark attendance'), 'error');
                    totalMatches = 0;
                    totalAttempts = 0;
                    updateProgress();
                }
                
            } catch (error) {
                console.error('Attendance error:', error);
                updateStatus('Error marking attendance. Please try again.', 'error');
                totalMatches = 0;
                totalAttempts = 0;
                updateProgress();
            }
        }
        
        function updateStatus(message, type) {
            status.textContent = message;
            status.className = `status-display status-${type}`;
        }
        
        function updateProgress() {
            // Progress bar should show attempts made
            const percentage = (totalAttempts / maxAttempts) * 100;
            progressFill.style.width = percentage + '%';
            
            // Counter should show matches found out of total attempts
            matchCounter.textContent = `Matches: ${totalMatches}/${totalAttempts} (Need ${requiredMatches})`;
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', stopCamera);
    </script>
</body>
</html>