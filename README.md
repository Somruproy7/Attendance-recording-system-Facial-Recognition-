# FullAttend - Facial Recognition Attendance System

A comprehensive facial recognition-based attendance system for educational institutions, built with PHP, MySQL, and Python.

## 🎯 Project Overview

FullAttend is a complete attendance management system that uses facial recognition technology to automatically mark student attendance. The system includes:

- **Web-based Management Interface** (PHP/MySQL)
- **Facial Recognition Engine** (Python)
- **Student & Class Management**
- **Timetable Management**
- **Comprehensive Reporting System**
- **Real-time Attendance Tracking**

## 🏗️ System Architecture

### Frontend (Web Interface)
- **PHP 7.4+** with PDO for database operations
- **HTML5/CSS3** with responsive design
- **JavaScript** for interactive features
- **Bootstrap-like** custom styling

### Backend (Database)
- **MySQL 5.7+** database
- **Normalized schema** with proper relationships
- **Optimized queries** for performance

### Facial Recognition (Python Application)
- **OpenCV** for video capture and image processing
- **face_recognition** library for facial detection and recognition
- **MySQL Connector** for database integration
- **Tkinter** GUI for standalone operation

## 📋 Features

### Admin Features
- ✅ **Student Management**: Register, enroll, and manage students
- ✅ **Class Management**: Create and manage classes
- ✅ **Timetable Management**: Schedule sessions and generate instances
- ✅ **Facial Recognition Control**: Start/stop attendance sessions
- ✅ **Comprehensive Reports**: Multiple report types with export options
- ✅ **Real-time Monitoring**: Live attendance tracking

### Student Features
- ✅ **Personal Dashboard**: View attendance statistics
- ✅ **Timetable View**: See scheduled classes
- ✅ **Attendance History**: Detailed attendance records
- ✅ **Profile Management**: Update personal information

### Facial Recognition Features
- ✅ **Automatic Face Detection**: Real-time face detection
- ✅ **Face Registration**: Register student faces for recognition
- ✅ **Attendance Marking**: Automatic attendance recording
- ✅ **Confidence Scoring**: Recognition confidence levels
- ✅ **Duplicate Prevention**: Avoid multiple recordings

## 🚀 Installation & Setup

### Prerequisites
- **XAMPP** (Apache + MySQL + PHP)
- **Python 3.8+**
- **Webcam** for facial recognition

### 1. Database Setup
```bash
# Import the database schema
mysql -u root -p < fullattend_db_schema.sql
```

### 2. Web Application Setup
```bash
# Copy files to XAMPP htdocs directory
cp -r fullattend/ C:/xampp/htdocs/

# Set proper permissions
chmod 755 uploads/profiles/
```

### 3. Python Dependencies
```bash
# Install required Python packages
pip install -r requirements.txt
```

### 4. Configuration
Edit `config/database.php`:
```php
private $host = 'localhost';
private $db_name = 'fullattend_db';
private $username = 'root';
private $password = ''; // Your MySQL password
```

## 📊 Database Schema

### Core Tables
- **users**: Student and admin accounts
- **classes**: Course information
- **student_enrollments**: Student-class relationships
- **timetable_sessions**: Class schedules
- **session_instances**: Individual class sessions
- **attendance_records**: Attendance data
- **student_face_encodings**: Facial recognition data

### Key Relationships
- Students are enrolled in classes
- Classes have scheduled sessions
- Sessions generate instances for attendance
- Face encodings link to students
- Attendance records track student presence

## 🎮 Usage Guide

### Admin Workflow
1. **Login** to admin dashboard
2. **Register Students** with facial recognition
3. **Create Classes** and enroll students
4. **Schedule Sessions** in timetable
5. **Start Attendance Sessions** for facial recognition
6. **Generate Reports** for analysis

### Student Workflow
1. **Login** to student dashboard
2. **View Schedule** and attendance history
3. **Check Attendance** statistics
4. **Update Profile** information

### Facial Recognition Workflow
1. **Start Python Application**: `python facial_recognition_system.py`
2. **Select Session** from dropdown
3. **Register Faces** for new students
4. **Start Recognition** for attendance
5. **Monitor Results** in real-time

## 📈 Reports Available

### 1. Attendance Report
- Individual student attendance by class
- Attendance percentages and statistics
- Filter by date range and class

### 2. Class Summary Report
- Overall class performance
- Average attendance rates
- Student enrollment statistics

### 3. Daily Attendance Report
- Session-by-session attendance
- Real-time attendance tracking
- Room and time slot analysis

### 4. Student Performance Report
- Individual student statistics
- Attendance trends over time
- Performance comparisons

## 🔧 Technical Details

### Facial Recognition Process
1. **Face Detection**: Locate faces in video frames
2. **Feature Extraction**: Generate face encodings
3. **Database Comparison**: Match against registered faces
4. **Confidence Scoring**: Calculate recognition confidence
5. **Attendance Recording**: Save to database with metadata

### Security Features
- **Password Hashing**: bcrypt encryption
- **Session Management**: Secure PHP sessions
- **Input Sanitization**: XSS prevention
- **SQL Injection Protection**: Prepared statements
- **CSRF Protection**: Token-based validation

### Performance Optimizations
- **Database Indexing**: Optimized queries
- **Face Encoding Caching**: Reduced processing time
- **Batch Processing**: Efficient attendance recording
- **Connection Pooling**: Database performance

## 🐛 Troubleshooting

### Common Issues

#### Database Connection Error
```bash
# Check XAMPP services
# Verify database credentials in config/database.php
# Ensure MySQL is running
```

#### Facial Recognition Not Working
```bash
# Check Python dependencies
pip install -r requirements.txt

# Verify webcam access
# Check database connection in Python script
```

#### Attendance Not Recording
```bash
# Verify session status is 'in_progress'
# Check face registration status
# Ensure proper database permissions
```

### Debug Mode
Enable debug logging in `config/database.php`:
```php
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
```

## 📝 API Documentation

### Database API
The system uses PDO for database operations with prepared statements for security.

### Facial Recognition API
The Python application provides:
- Face registration endpoints
- Real-time recognition
- Attendance recording
- Session management

## 🔄 Updates & Maintenance

### Regular Maintenance
- **Database Backups**: Daily automated backups
- **Log Rotation**: Manage application logs
- **Performance Monitoring**: Track system performance
- **Security Updates**: Regular dependency updates

### Version Control
- **Git Repository**: Track code changes
- **Database Migrations**: Schema version control
- **Configuration Management**: Environment-specific settings

## 📞 Support

### Documentation
- **User Manual**: Complete usage guide
- **API Documentation**: Technical reference
- **Troubleshooting Guide**: Common issues and solutions

### Contact
For technical support or feature requests, please contact the development team.

## 📄 License

This project is developed for educational purposes. Please ensure compliance with local privacy and data protection regulations when deploying in production environments.

## 🎉 Acknowledgments

- **OpenCV** for computer vision capabilities
- **face_recognition** library for facial recognition
- **PHP PDO** for secure database operations
- **XAMPP** for local development environment

---

**FullAttend** - Making attendance tracking intelligent and efficient! 🎓✨
