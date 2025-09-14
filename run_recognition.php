<?php
// run_recognition.php
// Bridges browser-captured photo to Python recognizer and returns match result
session_start();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$image_base64 = $input['image_base64'] ?? '';
if (!$image_base64) {
	echo json_encode(['success' => false, 'message' => 'No image']);
	exit();
}

// Write base64 to a temp file
$tmp = tempnam(sys_get_temp_dir(), 'fa_');
$png = $tmp . '.png';
unlink($tmp);

// Normalize base64
if (strpos($image_base64, 'base64,') !== false) {
	$image_base64 = substr($image_base64, strpos($image_base64, 'base64,') + 7);
}
$raw = base64_decode($image_base64);
file_put_contents($png, $raw);

// Call Python recognizer using venv python if present
$venvPython = __DIR__ . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$python = file_exists($venvPython) ? $venvPython : 'python';
$cmd = escapeshellcmd($python) . ' ' . escapeshellarg(__DIR__ . '/recognize_face.py') . ' --image_path ' . escapeshellarg($png);
// If a student is logged in, pass expected_student_id so Python compares against their saved profile image
if (!empty($_SESSION['user_id']) && ($_SESSION['user_type'] ?? '') === 'student') {
    $cmd .= ' --expected_student_id ' . escapeshellarg((string)$_SESSION['user_id']);
}
$output = shell_exec($cmd . ' 2>&1');
@unlink($png);

// Parse JSON from Python
$json_start = strpos($output, '{');
if ($json_start !== false) {
	$output = substr($output, $json_start);
}

$data = json_decode($output, true);
if (!is_array($data)) {
	echo json_encode(['success' => false, 'message' => 'Recognizer error', 'raw' => $output]);
	exit();
}

echo json_encode($data);


