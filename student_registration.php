<?php
// student_registration.php
session_start();
require_once 'config/database.php';

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
            case 'add_student':
                $user_id = sanitize_input($_POST['user_id']);
                $username = sanitize_input($_POST['username']);
                $email = sanitize_input($_POST['email']);
                $first_name = sanitize_input($_POST['first_name']);
                $last_name = sanitize_input($_POST['last_name']);
                $phone = sanitize_input($_POST['phone']);
                $password = $_POST['password'];
                
                // Check if user already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE user_id = :user_id OR username = :username OR email = :email");
                $stmt->execute(['user_id' => $user_id, 'username' => $username, 'email' => $email]);
                
                if ($stmt->rowCount() > 0) {
                    $error = "Student with this ID, username, or email already exists.";
                } else {
                    $password_hash = hash_password($password);
                    
                    $stmt = $conn->prepare("
                        INSERT INTO users (user_id, username, email, password_hash, user_type, first_name, last_name, phone, status)
                        VALUES (:user_id, :username, :email, :password_hash, 'student', :first_name, :last_name, :phone, 'active')
                    ");
                    
                    if ($stmt->execute([
                        'user_id' => $user_id,
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => $password_hash,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'phone' => $phone
                    ])) {
                        // Redirect back if the request came from Student Directory modal
                        if (!empty($_POST['redirect_back'])) {
                            header('Location: ' . $_POST['redirect_back'] . '?msg=' . urlencode('Student registered successfully!'));
                            exit();
                        }
                        $message = "Student registered successfully!";
                    } else {
                        $error = "Failed to register student.";
                    }
                }
                break;
                
            case 'enroll_student':
                $student_id = $_POST['student_id'];
                $class_id = $_POST['class_id'];
                
                // Check if already enrolled
                $stmt = $conn->prepare("SELECT id FROM student_enrollments WHERE student_id = :student_id AND class_id = :class_id");
                $stmt->execute(['student_id' => $student_id, 'class_id' => $class_id]);
                
                if ($stmt->rowCount() > 0) {
                    $error = "Student is already enrolled in this class.";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO student_enrollments (student_id, class_id, status)
                        VALUES (:student_id, :class_id, 'enrolled')
                    ");
                    
                    if ($stmt->execute(['student_id' => $student_id, 'class_id' => $class_id])) {
                        $message = "Student enrolled successfully!";
                    } else {
                        $error = "Failed to enroll student.";
                    }
                }
                break;
                
            case 'update_status':
                $user_id = $_POST['user_id'];
                $status = $_POST['status'];
                
                $stmt = $conn->prepare("UPDATE users SET status = :status WHERE id = :user_id");
                if ($stmt->execute(['status' => $status, 'user_id' => $user_id])) {
                    $message = "Student status updated successfully!";
                } else {
                    $error = "Failed to update student status.";
                }
                break;
                
            case 'edit_student':
                $student_id = $_POST['student_id'];
                $user_id = sanitize_input($_POST['user_id']);
                $username = sanitize_input($_POST['username']);
                $email = sanitize_input($_POST['email']);
                $first_name = sanitize_input($_POST['first_name']);
                $last_name = sanitize_input($_POST['last_name']);
                $phone = sanitize_input($_POST['phone']);
                $status = sanitize_input($_POST['status']);
                
                // Check if user_id, username, or email already exists for other students
                $stmt = $conn->prepare("SELECT id FROM users WHERE (user_id = :user_id OR username = :username OR email = :email) AND id != :student_id");
                $stmt->execute(['user_id' => $user_id, 'username' => $username, 'email' => $email, 'student_id' => $student_id]);
                
                if ($stmt->rowCount() > 0) {
                    $error = "Student ID, username, or email already exists for another student.";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE users 
                        SET user_id = :user_id, username = :username, email = :email, 
                            first_name = :first_name, last_name = :last_name, 
                            phone = :phone, status = :status, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :student_id AND user_type = 'student'
                    ");
                    
                    if ($stmt->execute([
                        'user_id' => $user_id,
                        'username' => $username,
                        'email' => $email,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'phone' => $phone,
                        'status' => $status,
                        'student_id' => $student_id
                    ])) {
                        $message = "Student updated successfully!";
                    } else {
                        $error = "Failed to update student.";
                    }
                }
                break;
        }
    }
}

// Get all students
$stmt = $conn->query("
    SELECT u.*, 
           COUNT(DISTINCT se.class_id) as enrolled_classes,
           COUNT(DISTINCT ar.id) as total_attendance
    FROM users u
    LEFT JOIN student_enrollments se ON u.id = se.student_id AND se.status = 'enrolled'
    LEFT JOIN attendance_records ar ON u.id = ar.student_id
    WHERE u.user_type = 'student'
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
");
$students = $stmt->fetchAll();

// Get all classes for enrollment
$stmt = $conn->query("SELECT id, class_code, class_name FROM classes WHERE status = 'active' ORDER BY class_code");
$classes = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - Full Attend</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="student.css?v=<?php echo time(); ?>">
</head>
<body class="admin-portal">
    <div class="dashboard">
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="class_management.php">Class Management</a>
                <a href="student_directory.php">Student Directory</a>
                <a href="student_registration.php" class="active">Student Management</a>
                <a href="admin/lecturer_management.php">Lecturer Management</a>
                <a href="timetable_management.php">Timetable</a>
                <a href="reports.php">Reports</a>
                <a href="settings.php">Settings</a>
                <a href="logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="images/fullattend_logo.png" alt="FullAttend Logo" />
            </div>
        </aside>

        <main class="dashboard-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1>Student Management</h1>
                    <p class="muted">Register, enroll, and manage students</p>
                </div>
            </div>

        <?php if ($message): ?>
            <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Add New Student -->
        <div class="form-section">
            <h2>Register New Student</h2>
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="add_student">
                
                <div class="form-group">
                    <label for="user_id">Student ID:</label>
                    <input type="text" id="user_id" name="user_id" required pattern="CIHE\d{6}">
                    <small>Format: CIHE240001</small>
                </div>
                
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="first_name">First Name:</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Last Name:</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone:</label>
                    <input type="tel" id="phone" name="phone" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn-primary">Register Student</button>
                </div>
            </form>
        </div>

        <!-- Enroll Student in Class -->
        <div class="form-section">
            <h2>Enroll Student in Class</h2>
            <form method="POST" class="enrollment-form">
                <input type="hidden" name="action" value="enroll_student">
                
                <div class="form-group">
                    <label for="enroll_student_id">Student:</label>
                    <select id="enroll_student_id" name="student_id" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['user_id'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="enroll_class_id">Class:</label>
                    <select id="enroll_class_id" name="class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn-primary">Enroll Student</button>
                </div>
            </form>
        </div>

        <!-- Quick Face Capture (Optional Photo) -->
        <div class="form-section">
            <h2>Quick Face Photo</h2>
            <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:320px;">
                    <video id="adminFaceVideo" autoplay muted playsinline style="width:100%; max-width:480px; border-radius:8px; background:#000;"></video>
                    <div class="toolbar" style="margin-top:10px; gap:10px; align-items:center;">
                        <button type="button" class="btn" onclick="adminStartCamera()">Start Camera</button>
                        <button type="button" class="btn-secondary" onclick="adminStopCamera()">Stop</button>
                        <button type="button" class="btn" onclick="captureFrame()">Capture</button>
                        <select id="cameraSelect" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:6px;"></select>
                    </div>
                    <div id="adminFaceStatus" class="muted" style="margin-top:6px;"></div>
                </div>
                <div style="flex:1; min-width:280px;">
                    <div class="form-group">
                        <label for="face_student_id">Select Student</label>
                        <select id="face_student_id">
                            <option value="">Select student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['user_id'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <canvas id="captureCanvas" width="320" height="240" style="border:1px solid #e5e7eb; border-radius:8px;"></canvas>
                    <div style="text-align:right; margin-top:10px;">
                        <button type="button" class="btn" onclick="saveFaceImage()">Save Photo</button>
                    </div>
                    <small class="muted">This saves a profile photo. Face recognition encodings are handled by the Python app.</small>
                </div>
            </div>
        </div>

        <!-- Student List -->
        <div class="form-section">
            <h2>Registered Students</h2>
            <div class="student-grid">
                <?php foreach ($students as $student): ?>
                    <div class="student-card">
                        <h3><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h3>
                        <p><strong>ID:</strong> <?php echo htmlspecialchars($student['user_id']); ?></p>
                        <p><strong>Username:</strong> <?php echo htmlspecialchars($student['username']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($student['phone']); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="status-<?php echo $student['status']; ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </p>
                        <p><strong>Enrolled Classes:</strong> <?php echo $student['enrolled_classes']; ?></p>
                        <p><strong>Total Attendance:</strong> <?php echo $student['total_attendance']; ?></p>
                        
                        <div class="student-actions">
                            <button type="button" class="btn-secondary" onclick="viewStudent(<?php echo $student['id']; ?>)">View</button>
                            <button type="button" class="btn-primary" onclick="editStudent(<?php echo $student['id']; ?>)">Edit</button>
                            
                            <form method="POST" class="status-form">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                <select name="status" onchange="this.form.submit()" class="status-select">
                                    <option value="active" <?php echo $student['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $student['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="pending" <?php echo $student['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        </main>
    </div>

    <!-- View Student Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('viewModal')">&times;</span>
            <h2>Student Details</h2>
            <div id="viewStudentContent"></div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Edit Student</h2>
            <form id="editStudentForm" method="POST">
                <input type="hidden" name="action" value="edit_student">
                <input type="hidden" name="student_id" id="edit_student_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_user_id">Student ID:</label>
                        <input type="text" id="edit_user_id" name="user_id" required pattern="CIHE\d{6}">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_username">Username:</label>
                        <input type="text" id="edit_username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email:</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_first_name">First Name:</label>
                        <input type="text" id="edit_first_name" name="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_last_name">Last Name:</label>
                        <input type="text" id="edit_last_name" name="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_phone">Phone:</label>
                        <input type="tel" id="edit_phone" name="phone" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status">Status:</label>
                        <select id="edit_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" class="btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Update Student</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let adminVideoStream = null;
        let selectedDeviceId = null;
        const statusEl = document.getElementById('adminFaceStatus');
        const cameraSelect = document.getElementById('cameraSelect');

        cameraSelect && cameraSelect.addEventListener('change', async function() {
            selectedDeviceId = this.value || null;
            if (adminVideoStream) {
                await adminStartCamera(true);
            }
        });

        async function populateCameras() {
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const videos = devices.filter(d => d.kind === 'videoinput');
                cameraSelect.innerHTML = '';
                if (videos.length === 0) {
                    cameraSelect.innerHTML = '<option>No camera found</option>';
                    return;
                }
                videos.forEach((d, idx) => {
                    const opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.textContent = d.label || `Camera ${idx+1}`;
                    cameraSelect.appendChild(opt);
                });
                if (!selectedDeviceId && videos[0]) {
                    selectedDeviceId = videos[0].deviceId;
                    cameraSelect.value = selectedDeviceId;
                }
            } catch (e) {
                console.warn('enumerateDevices failed', e);
            }
        }

        function getMediaStream(constraints) {
            return new Promise((resolve, reject) => {
                const nav = navigator;
                if (nav.mediaDevices && nav.mediaDevices.getUserMedia) {
                    nav.mediaDevices.getUserMedia(constraints).then(resolve).catch(reject);
                    return;
                }
                const legacy = nav.getUserMedia || nav.webkitGetUserMedia || nav.mozGetUserMedia || nav.msGetUserMedia;
                if (legacy) {
                    legacy.call(nav, constraints, resolve, reject);
                } else {
                    reject(new Error('getUserMedia not supported'));
                }
            });
        }

        async function adminStartCamera(restarting = false) {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                statusEl.textContent = 'Camera API not supported in this browser.';
                alert('Camera not supported');
                return;
            }
            // Insecure context warning (except localhost)
            const insecure = location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1';
            if (insecure) {
                statusEl.textContent = 'Warning: Use https:// or http://localhost to allow camera permissions.';
            }
            try {
                const videoConstraints = selectedDeviceId ? { deviceId: { exact: selectedDeviceId } } : { facingMode: { ideal: 'user' }, width: { ideal: 640 }, height: { ideal: 480 } };
                const constraints = { video: videoConstraints, audio: false };
                const stream = await getMediaStream(constraints);
                const video = document.getElementById('adminFaceVideo');
                video.srcObject = stream;
                await video.play().catch(()=>{});
                if (adminVideoStream) {
                    adminVideoStream.getTracks().forEach(t => t.stop());
                }
                adminVideoStream = stream;
                statusEl.textContent = `Camera active (${location.protocol}//${location.hostname}).`;
                // Populate device list after permission so labels are available
                if (!restarting) await populateCameras();
            } catch (err) {
                console.error(err);
                let msg = 'Unable to access camera. ';
                if (err.name === 'NotAllowedError') msg += 'Permission denied. Allow camera access in your browser settings.';
                else if (err.name === 'NotFoundError' || err.name === 'OverconstrainedError') msg += 'No suitable camera found.';
                else if (err.name === 'NotReadableError') msg += 'Camera is already in use by another application.';
                else msg += 'Error: ' + (err.message || err.toString());
                statusEl.textContent = msg;
                alert(msg);
            }
        }
        function adminStopCamera() {
            if (adminVideoStream) {
                adminVideoStream.getTracks().forEach(t => t.stop());
                adminVideoStream = null;
                document.getElementById('adminFaceVideo').srcObject = null;
            }
        }
        function captureFrame() {
            const video = document.getElementById('adminFaceVideo');
            if (!video || !video.srcObject) { alert('Start camera first.'); return; }
            const canvas = document.getElementById('captureCanvas');
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        }
        function dataURLtoBlob(dataURL) {
            const arr = dataURL.split(',');
            const mime = arr[0].match(/:(.*?);/)[1];
            const bstr = atob(arr[1]);
            let n = bstr.length;
            const u8arr = new Uint8Array(n);
            while(n--) u8arr[n] = bstr.charCodeAt(n);
            return new Blob([u8arr], {type:mime});
        }
        function saveFaceImage() {
            const studentId = document.getElementById('face_student_id').value;
            if (!studentId) { alert('Select a student.'); return; }
            const canvas = document.getElementById('captureCanvas');
            const dataUrl = canvas.toDataURL('image/png');
            fetch('upload_face_image.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: studentId, image_base64: dataUrl })
            }).then(r => r.json()).then(res => {
                if (res.success) alert('Photo saved.'); else alert('Failed: ' + res.message);
            }).catch(e => { console.error(e); alert('Error saving photo'); });
        }
        function viewStudent(studentId) {
            // Fetch student data and display in modal
            fetch('get_student.php?id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const student = data.student;
                        document.getElementById('viewStudentContent').innerHTML = `
                            <div class="student-details">
                                <p><strong>Student ID:</strong> ${student.user_id}</p>
                                <p><strong>Username:</strong> ${student.username}</p>
                                <p><strong>Email:</strong> ${student.email}</p>
                                <p><strong>First Name:</strong> ${student.first_name}</p>
                                <p><strong>Last Name:</strong> ${student.last_name}</p>
                                <p><strong>Phone:</strong> ${student.phone}</p>
                                <p><strong>Status:</strong> <span class="status-${student.status}">${student.status.charAt(0).toUpperCase() + student.status.slice(1)}</span></p>
                                <p><strong>Enrolled Classes:</strong> ${student.enrolled_classes || 0}</p>
                                <p><strong>Total Attendance:</strong> ${student.total_attendance || 0}</p>
                                <p><strong>Created:</strong> ${student.created_at}</p>
                            </div>
                        `;
                        document.getElementById('viewModal').style.display = 'block';
                    } else {
                        alert('Error loading student data: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading student data');
                });
        }

        function editStudent(studentId) {
            // Fetch student data and populate edit form
            fetch('get_student.php?id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const student = data.student;
                        document.getElementById('edit_student_id').value = student.id;
                        document.getElementById('edit_user_id').value = student.user_id;
                        document.getElementById('edit_username').value = student.username;
                        document.getElementById('edit_email').value = student.email;
                        document.getElementById('edit_first_name').value = student.first_name;
                        document.getElementById('edit_last_name').value = student.last_name;
                        document.getElementById('edit_phone').value = student.phone;
                        document.getElementById('edit_status').value = student.status;
                        document.getElementById('editModal').style.display = 'block';
                    } else {
                        alert('Error loading student data: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading student data');
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
