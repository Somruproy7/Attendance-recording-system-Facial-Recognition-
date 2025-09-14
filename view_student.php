<?php
// view_student.php
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
$student = null;
$classes = [];

try {
	$stmt = $conn->prepare("\n\t\tSELECT u.*,\n\t\t       COUNT(DISTINCT se.class_id) AS enrolled_classes,\n\t\t       COUNT(DISTINCT ar.id) AS total_attendance\n\t\tFROM users u\n\t\tLEFT JOIN student_enrollments se ON u.id = se.student_id AND se.status = 'enrolled'\n\t\tLEFT JOIN attendance_records ar ON u.id = ar.student_id\n\t\tWHERE u.id = :id AND u.user_type = 'student'\n\t\tGROUP BY u.id\n\t");
	$stmt->execute(['id' => $studentId]);
	$student = $stmt->fetch();

	if ($student) {
		$stmt = $conn->prepare("\n\t\t\tSELECT c.class_code, c.class_name\n\t\t\tFROM student_enrollments se\n\t\t\tJOIN classes c ON se.class_id = c.id\n\t\t\tWHERE se.student_id = :id AND se.status = 'enrolled'\n\t\t\tORDER BY c.class_code\n\t\t");
		$stmt->execute(['id' => $studentId]);
		$classes = $stmt->fetchAll();
	} else {
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
	<title>View Student</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<div class="dashboard">
		<aside class="sidebar">
			<h2>FULL ATTEND</h2>
			<nav>
				<a href="admin_dashboard.php">Dashboard</a>
				<a href="class_management.php">Class Management</a>
				<a href="student_directory.php" class="active">Student Directory</a>
				<a href="student_registration.php">Student Management</a>
				<a href="timetable_management.php">Timetable</a>
				<a href="reports.php">Reports</a>
				<a href="settings.php">Settings</a>
				<a href="logout.php">Logout</a>
			</nav>
			<div class="logos">
				<img src="images/fullattend_logo.png" alt="FullAttend Logo">
			</div>
		</aside>

		<main class="dashboard-content">
			<div class="page-header">
				<h1>Student Details</h1>
			</div>

		<?php if ($error): ?>
			<div class="error-message"><?php echo htmlspecialchars($error); ?></div>
		<?php else: ?>
			<div class="form-section" style="max-width: 900px; width: 100%;">
				<h2><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
				<p class="muted">ID: <?php echo htmlspecialchars($student['user_id']); ?> · Username: <?php echo htmlspecialchars($student['username']); ?></p>
				<div class="student-details" style="margin-top: 16px;">
					<p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
					<p><strong>Phone:</strong> <?php echo htmlspecialchars($student['phone']); ?></p>
					<p><strong>Status:</strong> <span class="status-<?php echo htmlspecialchars($student['status']); ?>"><?php echo ucfirst($student['status']); ?></span></p>
					<p><strong>Created:</strong> <?php echo htmlspecialchars($student['created_at']); ?></p>
					<p><strong>Updated:</strong> <?php echo htmlspecialchars($student['updated_at']); ?></p>
					<p><strong>Enrolled Classes:</strong> <?php echo (int)$student['enrolled_classes']; ?></p>
					<p><strong>Total Attendance Records:</strong> <?php echo (int)$student['total_attendance']; ?></p>
				</div>

				<h3 style="margin-top: 20px;">Classes</h3>
				<div class="student-grid">
					<?php if (empty($classes)): ?>
						<p class="muted">No classes enrolled.</p>
					<?php else: ?>
						<?php foreach ($classes as $class): ?>
							<div class="student-card">
								<p><strong><?php echo htmlspecialchars($class['class_code']); ?></strong> — <?php echo htmlspecialchars($class['class_name']); ?></p>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<div style="margin-top: 20px;">
					<a class="btn" href="edit_student.php?id=<?php echo $studentId; ?>">Edit Student</a>
					<a class="btn" href="student_directory.php">Back to Directory</a>
				</div>
			</div>
		<?php endif; ?>
		</main>
	</div>
</body>
</html>
