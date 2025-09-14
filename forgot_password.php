<?php
// forgot_password.php
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $user_type = $_POST['user_type'] ?? '';
    
    if (empty($email) || empty($user_type) || !in_array($user_type, ['student', 'lecturer'])) {
        $error = 'Please provide a valid email and select user type';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Check if user exists
            $stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND user_type = ? AND status = 'active'");
            $stmt->execute([$email, $user_type]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate reset token (32 characters)
                $token = bin2hex(random_bytes(16));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store token in database
                $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $stmt->execute([$token, $expires, $user['id']]);
                
                // In a real app, you would send an email with the reset link
                // For this example, we'll just show the reset link
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token;
                $success = "Password reset link has been sent to your email. <a href='$reset_link'>Click here</a> to reset your password.";
                
                // In production, uncomment and configure this:
                /*
                $to = $email;
                $subject = "Password Reset Request";
                $message = "Hello " . $user['first_name'] . ",\n\n";
                $message .= "You have requested to reset your password. Please click the following link to reset your password:\n";
                $message .= $reset_link . "\n\n";
                $message .= "This link will expire in 1 hour.\n\n";
                $message .= "If you did not request this, please ignore this email.\n";
                $headers = "From: noreply@fullattend.com" . "\r\n" .
                         "Reply-To: noreply@fullattend.com" . "\r\n" .
                         "X-Mailer: PHP/" . phpversion();
                
                mail($to, $subject, $message, $headers);
                $success = "If your email exists in our system, you will receive a password reset link shortly.";
                */
            } else {
                // For security, don't reveal if email exists
                $success = "If your email exists in our system, you will receive a password reset link shortly.";
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
    <title>Forgot Password - FullAttend</title>
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
    </style>
</head>
<body class="login-page">
    <div class="auth-container">
        <div class="text-center" style="margin-bottom: 30px;">
            <img src="images/fullattend_logo.png" alt="FullAttend Logo" style="max-width: 200px; margin-bottom: 20px;">
            <h2>Forgot Password</h2>
            <p class="muted">Enter your email to receive a password reset link</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn">Back to Login</a>
            </div>
        <?php else: ?>
            <form method="POST" action="forgot_password.php">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="user_type">I am a</label>
                    <select id="user_type" name="user_type" class="form-control" required>
                        <option value="">Select User Type</option>
                        <option value="student">Student</option>
                        <option value="lecturer">Lecturer</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">Send Reset Link</button>
                </div>
                
                <div class="text-center mt-3">
                    <a href="login.php">Back to Login</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
