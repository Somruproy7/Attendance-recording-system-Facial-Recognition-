<?php
// lecturer_management.php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Handle actions
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_lecturer') {
            // Validate input
            $required = ['first_name', 'last_name', 'email', 'user_id', 'password'];
            $missing = array_diff($required, array_keys(array_filter($_POST, 'strlen')));
            if ($missing) throw new Exception('Missing required fields');
            
            // Check if email/user_id exists
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR user_id = ?");
            $stmt->execute([$_POST['email'], $_POST['user_id']]);
            if ($stmt->fetchColumn() > 0) throw new Exception('Email or User ID already exists');
            
            // Insert lecturer
            $stmt = $conn->prepare("
                INSERT INTO users (user_id, first_name, last_name, email, password_hash, user_type, phone, department, bio, status)
                VALUES (?, ?, ?, ?, ?, 'lecturer', ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $_POST['user_id'],
                $_POST['first_name'],
                $_POST['last_name'],
                $_POST['email'],
                password_hash($_POST['password'], PASSWORD_DEFAULT),
                $_POST['phone'] ?? null,
                $_POST['department'] ?? null,
                $_POST['bio'] ?? null
            ]);
            $success = 'Lecturer added successfully';
        }
        
        elseif ($action === 'edit_lecturer' && !empty($_POST['id'])) {
            // Validate input
            $required = ['first_name', 'last_name', 'email'];
            $missing = array_diff($required, array_keys(array_filter($_POST, 'strlen')));
            if ($missing) throw new Exception('Missing required fields');
            
            // Check if email exists for other users
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$_POST['email'], (int)$_POST['id']]);
            if ($stmt->fetchColumn() > 0) throw new Exception('Email already exists');
            
            // Update lecturer
            $updateSql = "UPDATE users SET first_name=?, last_name=?, email=?, phone=?, department=?, bio=?";
            $params = [
                $_POST['first_name'],
                $_POST['last_name'],
                $_POST['email'],
                $_POST['phone'] ?? null,
                $_POST['department'] ?? null,
                $_POST['bio'] ?? null
            ];
            
            // Update password if provided
            if (!empty($_POST['password'])) {
                $updateSql .= ", password_hash=?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $updateSql .= " WHERE id=? AND user_type='lecturer'";
            $params[] = (int)$_POST['id'];
            
            $stmt = $conn->prepare($updateSql);
            $stmt->execute($params);
            $success = 'Lecturer updated successfully';
        }
        
        elseif ($action === 'assign_classes' && !empty($_POST['lecturer_id'])) {
            $conn->beginTransaction();
            
            // Remove existing assignments
            $stmt = $conn->prepare("DELETE FROM class_lecturers WHERE lecturer_id = ?");
            $stmt->execute([(int)$_POST['lecturer_id']]);
            
            // Add new assignments
            if (!empty($_POST['class_ids'])) {
                $stmt = $conn->prepare("INSERT INTO class_lecturers (class_id, lecturer_id) VALUES (?, ?)");
                foreach ($_POST['class_ids'] as $class_id) {
                    $stmt->execute([(int)$class_id, (int)$_POST['lecturer_id']]);
                }
            }
            
            $conn->commit();
            $success = 'Class assignments updated successfully';
        }
        
        elseif ($action === 'toggle_status' && !empty($_POST['id'])) {
            $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            $success = 'Status updated';
        }
        
        elseif ($action === 'delete_lecturer' && !empty($_POST['id'])) {
            $conn->beginTransaction();
            
            // Remove class assignments first
            $stmt = $conn->prepare("DELETE FROM class_lecturers WHERE lecturer_id = ?");
            $stmt->execute([(int)$_POST['id']]);
            
            // Delete lecturer
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND user_type = 'lecturer'");
            $stmt->execute([(int)$_POST['id']]);
            
            if ($stmt->rowCount() > 0) {
                $conn->commit();
                $success = 'Lecturer deleted';
            } else {
                $conn->rollback();
                throw new Exception('Delete failed');
            }
        }
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollback();
        $error = 'Database error: ' . $e->getMessage();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        $error = $e->getMessage();
    }
}

// Get all lecturers with class count
$lecturers = [];
try {
    $stmt = $conn->query("
        SELECT u.id, u.user_id, u.first_name, u.last_name, u.email, u.phone, u.department, u.bio, u.status,
               COUNT(cl.class_id) as class_count
        FROM users u 
        LEFT JOIN class_lecturers cl ON u.id = cl.lecturer_id
        WHERE u.user_type = 'lecturer'
        GROUP BY u.id, u.user_id, u.first_name, u.last_name, u.email, u.phone, u.department, u.bio, u.status
        ORDER BY u.last_name, u.first_name
    ");
    $lecturers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load lecturers';
}

// Get all classes for assignment dropdown
$classes = [];
try {
    $stmt = $conn->query("SELECT id, class_code, class_name FROM classes WHERE status = 'active' ORDER BY class_code");
    $classes = $stmt->fetchAll();
} catch (PDOException $e) {
    // Ignore error for classes
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Management - Admin</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .btn-edit {
            background: #3b82f6;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-toggle {
            background: #10b981;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            margin-right: 4px;
        }
        .action-buttons {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
        }
        .modal-footer {
            margin-top: 20px;
            text-align: right;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-outline {
            background: white;
            border: 1px solid #d1d5db;
            color: #4b5563;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        .btn-outline:hover {
            background: #f9fafb;
        }
        .badge {
            background: #f3f4f6;
            color: #374151;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 500;
        }
    </style>
</head>
<body class="admin-portal">
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="../admin_dashboard.php">Dashboard</a>
                <a href="../class_management.php">Class Management</a>
                <a href="../student_directory.php">Student Directory</a>
                <a href="../student_registration.php">Student Management</a>
                <a href="lecturer_management.php" class="active">Lecturer Management</a>
                <a href="../timetable_management.php">Timetable</a>
                <a href="../reports.php">Reports</a>
                <a href="../settings.php">Settings</a>
                <a href="../logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="../images/fullattend_logo.png" alt="FullAttend Logo">
            </div>
        </aside>

        <!-- Main Content -->
        <main class="dashboard-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1>Lecturer Management</h1>
                    <p class="muted">Manage lecturer accounts and permissions</p>
                </div>
                <div class="toolbar">
                    <button class="btn-primary" onclick="openModal('addLecturerModal')">
                        <i class="fas fa-plus"></i> Add Lecturer
                    </button>
                </div>
            </div>
            
            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #4f46e5;"><?php echo count($lecturers); ?></div>
                    <p class="muted">Total Lecturers</p>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #10b981;"><?php echo count(array_filter($lecturers, fn($l) => $l['status'] === 'active')); ?></div>
                    <p class="muted">Active Lecturers</p>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #8b5cf6;"><?php echo array_sum(array_column($lecturers, 'class_count')); ?></div>
                    <p class="muted">Total Assignments</p>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" style="color: #f59e0b;"><?php echo count($classes); ?></div>
                    <p class="muted">Available Classes</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (empty($lecturers)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-chalkboard-teacher" style="font-size: 3em; color: #d1d5db; margin-bottom: 20px;"></i>
                        <h3>No Lecturers Found</h3>
                        <p>Add a new lecturer to get started.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="table" style="overflow-x: auto;">
                        <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Classes</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lecturers as $lecturer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($lecturer['user_id']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($lecturer['first_name'] . ' ' . $lecturer['last_name']); ?>
                                        <?php if (!empty($lecturer['phone'])): ?>
                                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($lecturer['phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($lecturer['email']); ?></td>
                                    <td>
                                        <?php if (!empty($lecturer['department'])): ?>
                                            <?php echo htmlspecialchars($lecturer['department']); ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge"><?php echo $lecturer['class_count']; ?> classes</span>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo strtolower($lecturer['status']); ?>">
                                            <?php echo ucfirst($lecturer['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit" title="Edit Lecturer" onclick="editLecturer(<?php echo $lecturer['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm" style="background: #8b5cf6; color: white; padding: 6px 12px; border: none; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer;" title="Assign Classes" onclick="assignClasses(<?php echo $lecturer['id']; ?>)">
                                                <i class="fas fa-chalkboard"></i> Classes
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?php echo $lecturer['id']; ?>">
                                                <button type="submit" class="btn-toggle" title="Toggle Status">
                                                    <i class="fas fa-<?php echo $lecturer['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                                    <?php echo $lecturer['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this lecturer?');">
                                                <input type="hidden" name="action" value="delete_lecturer">
                                                <input type="hidden" name="id" value="<?php echo $lecturer['id']; ?>">
                                                <button type="submit" class="btn-danger" style="padding: 6px 12px; font-size: 12px;" title="Delete">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add Lecturer Modal -->
    <div id="addLecturerModal" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;"><i class="fas fa-user-plus"></i> Add New Lecturer</h3>
                <button onclick="closeModal('addLecturerModal')" style="background: none; border: none; font-size: 1.5em; cursor: pointer;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_lecturer">
                
                <div class="form-group">
                    <label for="user_id">Lecturer ID *</label>
                    <input type="text" id="user_id" name="user_id" class="form-control" required placeholder="e.g., LEC001">
                </div>
                
                <div class="form-group">
                    <label for="first_name">First Name *</label>
                    <input type="text" id="first_name" name="first_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Last Name *</label>
                    <input type="text" id="last_name" name="last_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="8">
                    <small style="color: #6b7280; font-size: 0.8em;">At least 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="department">Department</label>
                    <input type="text" id="department" name="department" class="form-control" placeholder="e.g., Computer Science">
                </div>
                
                <div class="form-group">
                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" class="form-control" rows="3" placeholder="Brief bio or description"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addLecturerModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Lecturer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Lecturer Modal -->
    <div id="editLecturerModal" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;"><i class="fas fa-user-edit"></i> Edit Lecturer</h3>
                <button onclick="closeModal('editLecturerModal')" style="background: none; border: none; font-size: 1.5em; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" id="editLecturerForm">
                <input type="hidden" name="action" value="edit_lecturer">
                <input type="hidden" name="id" id="edit_lecturer_id">
                
                <div class="form-group">
                    <label for="edit_first_name">First Name *</label>
                    <input type="text" id="edit_first_name" name="first_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_last_name">Last Name *</label>
                    <input type="text" id="edit_last_name" name="last_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_email">Email *</label>
                    <input type="email" id="edit_email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_phone">Phone</label>
                    <input type="tel" id="edit_phone" name="phone" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="edit_department">Department</label>
                    <input type="text" id="edit_department" name="department" class="form-control" placeholder="e.g., Computer Science">
                </div>
                
                <div class="form-group">
                    <label for="edit_bio">Bio</label>
                    <textarea id="edit_bio" name="bio" class="form-control" rows="3" placeholder="Brief bio or description"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="edit_password">New Password (leave blank to keep current)</label>
                    <input type="password" id="edit_password" name="password" class="form-control" minlength="8">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editLecturerModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Lecturer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Classes Modal -->
    <div id="assignClassesModal" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;"><i class="fas fa-chalkboard"></i> Assign Classes</h3>
                <button onclick="closeModal('assignClassesModal')" style="background: none; border: none; font-size: 1.5em; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" id="assignClassesForm">
                <input type="hidden" name="action" value="assign_classes">
                <input type="hidden" name="lecturer_id" id="assign_lecturer_id">
                
                <div class="form-group">
                    <label>Select Classes to Assign:</label>
                    <div id="classCheckboxes" style="max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 4px; padding: 10px;">
                        <?php foreach ($classes as $class): ?>
                            <div style="margin: 8px 0;">
                                <label style="display: flex; align-items: center; cursor: pointer;">
                                    <input type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" style="margin-right: 8px;">
                                    <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($classes)): ?>
                            <p style="color: #6b7280; margin: 0;">No active classes available</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('assignClassesModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Assignments</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function editLecturer(lecturerId) {
            // Find lecturer data
            const lecturers = <?php echo json_encode($lecturers); ?>;
            const lecturer = lecturers.find(l => l.id == lecturerId);
            
            if (lecturer) {
                // Populate edit form
                document.getElementById('edit_lecturer_id').value = lecturer.id;
                document.getElementById('edit_first_name').value = lecturer.first_name;
                document.getElementById('edit_last_name').value = lecturer.last_name;
                document.getElementById('edit_email').value = lecturer.email;
                document.getElementById('edit_phone').value = lecturer.phone || '';
                document.getElementById('edit_department').value = lecturer.department || '';
                document.getElementById('edit_bio').value = lecturer.bio || '';
                document.getElementById('edit_password').value = '';
                
                openModal('editLecturerModal');
            }
        }
        
        function assignClasses(lecturerId) {
            document.getElementById('assign_lecturer_id').value = lecturerId;
            
            // Get current assignments for this lecturer
            fetch(`get_lecturer_classes.php?lecturer_id=${lecturerId}`)
                .then(response => response.json())
                .then(data => {
                    // Clear all checkboxes first
                    const checkboxes = document.querySelectorAll('#classCheckboxes input[type="checkbox"]');
                    checkboxes.forEach(cb => cb.checked = false);
                    
                    // Check assigned classes
                    if (data.class_ids) {
                        data.class_ids.forEach(classId => {
                            const checkbox = document.querySelector(`#classCheckboxes input[value="${classId}"]`);
                            if (checkbox) checkbox.checked = true;
                        });
                    }
                })
                .catch(error => {
                    console.log('Could not load current assignments:', error);
                });
            
            openModal('assignClassesModal');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
