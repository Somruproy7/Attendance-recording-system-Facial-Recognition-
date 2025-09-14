<?php
// migrations/add_reset_columns.php
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Check if reset_token column exists
    $stmt = $conn->query("SHOW COLUMNS FROM `users` LIKE 'reset_token'");
    $tokenColumnExists = $stmt->rowCount() > 0;
    
    // Check if reset_expires column exists
    $stmt = $conn->query("SHOW COLUMNS FROM `users` LIKE 'reset_expires'");
    $expiresColumnExists = $stmt->rowCount() > 0;
    
    // Add reset_token column if it doesn't exist
    if (!$tokenColumnExists) {
        $sql = "ALTER TABLE `users` 
                ADD COLUMN `reset_token` VARCHAR(64) NULL DEFAULT NULL,
                ADD INDEX `idx_reset_token` (`reset_token`)";
        $conn->exec($sql);
        echo "Successfully added 'reset_token' column to 'users' table.\n";
    } else {
        echo "'reset_token' column already exists in 'users' table.\n";
    }
    
    // Add reset_expires column if it doesn't exist
    if (!$expiresColumnExists) {
        $sql = "ALTER TABLE `users` 
                ADD COLUMN `reset_expires` DATETIME NULL DEFAULT NULL";
        $conn->exec($sql);
        echo "Successfully added 'reset_expires' column to 'users' table.\n";
    } else {
        echo "'reset_expires' column already exists in 'users' table.\n";
    }
    
    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
}

// Run the migration
// This script should be run once from the command line with: php migrations/add_reset_columns.php
