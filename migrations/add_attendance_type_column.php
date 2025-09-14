<?php
// migrations/add_attendance_type_column.php
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Check if the column already exists
    $stmt = $conn->query("SHOW COLUMNS FROM `attendance_records` LIKE 'attendance_type'");
    $columnExists = $stmt->rowCount() > 0;
    
    if (!$columnExists) {
        // Add the attendance_type column with a default value of 'student'
        $sql = "ALTER TABLE `attendance_records` 
                ADD COLUMN `attendance_type` VARCHAR(20) NOT NULL DEFAULT 'student' COMMENT 'student or lecturer_start'";
        $conn->exec($sql);
        
        echo "Successfully added 'attendance_type' column to 'attendance_records' table.\n";
    } else {
        echo "'attendance_type' column already exists in 'attendance_records' table.\n";
    }
    
    // Add index for better performance
    $stmt = $conn->query("SHOW INDEX FROM `attendance_records` WHERE Key_name = 'idx_attendance_type'");
    $indexExists = $stmt->rowCount() > 0;
    
    if (!$indexExists) {
        $sql = "CREATE INDEX `idx_attendance_type` ON `attendance_records` (`attendance_type`)";
        $conn->exec($sql);
        echo "Successfully added index on 'attendance_type' column.\n";
    } else {
        echo "Index on 'attendance_type' column already exists.\n";
    }
    
    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
}

// Run the migration
// This script should be run once from the command line with: php migrations/add_attendance_type.php
