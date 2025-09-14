<?php
// ss/web_face_recognition.php
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
    // Validate session exists and student is enrolled
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare('
        SELECT si.id, si.status, ts.session_title, c.class_name, c.class_code,
               ts.start_time, ts.end_time
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
        header('Location: give_attendance.php?error=invalid_session');
        exit();
    }
    
    // Check if attendance already marked
    $stmt = $conn->prepare('
        SELECT id FROM attendance 
        WHERE student_id = :student_id AND session_instance_id = :session_id
    ');
    $stmt->execute([
        'student_id' => $student_id,
        'session_id' => $session_instance_id
    ]);
    
    $attendance_exists = $stmt->fetch();
    
} catch (PDOException $e) {
    header('Location: give_attendance.php?error=database_error');
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
    <link rel="stylesheet" href="../student/student.css">
    <style>
        .checkin-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .session-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .camera-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        #video {
            width: 100%;
            max-width: 480px;
            height: 360px;
            border-radius: 10px;
            background: #000;
            border: 3px solid #ddd;
            transition: border-color 0.3s ease;
        }
        
        #video.detecting {
            border-color: #ffc107;
            box-shadow: 0 0 20px rgba(255, 193, 7, 0.5);
        }
        
        #video.matched {
            border-color: #28a745;
            box-shadow: 0 0 20px rgba(40, 167, 69, 0.5);
        }
        
        .status-display {
            margin: 20px 0;
            padding: 15px;
            border-radius: 8px;
            font-weight: 600;
            text-align: center;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
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
        
        .controls {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 20px 0;
        }
        
        .btn-large {
            padding: 15px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
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
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .instructions {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin: 20px 0;
        }
        
        .instructions h4 {
            margin-top: 0;
            color: #333;
        }
        
        .instructions ul {
            margin: 10px 0 0 20px;
            color: #666;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 15px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .match-counter {
            font-size: 18px;
            font-weight: 600;
            margin: 10px 0;
        }
        
        .already-marked {
            background: #d1ecf1;
            color: #0c5460;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #bee5eb;
        }
    </style>
</head>
<body class="student-portal">
    <div class="checkin-container">
        <?php if ($attendance_exists): ?>
            <div class="already-marked">
                <h2>✓ Attendance Already Marked</h2>
                <p>You have already checked in for this session.</p>
                <a href="give_attendance.php" class="btn btn-primary">Back to Sessions</a>
            </div>
        <?php else: ?>
            <div class="session-info">
                <h2>Face Recognition Check-In</h2>
                <p><strong><?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></strong></p>
                <p><?php echo htmlspecialchars($session['session_title']); ?></p>
                <p><?php echo date('H:i', strtotime($session['start_time'])) . ' - ' . date('H:i', strtotime($session['end_time'])); ?></p>
            </div>
            
            <div class="camera-section">
                <video id="video" autoplay muted playsinline></video>
                
                <div id="status" class="status-display status-waiting">
                    Click "Start Camera" to begin face recognition check-in
                </div>
                
                <div id="progress-container" style="display: none;">
                    <div class="match-counter" id="match-counter">Matches: 0/3</div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill"></div>
                    </div>
                </div>
                
                <div class="controls">
                    <button id="startBtn" class="btn-large btn-primary" onclick="startCamera()">Start Camera</button>
                    <button id="checkBtn" class="btn-large btn-success" onclick="checkAttendance()" style="display: none;">Mark Attendance</button>
                    <button id="stopBtn" class="btn-large btn-secondary" onclick="stopCamera()" style="display: none;">Stop Camera</button>
                </div>
            </div>
            
            <div class="instructions">
                <h4>Instructions:</h4>
                <ul>
                    <li>Click "Start Camera" to begin</li>
                    <li>Position your face clearly in the camera view</li>
                    <li>The system will automatically detect and verify your face</li>
                    <li>Once verified 3 times consecutively, you can mark attendance</li>
                    <li>Your attendance will be recorded automatically</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="give_attendance.php" class="btn btn-secondary">← Back to Sessions</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const studentId = <?php echo json_encode($student_id); ?>;
        const sessionId = <?php echo json_encode($session_instance_id); ?>;
        let stream = null;
        let isProcessing = false;
        let consecutiveMatches = 0;
        const requiredMatches = 3;
        let processingInterval = null;
        
        const video = document.getElementById('video');
        const status = document.getElementById('status');
        const startBtn = document.getElementById('startBtn');
        const checkBtn = document.getElementById('checkBtn');
        const stopBtn = document.getElementById('stopBtn');
        const progressContainer = document.getElementById('progress-container');
        const matchCounter = document.getElementById('match-counter');
        const progressFill = document.getElementById('progress-fill');
        
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
                progressContainer.style.display = 'block';
                
                updateStatus('Camera started. Position your face in the frame...', 'waiting');
                
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
            checkBtn.style.display = 'none';
            progressContainer.style.display = 'none';
            
            consecutiveMatches = 0;
            updateProgress();
            updateStatus('Camera stopped. Click "Start Camera" to begin again.', 'waiting');
            video.className = '';
        }
        
        function startFaceDetection() {
            processingInterval = setInterval(async () => {
                if (!isProcessing && video.readyState >= 2) {
                    await processFrame();
                }
            }, 1000); // Check every second
        }
        
        async function processFrame() {
            if (isProcessing) return;
            isProcessing = true;
            
            try {
                // Capture frame from video
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0);
                
                const imageData = canvas.toDataURL('image/jpeg', 0.8);
                
                // Send to face recognition
                const response = await fetch('face_recognition_api.php', {
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
                
                if (result.success && result.matched) {
                    consecutiveMatches++;
                    updateStatus(`Face detected! Match ${consecutiveMatches}/${requiredMatches}`, 'detecting');
                    video.className = 'detecting';
                    
                    if (consecutiveMatches >= requiredMatches) {
                        updateStatus('Face verified! Ready to mark attendance.', 'success');
                        video.className = 'matched';
                        checkBtn.style.display = 'inline-block';
                        
                        // Auto-mark attendance after verification
                        setTimeout(() => {
                            if (consecutiveMatches >= requiredMatches) {
                                checkAttendance();
                            }
                        }, 1000);
                    }
                } else {
                    consecutiveMatches = 0;
                    updateStatus('Looking for your face...', 'waiting');
                    video.className = '';
                    checkBtn.style.display = 'none';
                }
                
                updateProgress();
                
            } catch (error) {
                console.error('Processing error:', error);
                updateStatus('Error processing image. Please try again.', 'error');
            }
            
            isProcessing = false;
        }
        
        async function checkAttendance() {
            if (consecutiveMatches < requiredMatches) {
                updateStatus('Please wait for face verification to complete.', 'error');
                return;
            }
            
            try {
                updateStatus('Marking attendance...', 'detecting');
                
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
                    updateStatus('✓ Attendance marked successfully!', 'success');
                    
                    // Stop camera and show success
                    if (processingInterval) {
                        clearInterval(processingInterval);
                    }
                    
                    checkBtn.style.display = 'none';
                    stopBtn.textContent = 'Close';
                    
                    // Redirect after 3 seconds
                    setTimeout(() => {
                        window.location.href = 'give_attendance.php?success=attendance_marked';
                    }, 3000);
                    
                } else {
                    updateStatus('Error: ' + (result.message || 'Failed to mark attendance'), 'error');
                }
                
            } catch (error) {
                console.error('Attendance error:', error);
                updateStatus('Error marking attendance. Please try again.', 'error');
            }
        }
        
        function updateStatus(message, type) {
            status.textContent = message;
            status.className = `status-display status-${type}`;
        }
        
        function updateProgress() {
            const percentage = (consecutiveMatches / requiredMatches) * 100;
            progressFill.style.width = percentage + '%';
            matchCounter.textContent = `Matches: ${consecutiveMatches}/${requiredMatches}`;
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', stopCamera);
    </script>
</body>
</html>
