<?php
// Migration to add missing columns to classes table
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Check if academic_year column exists
    $stmt = $conn->query("SHOW COLUMNS FROM classes LIKE 'academic_year'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE classes 
                ADD COLUMN academic_year VARCHAR(20) DEFAULT '" . date('Y') . "-" . (date('Y')+1) . "' AFTER class_name";
        $conn->exec($sql);
        echo "✅ Successfully added academic_year column to classes table.\n";
    } else {
        echo "ℹ️  Column academic_year already exists in classes table.\n";
    }
    
    // Check if semester column exists
    $stmt = $conn->query("SHOW COLUMNS FROM classes LIKE 'semester'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE classes 
                ADD COLUMN semester INT DEFAULT 1 AFTER academic_year";
        $conn->exec($sql);
        echo "✅ Successfully added semester column to classes table.\n";
    } else {
        echo "ℹ️  Column semester already exists in classes table.\n";
    }

    // Check if profile_image column exists in users table
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE users 
                ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL AFTER email";
        $conn->exec($sql);
        echo "✅ Successfully added profile_image column to users table.\n";
    } else {
        echo "ℹ️  Column profile_image already exists in users table.\n";
    }

    // Check if phone column exists in users table
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE users 
                ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL AFTER profile_image";
        $conn->exec($sql);
        echo "✅ Successfully added phone column to users table.\n";
    } else {
        echo "ℹ️  Column phone already exists in users table.\n";
    }

    // Check if department column exists in users table
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'department'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE users 
                ADD COLUMN department VARCHAR(100) NULL DEFAULT NULL AFTER phone";
        $conn->exec($sql);
        echo "✅ Successfully added department column to users table.\n";
    } else {
        echo "ℹ️  Column department already exists in users table.\n";
    }

    // Check if bio column exists in users table
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'bio'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE users 
                ADD COLUMN bio TEXT NULL DEFAULT NULL AFTER department";
        $conn->exec($sql);
        echo "✅ Successfully added bio column to users table.\n";
    } else {
        echo "ℹ️  Column bio already exists in users table.\n";
    }

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
