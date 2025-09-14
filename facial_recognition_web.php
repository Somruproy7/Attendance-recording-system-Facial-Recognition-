<?php
// facial_recognition_web.php
session_start();
require_once 'config/database.php';
require_once __DIR__ . '/ensure_today_sessions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'start_session':
                $session_instance_id = $_POST['session_instance_id'];
                
                // Update session status to in_progress
                $stmt = $conn->prepare("UPDATE session_instances SET status = 'in_progress', actual_start_time = NOW() WHERE id = :session_id");
                if ($stmt->execute(['session_id' => $session_instance_id])) {
                    $message = "Attendance session started! Facial recognition is now active.";
                } else {
                    $error = "Failed to start session.";
                }
                break;
                
            case 'stop_session':
                $session_instance_id = $_POST['session_instance_id'];
                
                // Update session status to completed
                $stmt = $conn->prepare("UPDATE session_instances SET status = 'completed', actual_end_time = NOW() WHERE id = :session_id");
                if ($stmt->execute(['session_id' => $session_instance_id])) {
                    $message = "Attendance session stopped.";
                } else {
                    $error = "Failed to stop session.";
                }
                break;
                
            case 'register_face':
                $student_id = $_POST['student_id'];
                $face_encoding = $_POST['face_encoding'];
                $image_path = $_POST['image_path'];
                
                // Check if face encoding already exists
                $stmt = $conn->prepare("SELECT id FROM student_face_encodings WHERE student_id = :student_id");
                $stmt->execute(['student_id' => $student_id]);
                
                if ($stmt->rowCount() > 0) {
                    // Update existing encoding
                    $stmt = $conn->prepare("
                        UPDATE student_face_encodings 
                        SET face_encoding = :face_encoding, image_path = :image_path, updated_at = NOW()
                        WHERE student_id = :student_id
                    ");
                } else {
                    // Insert new encoding
                    $stmt = $conn->prepare("
                        INSERT INTO student_face_encodings (student_id, face_encoding, image_path)
                        VALUES (:student_id, :face_encoding, :image_path)
                    ");
                }
                
                if ($stmt->execute([
                    'student_id' => $student_id,
                    'face_encoding' => $face_encoding,
                    'image_path' => $image_path
                ])) {
                    $message = "Face registered successfully!";
                } else {
                    $error = "Failed to register face.";
                }
                break;
        }
    }
}

// Make sure today's instances exist
ensure_todays_session_instances($conn);

// Get active sessions for today
$stmt = $conn->prepare("
    SELECT 
        si.id as instance_id,
        si.session_date,
        si.status as session_status,
        ts.session_title,
        ts.start_time,
        ts.end_time,
        ts.room_location,
        c.class_code,
        c.class_name,
        COUNT(ar.id) as attendance_count
    FROM session_instances si
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
    WHERE si.session_date = CURDATE()
    GROUP BY si.id, si.session_date, si.status, ts.session_title, ts.start_time, ts.end_time, ts.room_location, c.class_code, c.class_name
    ORDER BY ts.start_time
");
$stmt->execute();
$todays_sessions = $stmt->fetchAll();

// Get students with face encodings
$stmt = $conn->query("
    SELECT 
        u.id,
        u.user_id,
        u.first_name,
        u.last_name,
        sfe.face_encoding,
        sfe.image_path,
        sfe.created_at as face_registered
    FROM users u
    LEFT JOIN student_face_encodings sfe ON u.id = sfe.student_id
    WHERE u.user_type = 'student' AND u.status = 'active'
    ORDER BY u.last_name, u.first_name
");
$students = $stmt->fetchAll();

// Get recent attendance records
$stmt = $conn->query("
    SELECT 
        ar.check_in_time,
        u.first_name,
        u.last_name,
        u.user_id,
        c.class_code,
        ts.session_title,
        ar.status,
        ar.recognition_confidence
    FROM attendance_records ar
    JOIN users u ON ar.student_id = u.id
    JOIN session_instances si ON ar.session_instance_id = si.id
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    WHERE ar.check_in_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ORDER BY ar.check_in_time DESC
    LIMIT 20
");
$recent_attendance = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facial Recognition System - Full Attend</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .recognition-panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .video-container {
            position: relative;
            width: 100%;
            max-width: 640px;
            margin: 20px auto;
        }
        #video {
            width: 100%;
            border-radius: 8px;
        }
        .recognition-status {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 14px;
        }
        .session-controls {
            display: flex;
            gap: 10px;
            margin: 20px 0;
        }
        .attendance-log {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
        }
        .face-registration {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .student-face-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .student-face-card img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 10px;
        }
        .face-registered { border-color: green; }
        .face-not-registered { border-color: red; }
    </style>
</head>
<body>
    <div class="dashboard">
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="class_management.php">Class Management</a>
                <a href="student_registration.php">Student Management</a>
                <a href="timetable_management.php">Timetable</a>
                <a href="reports.php">Reports</a>
                <a href="settings.php">Settings</a>
                <a href="logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="images/fullattend_logo.png" alt="FullAttend Logo">
            </div>
        </aside>

        <main class="dashboard-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1>Facial Recognition System</h1>
                    <p class="muted">Manage attendance sessions and preview your camera</p>
                </div>
                <div class="toolbar">
                    <span class="pill">Today: <?php echo date('M d, Y'); ?></span>
                </div>
            </div>

        <?php if ($message): ?>
            <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Session Management -->
        <div class="recognition-panel">
            <h2>Today's Sessions</h2>
            <?php if (empty($todays_sessions)): ?>
                <p>No sessions scheduled for today.</p>
            <?php else: ?>
                <div class="session-list">
                    <?php foreach ($todays_sessions as $session): ?>
                        <div class="session-card">
                            <h3><?php echo htmlspecialchars($session['session_title']); ?></h3>
                            <p><strong>Class:</strong> <?php echo htmlspecialchars($session['class_code'] . ' - ' . $session['class_name']); ?></p>
                            <p><strong>Time:</strong> <?php echo date('H:i', strtotime($session['start_time'])) . ' - ' . date('H:i', strtotime($session['end_time'])); ?></p>
                            <p><strong>Room:</strong> <?php echo htmlspecialchars($session['room_location']); ?></p>
                            <p><strong>Status:</strong> 
                                <span class="status-<?php echo $session['session_status']; ?>">
                                    <?php echo ucfirst($session['session_status']); ?>
                                </span>
                            </p>
                            <p><strong>Attendance:</strong> <?php echo $session['attendance_count']; ?> students</p>
                            
                            <div class="session-controls">
                                <?php if ($session['session_status'] === 'scheduled'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="start_session">
                                        <input type="hidden" name="session_instance_id" value="<?php echo $session['instance_id']; ?>">
                                        <button type="submit" class="btn-primary">Start Attendance</button>
                                    </form>
                                <?php elseif ($session['session_status'] === 'in_progress'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="stop_session">
                                        <input type="hidden" name="session_instance_id" value="<?php echo $session['instance_id']; ?>">
                                        <button type="submit" class="btn-danger">Stop Attendance</button>
                                    </form>
                                    <button onclick="startRecognition(<?php echo $session['instance_id']; ?>)" class="btn-primary">Start Recognition</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Facial Recognition Interface -->
        <div class="recognition-panel">
            <h2>Facial Recognition</h2>
            <div class="video-container">
                <video id="video" autoplay muted playsinline></video>
                <div class="recognition-status" id="recognitionStatus">Ready</div>
            </div>
            
            <div class="session-controls">
                <button onclick="startCamera()" class="btn-primary">Start Camera</button>
                <button onclick="stopCamera()" class="btn-secondary">Stop Camera</button>
                <button onclick="startRecognition()" class="btn-primary">Start Recognition</button>
                <button onclick="stopRecognition()" class="btn-secondary">Stop Recognition</button>
            </div>
            
            <div class="attendance-log">
                <h3>Recent Attendance</h3>
                <?php if (empty($recent_attendance)): ?>
                    <p>No recent attendance records.</p>
                <?php else: ?>
                    <?php foreach ($recent_attendance as $record): ?>
                        <div class="attendance-item">
                            <strong><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></strong>
                            (<?php echo htmlspecialchars($record['user_id']); ?>) - 
                            <?php echo htmlspecialchars($record['class_code'] . ' - ' . $record['session_title']); ?>
                            - <?php echo date('H:i', strtotime($record['check_in_time'])); ?>
                            <span class="status-<?php echo $record['status']; ?>"><?php echo ucfirst($record['status']); ?></span>
                            <?php if ($record['recognition_confidence']): ?>
                                (<?php echo round($record['recognition_confidence'] * 100, 1); ?>% confidence)
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Face Registration Status -->
        <div class="recognition-panel">
            <h2>Face Registration Status</h2>
            <div class="face-registration">
                <?php foreach ($students as $student): ?>
                    <div class="student-face-card <?php echo $student['face_encoding'] ? 'face-registered' : 'face-not-registered'; ?>">
                        <?php if ($student['image_path']): ?>
                            <img src="<?php echo htmlspecialchars($student['image_path']); ?>" alt="Student Photo">
                        <?php else: ?>
                            <div style="width: 80px; height: 80px; background: #f0f0f0; border-radius: 50%; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 24px;">👤</span>
                            </div>
                        <?php endif; ?>
                        <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                        <p><?php echo htmlspecialchars($student['user_id']); ?></p>
                        <p>
                            <?php if ($student['face_encoding']): ?>
                                <span style="color: green;">✓ Face Registered</span><br>
                                <small><?php echo date('M d, Y', strtotime($student['face_registered'])); ?></small>
                            <?php else: ?>
                                <span style="color: red;">✗ Face Not Registered</span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Python Integration Instructions -->
        <div class="recognition-panel">
            <h2>Python Facial Recognition Integration</h2>
            <p>To use the facial recognition system:</p>
            <ol>
                <li>Make sure you have Python installed with the required packages (see requirements.txt)</li>
                <li>Run the facial recognition system: <code>python facial_recognition_system.py</code></li>
                <li>The Python application will connect to the same database and provide real-time facial recognition</li>
                <li>Attendance records will be automatically saved to the database</li>
            </ol>
            
            <h3>Required Python Packages:</h3>
            <ul>
                <li>opencv-python</li>
                <li>face-recognition</li>
                <li>mysql-connector-python</li>
                <li>numpy</li>
                <li>Pillow</li>
            </ul>
            
            <p><strong>Note:</strong> The web interface provides session management and monitoring. The actual facial recognition is handled by the Python application.</p>
        </main>
    </div>

    <script>
        let videoStream = null;
        let recognitionActive = false;
        
        async function startCamera() {
            try {
                const constraints = { video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }, audio: false };
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    throw new Error('Camera API not supported in this browser');
                }
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                const video = document.getElementById('video');
                video.srcObject = stream;
                await video.play().catch(()=>{});
                videoStream = stream;
                document.getElementById('recognitionStatus').textContent = 'Camera Active';
            } catch (err) {
                console.error('Error accessing camera:', err);
                alert('Unable to start camera. Please allow camera permissions and ensure no other app is using it.');
            }
        }
        
        function stopCamera() {
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                videoStream = null;
                document.getElementById('video').srcObject = null;
                document.getElementById('recognitionStatus').textContent = 'Camera Stopped';
            }
        }
        
        function startRecognition(sessionId = null) {
            if (!videoStream) {
                alert('Please start the camera first.');
                return;
            }
            
            recognitionActive = true;
            document.getElementById('recognitionStatus').textContent = 'Recognition Active';
            
            if (sessionId) {
                // Send session ID to Python application via WebSocket or API
                console.log('Starting recognition for session:', sessionId);
            }
            
            // In a real implementation, you would:
            // 1. Send video frames to the Python application
            // 2. Receive recognition results via WebSocket
            // 3. Update the attendance log in real-time
        }
        
        function stopRecognition() {
            recognitionActive = false;
            document.getElementById('recognitionStatus').textContent = 'Recognition Stopped';
        }
        
        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            stopCamera();
        });
    </script>
</body>
</html>
