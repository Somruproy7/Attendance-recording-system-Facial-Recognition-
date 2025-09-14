<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || 
    (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') && 
    (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'lecturer')) {
    header('Location: ../login.php');
    exit();
}

require_once 'includes/header.php';

$db = new Database();
$conn = $db->getConnection();
$lecturer_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $success = false;
    
    // Validate input
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    
    // Basic validation
    if (empty($first_name)) $errors[] = 'First name is required';
    if (empty($last_name)) $errors[] = 'Last name is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
    
    // Check if email is already taken by another user
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $lecturer_id]);
    if ($stmt->fetch()) {
        $errors[] = 'Email is already taken by another user';
    }
    
    // Handle profile image upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_info = pathinfo($_FILES['profile_image']['name']);
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array(strtolower($file_info['extension']), $allowed_extensions)) {
            $new_filename = 'lecturer_' . $lecturer_id . '_' . time() . '.' . $file_info['extension'];
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                $profile_image = $new_filename;
            } else {
                $errors[] = 'Failed to upload profile image';
            }
        } else {
            $errors[] = 'Invalid image format. Only JPG, JPEG, PNG, and GIF are allowed';
        }
    }
    
    // Update database if no errors
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Update user profile
            if ($profile_image) {
                // Get old image to delete it
                $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
                $stmt->execute([$lecturer_id]);
                $old_image = $stmt->fetchColumn();
                
                // Update with new image
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                        department = ?, bio = ?, profile_image = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$first_name, $last_name, $email, $phone, $department, $bio, $profile_image, $lecturer_id]);
                
                // Delete old image if it exists
                if ($old_image && file_exists($upload_dir . $old_image)) {
                    unlink($upload_dir . $old_image);
                }
            } else {
                // Update without changing image
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                        department = ?, bio = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$first_name, $last_name, $email, $phone, $department, $bio, $lecturer_id]);
            }
            
            // Update session variables
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            
            $conn->commit();
            $success = true;
            $_SESSION['success_message'] = 'Profile updated successfully!';
            
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Failed to update profile: ' . $e->getMessage();
        }
    }
}

// Get current lecturer data
$stmt = $conn->prepare("
    SELECT id, user_id, first_name, last_name, email, phone, department, bio, 
           profile_image, status, created_at, updated_at
    FROM users 
    WHERE id = ?
");
$stmt->execute([$lecturer_id]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturer) {
    $_SESSION['error_message'] = 'Lecturer profile not found.';
    header('Location: dashboard.php');
    exit();
}
?>

<div class="page-header">
    <div class="header-content">
        <h1>My Profile</h1>
        <p>Manage your personal information and settings</p>
    </div>
    <div class="header-actions">
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- Profile Form -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Personal Information</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($success) && $success): ?>
                    <div class="alert alert-success">
                        Profile updated successfully!
                    </div>
                <?php endif; ?>
                
                <form method="post" enctype="multipart/form-data">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                   value="<?= htmlspecialchars($lecturer['first_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                   value="<?= htmlspecialchars($lecturer['last_name']) ?>" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars($lecturer['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" 
                                   value="<?= htmlspecialchars($lecturer['phone'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="department" class="form-label">Department</label>
                        <input type="text" class="form-control" id="department" name="department" 
                               value="<?= htmlspecialchars($lecturer['department'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="profile_image" class="form-label">Profile Image</label>
                        <input type="file" class="form-control" id="profile_image" name="profile_image" 
                               accept="image/jpeg,image/jpg,image/png,image/gif">
                        <div class="form-text">Maximum file size: 2MB. Supported formats: JPG, JPEG, PNG, GIF</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bio" class="form-label">Bio</label>
                        <textarea class="form-control" id="bio" name="bio" rows="4" 
                                  placeholder="Tell us about yourself..."><?= htmlspecialchars($lecturer['bio'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Current Profile Preview -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Current Profile</h5>
            </div>
            <div class="card-body text-center">
                <div class="mb-3">
                    <?php if (!empty($lecturer['profile_image']) && file_exists('../uploads/profiles/' . $lecturer['profile_image'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_image']) ?>" 
                             alt="Profile" class="rounded-circle" width="120" height="120" style="object-fit: cover;">
                    <?php else: ?>
                        <div class="avatar-placeholder rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto" 
                             style="width: 120px; height: 120px; font-size: 2.5rem; font-weight: bold;">
                            <?= strtoupper(substr($lecturer['first_name'], 0, 1) . substr($lecturer['last_name'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h5><?= htmlspecialchars($lecturer['first_name'] . ' ' . $lecturer['last_name']) ?></h5>
                <p class="text-muted mb-2">ID: <?= htmlspecialchars($lecturer['user_id']) ?></p>
                <?php if (!empty($lecturer['department'])): ?>
                    <p class="text-muted mb-2"><?= htmlspecialchars($lecturer['department']) ?></p>
                <?php endif; ?>
                <small class="text-muted">
                    Member since <?= date('F Y', strtotime($lecturer['created_at'])) ?>
                </small>
            </div>
        </div>
        
        <!-- Account Information -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Account Information</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">User ID:</span>
                    <span><?= htmlspecialchars($lecturer['user_id']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Status:</span>
                    <span class="badge bg-<?= $lecturer['status'] === 'active' ? 'success' : 'secondary' ?>">
                        <?= ucfirst($lecturer['status']) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Joined:</span>
                    <span><?= date('M j, Y', strtotime($lecturer['created_at'])) ?></span>
                </div>
                <?php if ($lecturer['updated_at']): ?>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Last Updated:</span>
                    <span><?= date('M j, Y', strtotime($lecturer['updated_at'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('profile_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const fileSize = file.size / 1024 / 1024; // Convert to MB
        if (fileSize > 2) {
            alert('File size must be less than 2MB');
            e.target.value = '';
            return;
        }
        
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert('Only JPG, JPEG, PNG, and GIF files are allowed');
            e.target.value = '';
            return;
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
