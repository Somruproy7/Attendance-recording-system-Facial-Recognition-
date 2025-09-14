<?php
// Migration to create session_logs table if it doesn't exist
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Check if session_logs table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'session_logs'");
    if ($stmt->rowCount() == 0) {
        // Create the session_logs table
        $sql = "CREATE TABLE session_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_instance_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            performed_by INT NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_instance_id) REFERENCES session_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_session_instance (session_instance_id),
            INDEX idx_performed_by (performed_by),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        )";
        
        $conn->exec($sql);
        echo "✅ Successfully created session_logs table.\n";
    } else {
        echo "ℹ️  Table session_logs already exists.\n";
    }

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
