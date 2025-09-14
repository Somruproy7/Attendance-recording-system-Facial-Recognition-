-- Full Attend Database Schema
-- Drop database if exists and create new one
DROP DATABASE IF EXISTS fullattend_db;
CREATE DATABASE fullattend_db;
USE fullattend_db;

-- Users table (for both admin and students)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    user_type ENUM('admin', 'student') NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    profile_image VARCHAR(255),
    status ENUM('active', 'inactive', 'pending') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Classes table
CREATE TABLE classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_code VARCHAR(20) UNIQUE NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    description TEXT,
    instructor_id INT,
    semester VARCHAR(20) DEFAULT '2025-S1',
    year INT DEFAULT 2025,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Student enrollments
CREATE TABLE student_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    enrollment_date DATE DEFAULT (CURRENT_DATE),
    status ENUM('enrolled', 'dropped', 'completed') DEFAULT 'enrolled',
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (student_id, class_id)
);

-- Timetable sessions
CREATE TABLE timetable_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    session_title VARCHAR(100) NOT NULL,
    session_type ENUM('Lecture', 'Lab', 'Tutorial', 'Exam', 'Other') DEFAULT 'Lecture',
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room_location VARCHAR(50),
    instructor VARCHAR(100),
    recurring BOOLEAN DEFAULT FALSE,
    start_date DATE,
    end_date DATE,
    notes TEXT,
    status ENUM('scheduled', 'cancelled', 'completed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Individual session instances (for attendance tracking)
CREATE TABLE session_instances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    timetable_session_id INT NOT NULL,
    session_date DATE NOT NULL,
    actual_start_time DATETIME,
    actual_end_time DATETIME,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (timetable_session_id) REFERENCES timetable_sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_date (timetable_session_id, session_date)
);

-- Face encodings for students
CREATE TABLE student_face_encodings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    face_encoding TEXT NOT NULL, -- JSON encoded face features
    image_path VARCHAR(255),
    confidence_threshold FLOAT DEFAULT 0.6,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Attendance records
CREATE TABLE attendance_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_instance_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
    check_in_time DATETIME,
    recognition_confidence FLOAT,
    notes TEXT,
    marked_by ENUM('system', 'admin', 'manual') DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_instance_id) REFERENCES session_instances(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (session_instance_id, student_id)
);

-- System settings
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert initial data based on frontend
INSERT INTO users (user_id, username, email, password_hash, user_type, first_name, last_name, phone, status) VALUES
-- Admin users
('admin001', 'admin', 'admin@cihe.edu.au', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System', 'Administrator', '+61 3 9999 0000', 'active'),

-- Students based on frontend data
('CIHE240001', 'alice.lam', 'alice.lam@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Alice', 'Lam', '+61 400 000 001', 'active'),
('CIHE240045', 'ben.kumar', 'ben.kumar@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Ben', 'Kumar', '+61 400 000 045', 'inactive'),
('CIHE240078', 'chloe.singh', 'chloe.singh@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Chloe', 'Singh', '+61 400 000 078', 'active'),
('CIHE240102', 'dylan.li', 'dylan.li@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Dylan', 'Li', '+61 400 000 102', 'pending'),
('CIHE240126', 'ella.moore', 'ella.moore@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Ella', 'Moore', '+61 400 000 126', 'active'),
('CIHE240369', 'jane.doe', 'jane.doe@student.cihe.edu.au', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Jane', 'Doe', '+61 4xx xxx xxx', 'active'),
('CIHE240150', 'arbind.dangi', 'arbind.dangi@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Arbind', 'Dangi', '+61 400 000 150', 'active'),
('CIHE240200', 'elon.musk', 'elon.musk@example.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Elon', 'Musk', '+61 400 000 200', 'active');

-- Insert classes based on frontend
INSERT INTO classes (class_code, class_name, description, instructor_id, semester, year) VALUES
('CLASS101', 'Project-1', 'Software Development Project Phase 1', 1, '2025-S1', 2025),
('CLASS102', 'Mobile Application', 'Mobile Application Development', 1, '2025-S1', 2025),
('CLASS103', 'Business Ethic', 'Business Ethics and Professional Practice', 1, '2025-S1', 2025),
('CLASS104', 'Algorithms & DS', 'Algorithms and Data Structures', 1, '2025-S1', 2025);

-- Enroll students in classes based on frontend data
INSERT INTO student_enrollments (student_id, class_id) VALUES
-- Alice Lam - Class 101
(2, 1),
-- Ben Kumar - Class 102  
(3, 2),
-- Chloe Singh - Class 103
(4, 3),
-- Dylan Li - Class 104
(5, 4),
-- Ella Moore - Class 101
(6, 1),
-- Jane Doe - mixed classes
(7, 1), (7, 2), (7, 3), (7, 4),
-- Additional enrollments for demo
(8, 1), (8, 2),
(9, 1), (9, 3);

-- Insert timetable sessions based on frontend
INSERT INTO timetable_sessions (class_id, session_title, session_type, day_of_week, start_time, end_time, room_location, instructor, recurring, start_date, end_date) VALUES
-- Monday
(1, 'Project 1', 'Lecture', 'Monday', '09:00:00', '10:00:00', 'Room A1', 'Dr. Jane Smith', TRUE, '2025-05-01', '2025-08-01'),
(2, 'Mobile Application', 'Lab', 'Monday', '10:00:00', '11:00:00', 'Lab L1', 'Dr. John Doe', TRUE, '2025-05-01', '2025-08-01'),
(3, 'Business Ethics', 'Lecture', 'Monday', '13:00:00', '14:00:00', 'Room C3', 'Dr. Sarah Wilson', TRUE, '2025-05-01', '2025-08-01'),

-- Tuesday  
(1, 'Project 1', 'Lecture', 'Tuesday', '10:00:00', '11:00:00', 'Room A1', 'Dr. Jane Smith', TRUE, '2025-05-01', '2025-08-01'),
(4, 'Algorithms & DS', 'Lecture', 'Tuesday', '11:00:00', '12:00:00', 'Room B2', 'Dr. Mike Johnson', TRUE, '2025-05-01', '2025-08-01'),
(1, 'Consultation', 'Other', 'Tuesday', '14:00:00', '15:00:00', 'Office', 'Dr. Jane Smith', TRUE, '2025-05-01', '2025-08-01'),

-- Wednesday
(4, 'Algorithms & DS', 'Lecture', 'Wednesday', '09:00:00', '10:00:00', 'Room B2', 'Dr. Mike Johnson', TRUE, '2025-05-01', '2025-08-01'),
(2, 'Mobile Application', 'Lab', 'Wednesday', '13:00:00', '14:00:00', 'Lab L1', 'Dr. John Doe', TRUE, '2025-05-01', '2025-08-01'),

-- Thursday
(1, 'Tutorial', 'Tutorial', 'Thursday', '10:00:00', '11:00:00', 'Room T2', 'Dr. Jane Smith', TRUE, '2025-05-01', '2025-08-01'),
(4, 'Algorithms & DS - Lab', 'Lab', 'Thursday', '14:00:00', '15:00:00', 'Lab L2', 'Dr. Mike Johnson', TRUE, '2025-05-01', '2025-08-01'),

-- Friday
(3, 'Business Ethics', 'Lecture', 'Friday', '09:00:00', '10:00:00', 'Room C3', 'Dr. Sarah Wilson', TRUE, '2025-05-01', '2025-08-01'),
(1, 'Project 1 - Lab', 'Lab', 'Friday', '11:00:00', '12:00:00', 'Lab L1', 'Dr. Jane Smith', TRUE, '2025-05-01', '2025-08-01');

-- Create session instances for May 2025 (sample data)
INSERT INTO session_instances (timetable_session_id, session_date, actual_start_time, actual_end_time, status) VALUES
-- May 10, 2025 (Friday) - Business Ethics
(10, '2025-05-10', '2025-05-10 09:02:00', '2025-05-10 10:00:00', 'completed'),
-- May 12, 2025 (Monday) - Mobile Application
(2, '2025-05-12', '2025-05-12 10:08:00', '2025-05-12 11:00:00', 'completed'),
-- May 15, 2025 (Thursday) - Tutorial
(8, '2025-05-15', '2025-05-15 10:01:00', '2025-05-15 11:00:00', 'completed'),
-- May 18, 2025 (Sunday) - would be generated for next week
(7, '2025-05-18', NULL, NULL, 'scheduled'),
-- May 22, 2025 (Thursday) - Mobile Application
(2, '2025-05-22', '2025-05-22 10:00:00', '2025-05-22 11:00:00', 'completed');

-- Insert sample attendance records based on frontend data
INSERT INTO attendance_records (session_instance_id, student_id, status, check_in_time, recognition_confidence, notes) VALUES
-- Session 1 (May 10, Business Ethics) - Jane Doe present
(1, 7, 'present', '2025-05-10 09:02:00', 0.95, NULL),

-- Session 2 (May 12, Mobile Application) - Jane Doe late
(2, 7, 'late', '2025-05-12 10:08:00', 0.92, 'Traffic delay'),

-- Session 3 (May 15, Tutorial) - Jane Doe present  
(3, 7, 'present', '2025-05-15 12:01:00', 0.96, NULL),

-- Session 4 (May 18) - Jane Doe absent
(4, 7, 'absent', NULL, NULL, 'Sick leave'),

-- Session 5 (May 22, Mobile Application) - Jane Doe present
(5, 7, 'present', '2025-05-22 10:00:00', 0.94, NULL);

-- Insert system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('face_recognition_threshold', '0.6', 'Minimum confidence threshold for face recognition'),
('attendance_grace_period', '15', 'Grace period in minutes before marking late'),
('camera_resolution', '1280x720', 'Default camera resolution'),
('session_timeout', '30', 'Session timeout in minutes'),
('backup_frequency', '24', 'Database backup frequency in hours');

-- Create views for easier data access
CREATE VIEW student_attendance_summary AS
SELECT 
    u.id as student_id,
    u.user_id,
    CONCAT(u.first_name, ' ', u.last_name) as full_name,
    c.class_name,
    COUNT(ar.id) as total_sessions,
    SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
    SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count,
    ROUND((SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(ar.id)) * 100, 2) as attendance_percentage
FROM users u
JOIN student_enrollments se ON u.id = se.student_id
JOIN classes c ON se.class_id = c.id
LEFT JOIN session_instances si ON EXISTS(
    SELECT 1 FROM timetable_sessions ts 
    WHERE ts.class_id = c.id AND ts.id = si.timetable_session_id
)
LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id AND ar.student_id = u.id
WHERE u.user_type = 'student'
GROUP BY u.id, u.user_id, u.first_name, u.last_name, c.class_name;

CREATE VIEW class_session_summary AS
SELECT 
    c.id as class_id,
    c.class_code,
    c.class_name,
    si.session_date,
    ts.session_title,
    ts.room_location,
    si.status,
    COUNT(ar.id) as total_students,
    SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
    SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count
FROM classes c
JOIN timetable_sessions ts ON c.id = ts.class_id
JOIN session_instances si ON ts.id = si.timetable_session_id
LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id
GROUP BY c.id, c.class_code, c.class_name, si.session_date, ts.session_title, ts.room_location, si.status
ORDER BY si.session_date DESC;