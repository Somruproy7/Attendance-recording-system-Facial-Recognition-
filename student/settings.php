<?php
session_start();
require_once '../config/database.php';

// Ensure student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];
$message = "";

// Handle profile update
if (isset($_POST['update_profile'])) {
    $first_name = sanitize_input($_POST['first_name']);
    $last_name = sanitize_input($_POST['last_name']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone']);

    try {
        $stmt = $conn->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone WHERE id = :id");
        $stmt->execute([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'id' => $user_id
        ]);
        $message = "Profile updated successfully!";
    } catch (PDOException $e) {
        $message = "Error updating profile: " . $e->getMessage();
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $old_pass = $_POST['old_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    try {
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $row = $stmt->fetch();
        if ($row && password_verify($old_pass, $row['password_hash'])) {
            if ($new_pass === $confirm_pass) {
                $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password_hash = :ph WHERE id = :id");
                $upd->execute(['ph' => $hashed_pass, 'id' => $user_id]);
                $message = "Password changed successfully!";
            } else {
                $message = "New passwords do not match!";
            }
        } else {
            $message = "Old password is incorrect!";
        }
    } catch (PDOException $e) {
        $message = "Error changing password: " . $e->getMessage();
    }
}

// Handle profile picture upload
if (isset($_POST['upload_image'])) {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $target_dir = "../uploads/profiles/";
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0777, true);
        }
        $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $file_name = $user_id . '_' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/','', $ext);
        $target_file = $target_dir . $file_name;

        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
            $stmt = $conn->prepare("UPDATE users SET profile_image = :img WHERE id = :id");
            $stmt->execute(['img' => $file_name, 'id' => $user_id]);
            $message = "Profile picture updated!";
        } else {
            $message = "Error uploading image.";
        }
    }
}

// Fetch user info
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $user = null;
    $message = "Error loading profile: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Settings</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../student.css?v=<?php echo time(); ?>">
    <script>
        // Optionally poll for updated profile every 2 minutes
        // setInterval(() => location.reload(), 120000);
    </script>
</head>
<body class="student-portal">
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        <main class="dashboard-content">
            <h1>Settings</h1>
            <?php if (!empty($message)): ?>
                <div class="success-message" style="margin: 10px 0;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="form-section">
                <h2>Update Profile</h2>
                <form method="POST" class="form-grid" style="max-width:800px;">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>
                    <div style="grid-column:1/-1; text-align:right;">
                        <button type="submit" name="update_profile" class="btn-primary">Save</button>
                    </div>
                </form>
            </div>

            <div class="form-section">
                <h2>Change Password</h2>
                <form method="POST" class="form-grid" style="max-width:800px;">
                    <div class="form-group">
                        <label>Old Password</label>
                        <input type="password" name="old_password" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                    <div style="grid-column:1/-1; text-align:right;">
                        <button type="submit" name="change_password" class="btn">Change Password</button>
                    </div>
                </form>
            </div>

            <div class="form-section">
                <h2>Profile Picture</h2>
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="../uploads/profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" style="width:100px;height:100px;border-radius:8px;object-fit:cover;" alt="Profile"><br><br>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="form-grid" style="max-width:800px;">
                    <div class="form-group">
                        <input type="file" name="profile_image" required>
                    </div>
                    <div style="grid-column:1/-1; text-align:right;">
                        <button type="submit" name="upload_image" class="btn">Upload</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
