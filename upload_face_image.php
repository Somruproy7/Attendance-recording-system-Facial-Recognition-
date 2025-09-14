<?php
// upload_face_image.php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$student_id = isset($input['student_id']) ? intval($input['student_id']) : 0;
$image_base64 = $input['image_base64'] ?? '';

if (!$student_id || !$image_base64) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

// Decode base64
if (strpos($image_base64, 'base64,') !== false) {
    $image_base64 = substr($image_base64, strpos($image_base64, 'base64,') + 7);
}
$image_data = base64_decode($image_base64);
if ($image_data === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid image data']);
    exit();
}

// Ensure uploads directory exists
$dir = __DIR__ . '/uploads/profiles';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}

$filename = 'profile_' . $student_id . '_' . time() . '.png';
$path = $dir . '/' . $filename;
if (file_put_contents($path, $image_data) === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to save image']);
    exit();
}

// Save path relative to app root for serving
$relativePath = 'uploads/profiles/' . $filename;

// Update user profile image
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare('UPDATE users SET profile_image = :img, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_type = "student"');
$stmt->execute(['img' => $filename, 'id' => $student_id]);

// Retrain LBPH model so recognition includes this new photo (best-effort)
try {
    $venvPython = __DIR__ . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $python = file_exists($venvPython) ? $venvPython : 'python';
    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg(__DIR__ . '/train_lbph.py');
    // Run in background without blocking
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        pclose(popen('start /B ' . $cmd, 'r'));
    } else {
        exec($cmd . ' > /dev/null 2>&1 &');
    }
} catch (Throwable $e) {
    // ignore
}

echo json_encode(['success' => true, 'path' => $relativePath]);


