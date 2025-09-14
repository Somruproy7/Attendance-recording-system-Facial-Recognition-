<?php
// Get current page and set active class
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Ensure database connection
if (!isset($conn)) {
    if (!class_exists('Database')) {
        require_once '../config/database.php';
    }
    $db = new Database();
    $conn = $db->getConnection();
}

// Get lecturer's classes count
$class_count = 0;
$active_sessions = 0;
$total_students = 0;

if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT c.id) as class_count
            FROM classes c
            JOIN class_lecturers cl ON c.id = cl.class_id
            WHERE cl.lecturer_id = ? AND c.status = 'active'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $class_count = $result ? $result['class_count'] : 0;
        
        // Get active sessions count
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT si.id) as active_sessions
            FROM session_instances si
            JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
            JOIN class_lecturers cl ON ts.class_id = cl.class_id
            WHERE cl.lecturer_id = ? 
            AND si.status = 'in_progress'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $active_sessions = $result ? $result['active_sessions'] : 0;
        
        // Get total students count
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT se.student_id) as total_students
            FROM student_enrollments se
            JOIN class_lecturers cl ON se.class_id = cl.class_id
            WHERE cl.lecturer_id = ? AND se.status = 'enrolled'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_students = $result ? $result['total_students'] : 0;
    } catch (PDOException $e) {
        // Handle error silently for sidebar
        $class_count = 0;
        $active_sessions = 0;
        $total_students = 0;
    }
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo $current_dir === 'sessions' ? '../../images/fullattend_logo.png' : '../images/fullattend_logo.png'; ?>" alt="FullAttend Logo" class="logo">
        <h2>LECTURER PANEL</h2>
    </div>
    
    <nav class="sidebar-nav">
        <?php 
        $dashboard_link = $current_dir === 'sessions' ? '../dashboard.php' : 'dashboard.php';
        $sessions_link = $current_dir === 'sessions' ? 'index.php' : 'sessions/';
        $attendance_link = $current_dir === 'sessions' ? '../attendance_report.php' : 'attendance_report.php';
        $reports_link = $current_dir === 'sessions' ? '../reports.php' : 'reports.php';
        ?>
        
        <a href="<?php echo $dashboard_link; ?>" class="nav-link <?php echo in_array($current_page, ['dashboard.php', 'lecturer_dashboard.php']) ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
        
        <a href="<?php echo $sessions_link; ?>" class="nav-link <?php echo in_array($current_page, ['index.php', 'sessions.php', 'create.php', 'view.php', 'edit.php']) || $current_dir === 'sessions' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i>
            <span>My Sessions</span>
        </a>
        
        <a href="<?php echo $attendance_link; ?>" class="nav-link <?php echo in_array($current_page, ['session_attendance.php', 'attendance_report.php']) ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i>
            <span>Attendance</span>
        </a>
        
        <a href="<?php echo $reports_link; ?>" class="nav-link <?php echo in_array($current_page, ['dashboard_statistics.php', 'reports.php']) ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        
        <div class="nav-divider"></div>
        
        <a href="<?php echo $current_dir === 'sessions' ? '../class_management.php' : 'class_management.php'; ?>" class="nav-link <?php echo $current_page === 'class_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>My Classes</span>
        </a>
        
        <a href="<?php echo $current_dir === 'sessions' ? '../profile.php' : 'profile.php'; ?>" class="nav-link <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-cog"></i>
            <span>Profile</span>
        </a>
    </nav>
    
    <!-- Quick Stats -->
    <div class="sidebar-stats">
        <div class="stat-item">
            <span class="stat-value"><?= $class_count ?></span>
            <span class="stat-label">My Classes</span>
        </div>
        <div class="stat-item">
            <span class="stat-value"><?= $active_sessions ?></span>
            <span class="stat-label">Active Sessions</span>
        </div>
        <div class="stat-item">
            <span class="stat-value"><?= $total_students ?></span>
            <span class="stat-label">Total Students</span>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <a href="<?php echo $current_dir === 'sessions' ? '../profile.php' : 'profile.php'; ?>" class="user-info" style="text-decoration: none; color: inherit;">
            <div class="user-avatar">
                <?php 
                // Get user profile image if available
                $profile_image = null;
                if (isset($_SESSION['user_id'])) {
                    try {
                        $user_stmt = $conn->prepare("SELECT profile_image, first_name, last_name FROM users WHERE id = ?");
                        $user_stmt->execute([$_SESSION['user_id']]);
                        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        $profile_image = $user_data['profile_image'] ?? null;
                        $first_name = $user_data['first_name'] ?? $_SESSION['first_name'] ?? '';
                        $last_name = $user_data['last_name'] ?? $_SESSION['last_name'] ?? '';
                    } catch (Exception $e) {
                        $first_name = $_SESSION['first_name'] ?? '';
                        $last_name = $_SESSION['last_name'] ?? '';
                    }
                } else {
                    $first_name = $_SESSION['first_name'] ?? '';
                    $last_name = $_SESSION['last_name'] ?? '';
                }
                
                if (!empty($profile_image) && file_exists('../uploads/profiles/' . $profile_image)): ?>
                    <img src="../uploads/profiles/<?= htmlspecialchars($profile_image) ?>" 
                         alt="Profile" class="rounded-circle" width="40" height="40" style="object-fit: cover;">
                <?php else:
                    $initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
                    echo $initials;
                endif;
                ?>
            </div>
            <div class="user-details">
                <div class="user-name">
                    <?= htmlspecialchars(trim($first_name . ' ' . $last_name)) ?: 'Lecturer' ?>
                </div>
                <div class="user-role">Lecturer</div>
            </div>
        </a>
        <a href="../logout.php" class="logout-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Logout</span>
        </a>
    </div>
</aside>

<style>
.sidebar {
    width: 280px;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
    color: #f8fafc;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 20px rgba(0,0,0,0.1);
    z-index: 1000;
    overflow-y: auto;
}

.sidebar-header {
    padding: 2rem;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.05);
}

.sidebar-header .logo {
    max-width: 100px;
    margin-bottom: 1rem;
    border-radius: 8px;
}

.sidebar-header h2 {
    margin: 0;
    font-size: 1rem;
    color: #f1f5f9;
    font-weight: 700;
    letter-spacing: 1px;
}

.sidebar-nav {
    flex: 1;
    padding: 1rem 0;
}

.nav-link {
    display: flex;
    align-items: center;
    padding: 1rem 1.5rem;
    color: #cbd5e1;
    text-decoration: none;
    transition: all 0.3s ease;
    font-weight: 500;
    border-left: 3px solid transparent;
}

.nav-link:hover {
    background: rgba(255,255,255,0.1);
    color: #f1f5f9;
    border-left-color: rgba(255,255,255,0.3);
}

.nav-link.active {
    background: rgba(59, 130, 246, 0.2);
    color: white;
    border-left-color: #3b82f6;
}

.nav-link i {
    margin-right: 12px;
    width: 20px;
    font-size: 18px;
}

.nav-divider {
    height: 1px;
    background: rgba(255,255,255,0.1);
    margin: 1rem 0;
}

.sidebar-stats {
    padding: 1rem 1.5rem;
    background: rgba(0,0,0,0.1);
    border-top: 1px solid rgba(255,255,255,0.1);
}

.stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
}

.stat-value {
    font-weight: 700;
    font-size: 1.25rem;
    color: #60a5fa;
}

.stat-label {
    font-size: 0.875rem;
    color: #94a3b8;
}

.sidebar-footer {
    padding: 15px;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.user-info {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
    cursor: pointer;
}

.user-info:hover {
    background: rgba(255,255,255,0.08);
    transform: translateY(-1px);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #3498db;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 10px;
}

.user-details {
    flex: 1;
}

.user-name {
    font-weight: 500;
    font-size: 14px;
}

.user-role {
    font-size: 12px;
    color: #7f8c8d;
}

.logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 10px;
    background: #e74c3c;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.3s ease;
}

.logout-btn:hover {
    background: #c0392b;
}

.logout-btn svg {
    margin-right: 5px;
}
</style>
