<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Adding sample lecturer user...\n";
    
    // Check if lecturer already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = 'lecturer@cihe.edu.au'");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        // Create sample lecturer
        $stmt = $conn->prepare("
            INSERT INTO users (user_id, username, email, password_hash, user_type, first_name, last_name, phone, department, bio, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            'LEC001',
            'dr.smith',
            'lecturer@cihe.edu.au',
            password_hash('password123', PASSWORD_DEFAULT), // Default password: password123
            'lecturer',
            'Dr. Jane',
            'Smith',
            '+61 3 9999 0001',
            'Computer Science',
            'Dr. Jane Smith is a Senior Lecturer in Computer Science with over 10 years of experience in software development and machine learning.',
            'active'
        ]);
        
        $lecturer_id = $conn->lastInsertId();
        echo "✓ Created lecturer user: dr.smith (ID: $lecturer_id)\n";
        echo "  Email: lecturer@cihe.edu.au\n";
        echo "  Default password: password123\n";
        
        // Assign lecturer to existing classes
        echo "\nAssigning lecturer to classes...\n";
        
        // Check if class_lecturers table exists
        $stmt = $conn->query("SHOW TABLES LIKE 'class_lecturers'");
        if ($stmt->fetch()) {
            // Assign to existing classes (IDs 1-4 from schema)
            $classes = [1, 2, 3, 4];
            $stmt = $conn->prepare("
                INSERT IGNORE INTO class_lecturers (class_id, lecturer_id) 
                VALUES (?, ?)
            ");
            
            foreach ($classes as $class_id) {
                $stmt->execute([$class_id, $lecturer_id]);
                echo "  ✓ Assigned to class ID: $class_id\n";
            }
        } else {
            echo "  ⚠ class_lecturers table not found - skipping class assignments\n";
        }
        
    } else {
        echo "✓ Lecturer user already exists\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\n📝 Login credentials:\n";
    echo "   Email: lecturer@cihe.edu.au\n";
    echo "   Password: password123\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
