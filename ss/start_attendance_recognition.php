<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$message = '';
$error = '';

// Handle form submission to start face recognition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_recognition'])) {
        try {
            // Change to ss directory and run the attendance face recognition
            $command = 'cd "' . __DIR__ . '" && python attendance_face_recognition.py 2>&1';
            
            // Start the process in background
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows
                $output = shell_exec('start /B ' . $command);
            } else {
                // Linux/Mac
                $output = shell_exec($command . ' &');
            }
            
            $message = "Face recognition for attendance started successfully!";
        } catch (Exception $e) {
            $error = "Error starting face recognition: " . $e->getMessage();
        }
    }
}

// Get recent attendance records
try {
    $stmt = $pdo->prepare("
        SELECT a.*, s.name, s.student_id as student_number
        FROM attendance a 
        JOIN students s ON a.student_id = s.id 
        WHERE DATE(a.attendance_date) = CURDATE()
        ORDER BY a.attendance_time DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $recent_attendance = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SS Face Recognition Attendance - FullAttend</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .recognition-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .control-panel {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .start-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 15px;
        }
        
        .start-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .instructions {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin-top: 20px;
        }
        
        .instructions h3 {
            color: #333;
            margin-bottom: 15px;
        }
        
        .instructions ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .instructions li {
            margin-bottom: 8px;
            color: #666;
        }
        
        .attendance-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .attendance-table h3 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin: 0;
            padding: 20px;
            font-size: 18px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9ff;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9ff;
        }
        
        .status-present {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .feature-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        
        .feature-card h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .feature-card p {
            color: #666;
            line-height: 1.6;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="recognition-container">
        <h1>SS Face Recognition Attendance System</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="control-panel">
            <h2>Start Face Recognition</h2>
            <p>Launch the enhanced face recognition system from the SS folder for attendance marking.</p>
            
            <form method="POST">
                <button type="submit" name="start_recognition" class="start-button">
                    🎥 Start Face Recognition
                </button>
                <a href="../admin_dashboard.php" class="start-button" style="background: #6c757d; text-decoration: none; display: inline-block;">
                    ← Back to Dashboard
                </a>
            </form>
            
            <div class="instructions">
                <h3>How to Use:</h3>
                <ul>
                    <li><strong>Camera Controls:</strong> Press 'C' to switch cameras, 'R' to refresh camera list</li>
                    <li><strong>Mark Attendance:</strong> Press 'A' when faces are detected to mark attendance</li>
                    <li><strong>View Info:</strong> Press 'I' to see camera information</li>
                    <li><strong>Exit:</strong> Press 'Q' to quit the application</li>
                    <li><strong>Cooldown:</strong> 30-second cooldown between attendance marks per student</li>
                </ul>
            </div>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <h4>🎯 Enhanced Accuracy</h4>
                <p>Uses advanced face detection with template matching for reliable student identification from the main system's profile photos.</p>
            </div>
            
            <div class="feature-card">
                <h4>📷 Multi-Camera Support</h4>
                <p>Automatically detects and supports multiple cameras including external USB webcams with easy switching.</p>
            </div>
            
            <div class="feature-card">
                <h4>🔄 Real-time Processing</h4>
                <p>Live face detection and matching with visual feedback showing student IDs and confidence scores.</p>
            </div>
            
            <div class="feature-card">
                <h4>🛡️ Smart Cooldown</h4>
                <p>Prevents duplicate attendance entries with a 30-second cooldown period per student.</p>
            </div>
        </div>
        
        <div class="attendance-table">
            <h3>Today's Attendance (Latest 10)</h3>
            <div class="table-container">
                <?php if (!empty($recent_attendance)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Time</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_attendance as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['student_number']); ?></td>
                                    <td><?php echo htmlspecialchars($record['name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['attendance_time']); ?></td>
                                    <td><?php echo htmlspecialchars($record['attendance_date']); ?></td>
                                    <td><span class="status-present"><?php echo htmlspecialchars($record['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #666;">
                        <p>No attendance records found for today.</p>
                        <p>Start the face recognition system to begin marking attendance.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
