<?php
session_start();
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $staff_id = trim($_POST['staff_id']);
    $department = trim($_POST['department']);
    $phone = trim($_POST['phone']);

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($staff_id)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        try {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email OR user_id = :staff_id");
            $stmt->execute(['email' => $email, 'staff_id' => $staff_id]);
            
            if ($stmt->rowCount() > 0) {
                $error = 'Email or Staff ID already exists';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Generate username from email
                $username = strtolower($first_name[0] . $last_name);
                $username = preg_replace('/[^a-z0-9]/', '', $username);
                $usernameSuffix = 1;
                $originalUsername = $username;
                
                // Ensure username is unique
                while (true) {
                    $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
                    $checkStmt->execute(['username' => $username]);
                    if ($checkStmt->rowCount() === 0) break;
                    $username = $originalUsername . $usernameSuffix++;
                }
                
                // Insert new lecturer
                $stmt = $conn->prepare("
                    INSERT INTO users (user_id, username, first_name, last_name, email, password_hash, user_type, phone, status, created_at)
                    VALUES (:user_id, :username, :first_name, :last_name, :email, :password_hash, 'lecturer', :phone, 'active', NOW())
                ");
                
                $stmt->execute([
                    'user_id' => $staff_id,
                    'username' => $username,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'password_hash' => $hashed_password,
                    'phone' => $phone
                ]);
                
                // Get the new user's ID
                $user_id = $conn->lastInsertId();
                
                // Insert lecturer details
                $stmt = $conn->prepare("
                    INSERT INTO lecturers (user_id, staff_id, department, created_at)
                    VALUES (:user_id, :staff_id, :department, NOW())
                ");
                
                $stmt->execute([
                    'user_id' => $user_id,
                    'staff_id' => $staff_id,
                    'department' => $department
                ]);
                
                // Set session and redirect
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_type'] = 'lecturer';
                $_SESSION['first_name'] = $first_name;
                
                header('Location: lecturer/lecturer_dashboard.php');
                exit();
            }
        } catch (PDOException $e) {
            $error = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Sign Up - Full Attend</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .signup-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn {
            display: inline-block;
            background: #4f46e5;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-align: center;
            text-decoration: none;
        }
        .btn-block {
            display: block;
            width: 100%;
        }
        .error {
            color: #e53e3e;
            margin-bottom: 15px;
            padding: 10px;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 4px;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body style="background: #f3f4f6;">
    <div class="signup-container">
        <div style="text-align: center; margin-bottom: 20px;">
            <img src="images/fullattend_logo.png" alt="FullAttend Logo" style="height: 50px; margin-bottom: 10px;">
            <h1>Lecturer Sign Up</h1>
            <p class="muted">Create your lecturer account</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="first_name">First Name *</label>
                <input type="text" id="first_name" name="first_name" required 
                       value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" id="last_name" name="last_name" required
                       value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="staff_id">Staff ID *</label>
                <input type="text" id="staff_id" name="staff_id" required
                       value="<?php echo isset($_POST['staff_id']) ? htmlspecialchars($_POST['staff_id']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="department">Department</label>
                <input type="text" id="department" name="department"
                       value="<?php echo isset($_POST['department']) ? htmlspecialchars($_POST['department']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone"
                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" required minlength="8">
                <small>Password must be at least 8 characters long</small>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn btn-block">Create Account</button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</body>
</html>
