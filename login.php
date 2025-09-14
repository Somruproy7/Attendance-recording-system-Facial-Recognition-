<?php
// login.php
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user_type = $_POST['user_type'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $db = new Database();
        $conn = $db->getConnection();
        
        try {
            $stmt = $conn->prepare("
                SELECT id, user_id, username, email, password_hash, user_type, first_name, last_name, status 
                FROM users 
                WHERE (username = :username OR email = :email OR user_id = :user_id) 
                AND user_type = :user_type AND status = 'active'
            ");
            
            $stmt->execute([
                'username' => $username,
                'email' => $username,
                'user_id' => $username,
                'user_type' => $user_type
            ]);
            
            $user = $stmt->fetch();
            
            if ($user && verify_password($password, $user['password_hash'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['student_id'] = $user['user_id'];
                
                // Redirect based on user type
                switch ($user['user_type']) {
                    case 'admin':
                        header('Location: admin_dashboard.php');
                        break;
                    case 'lecturer':
                        header('Location: lecturer/lecturer_dashboard.php');
                        break;
                    case 'student':
                    default:
                        header('Location: student/student_dashboard.php');
                }
                exit();
            } else {
                $error = "Invalid credentials or account not active.";
            }
        } catch (PDOException $e) {
            $error = "Database error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Full Attend</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* Full page background override */
        body {
            margin: 0 !important;
            padding: 0 !important;
            background: #d4c4c0 !important;
            background-image: none !important;
            width: 100% !important;
            height: 100vh !important;
            overflow-x: hidden !important;
        }
        .container {
            min-height: 100vh;
            width: 100vw;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #d4c4c0 !important;
            background-image: none !important;
            margin: 0 !important;
            box-sizing: border-box;
        }
        /* Update login button colors */
        .button-group button {
            background: #8b7bc7 !important;
            color: white !important;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .button-group button:hover {
            background: #7968b8 !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        }
        .login-button {
            background: #8b7bc7 !important;
            color: white !important;
        }
        .login-button:hover {
            background: #7968b8 !important;
        }
        /* Make all button text uppercase */
        .button-group button {
            text-transform: uppercase !important;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-row">
            <div class="logo">
                <img src="images/fullattend_logo.png" alt="FullAttend Logo">
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error-message" style="color: red; text-align: center; margin: 10px 0;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="button-group">
            <a href="#" onclick="showLoginForm('student')"><button>STUDENT LOGIN</button></a>
            <a href="#" onclick="showLoginForm('lecturer')"><button>LECTURER LOGIN</button></a>
            <a href="#" onclick="showLoginForm('admin')"><button>ADMIN LOGIN</button></a>
        </div>
        
        <!-- Login Form Modal -->
        <div id="loginModal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); z-index: 1000;">
            <div class="login-box">
                <div class="login-logos">
                    <img src="images/fullattend_logo.png" alt="FullAttend Logo">
                </div>
                <h3 id="loginTitle">Student Login</h3>
                <p style="text-align: center; margin: 10px 0 0; color: #6b7280;">
                    New lecturer? <a href="lecturer_signup.php" style="color: #4f46e5; text-decoration: none;">Sign up here</a>
                </p>
                <form method="POST">
                    <input type="hidden" id="userType" name="user_type" value="student">
                    <div class="input-group">
                        <input type="text" name="username" placeholder="Student ID / Username" required>
                    </div>
                    <div class="input-group">
                        <input type="password" name="password" placeholder="Password" required>
                    </div>
                    <button type="submit" class="login-button">LOG IN</button>
                </form>
                <div id="forgotPasswordLink" class="forgot-password" style="display: none;">
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>
                <button onclick="hideLoginForm()" style="background: #e5e7eb; color: #111; margin-top: 10px;">Close</button>
            </div>
        </div>
        
        <!-- Overlay -->
        <div id="overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999;" onclick="hideLoginForm()"></div>
    </div>

    <script>
        function showLoginForm(type) {
            document.getElementById('loginModal').style.display = 'block';
            document.getElementById('overlay').style.display = 'block';
            document.getElementById('userType').value = type;
            document.getElementById('loginTitle').textContent = (type.charAt(0).toUpperCase() + type.slice(1) + ' Login').toUpperCase();
            
            // Show/hide forgot password link based on user type
            const forgotPasswordLink = document.getElementById('forgotPasswordLink');
            if (type === 'admin') {
                forgotPasswordLink.style.display = 'none';
            } else {
                forgotPasswordLink.style.display = 'block';
            }
            
            let placeholder = 'Username / Email';
            if (type === 'student') {
                placeholder = 'Student ID / Username';
            } else if (type === 'lecturer') {
                placeholder = 'Lecturer ID / Email';
            }
            document.querySelector('input[name="username"]').placeholder = placeholder;
        }
        
        function hideLoginForm() {
            document.getElementById('loginModal').style.display = 'none';
            document.getElementById('overlay').style.display = 'none';
        }
    </script>
</body>
</html>