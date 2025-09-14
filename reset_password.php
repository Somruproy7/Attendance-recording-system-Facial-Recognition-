<?php
// reset_password.php
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$user_id = null;

// Validate token
if (!empty($token)) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $valid_token = true;
            $user_id = $user['id'];
        } else {
            $error = 'Invalid or expired reset token.';
        }
    } catch (PDOException $e) {
        $error = 'An error occurred. Please try again later.';
        // Log the error in production
        // error_log($e->getMessage());
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        try {
            // Update password and clear reset token
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                $success = 'Your password has been reset successfully. You can now <a href="login.php">login</a> with your new password.';
                $valid_token = false; // Hide the form after successful reset
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'An error occurred. Please try again later.';
            // Log the error in production
            // error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - FullAttend</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .auth-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn {
            display: inline-block;
            background: #4a6baf;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        .btn:hover {
            background: #3a5a9a;
        }
        .text-center {
            text-align: center;
        }
        .mt-3 {
            margin-top: 1rem;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .password-requirements {
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body class="login-page">
    <div class="auth-container">
        <div class="text-center" style="margin-bottom: 30px;">
            <img src="images/fullattend_logo.png" alt="FullAttend Logo" style="max-width: 200px; margin-bottom: 20px;">
            <h2>Reset Password</h2>
            <?php if ($valid_token): ?>
                <p class="muted">Enter your new password below</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($valid_token): ?>
            <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="8">
                    <div class="password-requirements">Must be at least 8 characters long</div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8">
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">Reset Password</button>
                </div>
            </form>
        <?php elseif (empty($success)): ?>
            <div class="alert alert-danger">Invalid or expired password reset link. Please request a new one.</div>
            <div class="text-center mt-3">
                <a href="forgot_password.php" class="btn">Request New Reset Link</a>
            </div>
        <?php endif; ?>
        
        <div class="text-center mt-3">
            <a href="login.php">Back to Login</a>
        </div>
    </div>
    
    <script>
        // Client-side password validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const password = document.getElementById('password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    
                    if (password.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long');
                        return false;
                    }
                    
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        alert('Passwords do not match');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>
