-- FullAttend Database Migration Script
-- This script adds missing tables and columns to enhance the existing database
-- Run this to update your current database with the new features

USE fullattend_db;

-- 1. Add 'lecturer' to user_type enum if not exists
ALTER TABLE users MODIFY COLUMN user_type ENUM('admin', 'student', 'lecturer') NOT NULL;

-- 2. Add missing columns to users table (check if exists first)
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'fullattend_db' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'department');
SET @sql = IF(@column_exists = 0, 'ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL', 'SELECT "Column department already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'fullattend_db' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'bio');
SET @sql = IF(@column_exists = 0, 'ALTER TABLE users ADD COLUMN bio TEXT DEFAULT NULL', 'SELECT "Column bio already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add missing columns to classes table
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'fullattend_db' AND TABLE_NAME = 'classes' AND COLUMN_NAME = 'academic_year');
SET @sql = IF(@column_exists = 0, 'ALTER TABLE classes ADD COLUMN academic_year VARCHAR(20) DEFAULT "2025-2026"', 'SELECT "Column academic_year already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add attendance_code column to session_instances table
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'fullattend_db' AND TABLE_NAME = 'session_instances' AND COLUMN_NAME = 'attendance_code');
SET @sql = IF(@column_exists = 0, 'ALTER TABLE session_instances ADD COLUMN attendance_code VARCHAR(10) DEFAULT NULL', 'SELECT "Column attendance_code already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Create class_lecturers table for lecturer-class assignments
CREATE TABLE IF NOT EXISTS class_lecturers (
    id INT NOT NULL AUTO_INCREMENT,
    class_id INT NOT NULL,
    lecturer_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_class_lecturer (class_id, lecturer_id),
    KEY lecturer_id (lecturer_id),
    CONSTRAINT class_lecturers_ibfk_1 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT class_lecturers_ibfk_2 FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 6. Create enrollment_requests table for student enrollment requests
CREATE TABLE IF NOT EXISTS enrollment_requests (
    id INT NOT NULL AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    processed_by INT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_student_class_pending (student_id, class_id, status),
    KEY idx_status (status),
    KEY class_id (class_id),
    KEY processed_by (processed_by),
    CONSTRAINT enrollment_requests_ibfk_1 FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT enrollment_requests_ibfk_2 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT enrollment_requests_ibfk_3 FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 7. Create session_logs table for tracking session actions
CREATE TABLE IF NOT EXISTS session_logs (
    id INT NOT NULL AUTO_INCREMENT,
    session_instance_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    performed_by INT NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session_instance (session_instance_id),
    KEY idx_performed_by (performed_by),
    KEY idx_action (action),
    KEY idx_created_at (created_at),
    CONSTRAINT session_logs_ibfk_1 FOREIGN KEY (session_instance_id) REFERENCES session_instances(id) ON DELETE CASCADE,
    CONSTRAINT session_logs_ibfk_2 FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE CASCADE
);

-- 8. Create lecturer_profiles table (optional - for additional lecturer info)
CREATE TABLE IF NOT EXISTS lecturer_profiles (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    staff_id VARCHAR(50) NOT NULL,
    department VARCHAR(100) DEFAULT NULL,
    office_location VARCHAR(100) DEFAULT NULL,
    office_hours TEXT,
    bio TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY staff_id (staff_id),
    KEY user_id (user_id),
    CONSTRAINT lecturer_profiles_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 9. Create lecturers table (legacy support - may not be needed)
CREATE TABLE IF NOT EXISTS lecturers (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    staff_id VARCHAR(50) NOT NULL,
    department VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY staff_id (staff_id),
    KEY user_id (user_id),
    CONSTRAINT lecturers_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 10. Add sample lecturer if not exists
INSERT IGNORE INTO users (user_id, username, email, password_hash, user_type, first_name, last_name, phone, department, bio, status) VALUES
('LEC001', 'dr.smith', 'lecturer@cihe.edu.au', '$2y$10$kHtFMgaLhgo6lg8SfYkoAO6ESRCfWPjVTtBx9GshvIQFhSDwc6hz.', 'lecturer', 'Dr. Jane', 'Smith', '+61 3 9999 0001', 'Computer Science', 'Dr. Jane Smith is a Senior Lecturer in Computer Science with over 10 years of experience in software development and machine learning.', 'active');

-- 11. Assign sample lecturer to all classes (get lecturer ID first)
SET @lecturer_id = (SELECT id FROM users WHERE user_id = 'LEC001' LIMIT 1);

INSERT IGNORE INTO class_lecturers (class_id, lecturer_id) 
SELECT id, @lecturer_id FROM classes WHERE @lecturer_id IS NOT NULL;

-- 12. Update system settings with new ones if they don't exist
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('system_name', 'Full Attend', 'Name of the attendance system'),
('school_name', 'CIHE', 'Name of the educational institution'),
('timezone', 'Australia/Melbourne', 'System timezone'),
('admin_email', 'admin@cihe.edu.au', 'Administrator email address'),
('announcements', '', 'System announcements');

-- Migration completed successfully
SELECT 'Database migration completed successfully!' as Status;
