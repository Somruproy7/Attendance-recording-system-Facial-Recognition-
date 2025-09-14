<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Assigning lecturer to classes...\n";
    
    // Find the lecturer
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = 'lecturer@cihe.edu.au' AND user_type = 'lecturer'");
    $stmt->execute();
    $lecturer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lecturer) {
        echo "❌ Lecturer not found. Please run add_sample_lecturer.php first.\n";
        exit(1);
    }
    
    $lecturer_id = $lecturer['id'];
    echo "Found lecturer with ID: $lecturer_id\n";
    
    // Check if class_lecturers table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'class_lecturers'");
    if (!$stmt->fetch()) {
        echo "❌ class_lecturers table not found. Please run create_class_lecturers_table.php first.\n";
        exit(1);
    }
    
    // Assign to existing classes (IDs 1-4 from schema)
    $classes = [1, 2, 3, 4];
    $stmt = $conn->prepare("
        INSERT IGNORE INTO class_lecturers (class_id, lecturer_id) 
        VALUES (?, ?)
    ");
    
    foreach ($classes as $class_id) {
        try {
            $stmt->execute([$class_id, $lecturer_id]);
            echo "  ✓ Assigned to class ID: $class_id\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "  ⚠ Already assigned to class ID: $class_id\n";
            } else {
                echo "  ❌ Failed to assign to class ID: $class_id - " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
