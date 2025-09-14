<?php
// ensure_enrollment_requests.php
// Creates enrollment_requests table if it does not exist

require_once __DIR__ . '/config/database.php';

if (!function_exists('ensure_enrollment_requests_table')) {
    function ensure_enrollment_requests_table(PDO $conn): void {
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS enrollment_requests (
                id INT PRIMARY KEY AUTO_INCREMENT,
                student_id INT NOT NULL,
                class_id INT NOT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
                reason TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                processed_by INT NULL,
                UNIQUE KEY uniq_student_class_pending (student_id, class_id, status),
                INDEX idx_status (status),
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            ");
        } catch (Throwable $e) {
            // no-op; don't block page
        }
    }
}

?>


