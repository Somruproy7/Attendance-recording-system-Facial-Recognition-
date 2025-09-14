<?php
// settings.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
	header('Location: login.php');
	exit();
}

$db = new Database();
$conn = $db->getConnection();

$message = '';
$error = '';

function getSetting(PDO $conn, string $key, $default = '') {
	$stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1");
	$stmt->execute(['key' => $key]);
	$row = $stmt->fetch();
	return $row ? $row['setting_value'] : $default;
}

function setSetting(PDO $conn, string $key, string $value): void {
	$stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value)
	\tON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
	$stmt->execute(['key' => $key, 'value' => $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		setSetting($conn, 'system_name', trim($_POST['system_name'] ?? 'Full Attend'));
		setSetting($conn, 'school_name', trim($_POST['school_name'] ?? ''));
		setSetting($conn, 'timezone', trim($_POST['timezone'] ?? 'UTC'));
		setSetting($conn, 'attendance_grace_minutes', (string)intval($_POST['attendance_grace_minutes'] ?? 5));
		setSetting($conn, 'recognition_confidence_threshold', (string)floatval($_POST['recognition_confidence_threshold'] ?? 0.6));
		setSetting($conn, 'admin_email', trim($_POST['admin_email'] ?? ''));
		setSetting($conn, 'announcements', trim($_POST['announcements'] ?? ''));
		$message = 'Settings saved successfully';
	} catch (Exception $e) {
		$error = 'Failed to save settings: ' . $e->getMessage();
	}
}

$settings = [
	'system_name' => getSetting($conn, 'system_name', 'Full Attend'),
	'school_name' => getSetting($conn, 'school_name', ''),
	'timezone' => getSetting($conn, 'timezone', 'UTC'),
	'attendance_grace_minutes' => getSetting($conn, 'attendance_grace_minutes', '5'),
	'recognition_confidence_threshold' => getSetting($conn, 'recognition_confidence_threshold', '0.6'),
	'admin_email' => getSetting($conn, 'admin_email', ''),
	'announcements' => getSetting($conn, 'announcements', ''),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Settings - Full Attend</title>
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
				<a href="student_registration.php">Student Management</a>
				<a href="admin/lecturer_management.php">Lecturer Management</a>
				<a href="timetable_management.php">Timetable</a>
				<a href="reports.php">Reports</a>
				<a href="settings.php" class="active">Settings</a>
				<a href="logout.php">Logout</a>
			</nav>
			<div class="logos">
				<img src="images/fullattend_logo.png" alt="FullAttend Logo">
			</div>
		</aside>

		<main class="dashboard-content">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
				<div>
					<h1>System Settings</h1>
					<p class="muted">Configure application preferences</p>
				</div>
			</div>

		<?php if ($message): ?><div class="success-message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
		<?php if ($error): ?><div class="error-message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

		<form method="POST" class="form-section">
			<h2>General</h2>
			<div class="form-grid">
				<div class="form-group">
					<label for="system_name">System Name</label>
					<input id="system_name" name="system_name" value="<?php echo htmlspecialchars($settings['system_name']); ?>" required />
				</div>
				<div class="form-group">
					<label for="school_name">School/Organization</label>
					<input id="school_name" name="school_name" value="<?php echo htmlspecialchars($settings['school_name']); ?>" />
				</div>
				<div class="form-group">
					<label for="timezone">Timezone</label>
					<input id="timezone" name="timezone" value="<?php echo htmlspecialchars($settings['timezone']); ?>" />
				</div>
				<div class="form-group">
					<label for="admin_email">Admin Email</label>
					<input id="admin_email" type="email" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email']); ?>" />
				</div>
			</div>

			<h2 style="margin-top:20px;">Attendance</h2>
			<div class="form-grid">
				<div class="form-group">
					<label for="attendance_grace_minutes">Grace Minutes</label>
					<input id="attendance_grace_minutes" type="number" min="0" max="60" name="attendance_grace_minutes" value="<?php echo htmlspecialchars($settings['attendance_grace_minutes']); ?>" />
				</div>
				<div class="form-group">
					<label for="recognition_confidence_threshold">Recognition Threshold (0-1)</label>
					<input id="recognition_confidence_threshold" type="number" step="0.01" min="0" max="1" name="recognition_confidence_threshold" value="<?php echo htmlspecialchars($settings['recognition_confidence_threshold']); ?>" />
				</div>
			</div>

			

			<div style="text-align: right;">
				<button type="submit" class="btn-primary">Save Settings</button>
			</div>
		</form>
		</main>
	</div>
</body>
</html>
