<?php
// Migration to add attendance_code column to session_instances table
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Check if column already exists
    $stmt = $conn->query("SHOW COLUMNS FROM session_instances LIKE 'attendance_code'");
    if ($stmt->rowCount() == 0) {
        // Add the attendance_code column
        $sql = "ALTER TABLE session_instances 
                ADD COLUMN attendance_code VARCHAR(10) NULL DEFAULT NULL AFTER status";
        
        $conn->exec($sql);
        echo "✅ Successfully added attendance_code column to session_instances table.\n";
    } else {
        echo "ℹ️  Column attendance_code already exists in session_instances table.\n";
    }
    
    // Check if actual_start_time column exists
    $stmt = $conn->query("SHOW COLUMNS FROM session_instances LIKE 'actual_start_time'");
    if ($stmt->rowCount() == 0) {
        // Add the actual_start_time column
        $sql = "ALTER TABLE session_instances 
                ADD COLUMN actual_start_time TIMESTAMP NULL DEFAULT NULL AFTER attendance_code";
        
        $conn->exec($sql);
        echo "✅ Successfully added actual_start_time column to session_instances table.\n";
    } else {
        echo "ℹ️  Column actual_start_time already exists in session_instances table.\n";
    }
    
    // Check if actual_end_time column exists
    $stmt = $conn->query("SHOW COLUMNS FROM session_instances LIKE 'actual_end_time'");
    if ($stmt->rowCount() == 0) {
        // Add the actual_end_time column
        $sql = "ALTER TABLE session_instances 
                ADD COLUMN actual_end_time TIMESTAMP NULL DEFAULT NULL AFTER actual_start_time";
        
        $conn->exec($sql);
        echo "✅ Successfully added actual_end_time column to session_instances table.\n";
    } else {
        echo "ℹ️  Column actual_end_time already exists in session_instances table.\n";
    }

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
