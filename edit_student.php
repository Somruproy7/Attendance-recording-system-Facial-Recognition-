<?php
// edit_student.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
	header('Location: login.php');
	exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
	header('Location: student_directory.php');
	exit();
}

$db = new Database();
$conn = $db->getConnection();
$studentId = (int)$_GET['id'];

$error = '';
$message = '';

function field($name) {
	return htmlspecialchars($_POST[$name] ?? '', ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$user_id    = trim($_POST['user_id'] ?? '');
	$username   = trim($_POST['username'] ?? '');
	$email      = trim($_POST['email'] ?? '');
	$first_name = trim($_POST['first_name'] ?? '');
	$last_name  = trim($_POST['last_name'] ?? '');
	$phone      = trim($_POST['phone'] ?? '');
	$status     = trim($_POST['status'] ?? 'active');

	try {
		// Uniqueness check
		$check = $conn->prepare("SELECT id FROM users WHERE (user_id = :user_id OR username = :username OR email = :email) AND id != :id");
		$check->execute(['user_id' => $user_id, 'username' => $username, 'email' => $email, 'id' => $studentId]);
		if ($check->rowCount() > 0) {
			$error = 'Student ID, username, or email already in use by another account.';
		} else {
			$update = $conn->prepare("\n\t\t\tUPDATE users\n\t\t\tSET user_id = :user_id, username = :username, email = :email, first_name = :first_name, last_name = :last_name, phone = :phone, status = :status, updated_at = CURRENT_TIMESTAMP\n\t\t\tWHERE id = :id AND user_type = 'student'\n\t\t");
			$update->execute([
				'user_id' => $user_id,
				'username' => $username,
				'email' => $email,
				'first_name' => $first_name,
				'last_name' => $last_name,
				'phone' => $phone,
				'status' => $status,
				'id' => $studentId,
			]);
			// Redirect back to directory with success
			header('Location: student_directory.php?msg=' . urlencode('Student updated successfully'));
			exit();
		}
	} catch (Exception $e) {
		$error = 'Database error: ' . $e->getMessage();
	}
}

// Load current data
try {
	$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id AND user_type = 'student'");
	$stmt->execute(['id' => $studentId]);
	$student = $stmt->fetch();
	if (!$student) {
		$error = 'Student not found';
	}
} catch (Exception $e) {
	$error = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Edit Student</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<div class="container">
		<header>
			<h1>Edit Student</h1>
			<nav>
				<a href="admin_dashboard.php">Dashboard</a>
				<a href="student_directory.php" class="active">Student Directory</a>
				<a href="student_registration.php">Students</a>
				<a href="logout.php">Logout</a>
			</nav>
		</header>

		<?php if ($error): ?>
			<div class="error-message"><?php echo htmlspecialchars($error); ?></div>
		<?php elseif ($student): ?>
			<div class="form-section" style="max-width: 900px; width: 100%;">
				<form method="POST" class="form-grid">
					<div class="form-group">
						<label for="user_id">Student ID</label>
						<input id="user_id" name="user_id" required pattern="CIHE\d{6}" value="<?php echo htmlspecialchars($student['user_id']); ?>"/>
						<small class="muted">Format: CIHE240001</small>
					</div>

					<div class="form-group">
						<label for="username">Username</label>
						<input id="username" name="username" required value="<?php echo htmlspecialchars($student['username']); ?>"/>
					</div>

					<div class="form-group">
						<label for="email">Email</label>
						<input id="email" type="email" name="email" required value="<?php echo htmlspecialchars($student['email']); ?>"/>
					</div>

					<div class="form-group">
						<label for="first_name">First Name</label>
						<input id="first_name" name="first_name" required value="<?php echo htmlspecialchars($student['first_name']); ?>"/>
					</div>

					<div class="form-group">
						<label for="last_name">Last Name</label>
						<input id="last_name" name="last_name" required value="<?php echo htmlspecialchars($student['last_name']); ?>"/>
					</div>

					<div class="form-group">
						<label for="phone">Phone</label>
						<input id="phone" name="phone" required value="<?php echo htmlspecialchars($student['phone']); ?>"/>
					</div>

					<div class="form-group">
						<label for="status">Status</label>
						<select id="status" name="status">
							<option value="active" <?php echo $student['status']==='active'?'selected':''; ?>>Active</option>
							<option value="inactive" <?php echo $student['status']==='inactive'?'selected':''; ?>>Inactive</option>
							<option value="pending" <?php echo $student['status']==='pending'?'selected':''; ?>>Pending</option>
						</select>
					</div>

					<div style="grid-column: 1 / -1; text-align: right;">
						<a class="btn" href="student_directory.php">Cancel</a>
						<button class="btn-primary" type="submit">Save Changes</button>
					</div>
				</form>
			</div>
		<?php endif; ?>
	</div>
</body>
</html>
