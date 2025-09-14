<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Creating class_lecturers table...\n";
    
    // Check if table already exists
    $stmt = $conn->query("SHOW TABLES LIKE 'class_lecturers'");
    if (!$stmt->fetch()) {
        // Create class_lecturers table
        $sql = "
        CREATE TABLE class_lecturers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            class_id INT NOT NULL,
            lecturer_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_class_lecturer (class_id, lecturer_id)
        )";
        
        $conn->exec($sql);
        echo "✓ Created class_lecturers table\n";
    } else {
        echo "✓ class_lecturers table already exists\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
