<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Adding 'lecturer' to user_type enum...\n";
    
    // Check if lecturer is already in the enum
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'user_type'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (strpos($column['Type'], 'lecturer') === false) {
        // Add lecturer to the enum
        $stmt = $conn->exec("ALTER TABLE users MODIFY COLUMN user_type ENUM('admin', 'student', 'lecturer') NOT NULL");
        echo "✓ Successfully added 'lecturer' to user_type enum\n";
    } else {
        echo "✓ 'lecturer' already exists in user_type enum\n";
    }
    
    // Also add more lecturer-related fields if they don't exist
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'department'");
    if (!$stmt->fetch()) {
        $conn->exec("ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL");
        echo "✓ Added department column to users table\n";
    } else {
        echo "✓ Department column already exists in users table\n";
    }
    
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'bio'");
    if (!$stmt->fetch()) {
        $conn->exec("ALTER TABLE users ADD COLUMN bio TEXT DEFAULT NULL");
        echo "✓ Added bio column to users table\n";
    } else {
        echo "✓ Bio column already exists in users table\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
