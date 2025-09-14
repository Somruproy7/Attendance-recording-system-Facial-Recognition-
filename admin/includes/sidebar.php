<?php
// sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<aside class="sidebar">
    <h2>FULL ATTEND</h2>
    <nav>
        <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="../student_directory.php" class="<?php echo $current_page === 'student_directory.php' ? 'active' : ''; ?>">
            <i class="fas fa-address-book"></i> Student Directory
        </a>
        <a href="lecturer_management.php" class="<?php echo $current_page === 'lecturer_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-chalkboard-teacher"></i> Lecturers
        </a>
        <a href="class_management.php" class="<?php echo $current_page === 'class_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-chalkboard"></i> Classes
        </a>
        <a href="timetable_management.php" class="<?php echo $current_page === 'timetable_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i> Timetable
        </a>
        <a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
        <a href="settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> Settings
        </a>
        <a href="../logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
    <div class="logos">
        <img src="../images/fullattend_logo.png" alt="FullAttend Logo">
    </div>
</aside>

<style>
.sidebar {
    width: 250px;
    background: #1a237e;
    color: white;
    padding: 20px 0;
    display: flex;
    flex-direction: column;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
}

.sidebar h2 {
    color: white;
    text-align: center;
    margin: 0 0 30px 0;
    padding: 0 20px;
    font-size: 1.5em;
}

.sidebar nav {
    flex: 1;
    overflow-y: auto;
    padding: 0 10px;
}

.sidebar a {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    border-radius: 4px;
    margin: 5px 10px;
    transition: all 0.3s;
}

.sidebar a i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
}

.sidebar a:hover {
    background: rgba(255,255,255,0.1);
    color: white;
}

.sidebar a.active {
    background: #3949ab;
    color: white;
    font-weight: 500;
}

.sidebar .logout {
    margin-top: auto;
    border-top: 1px solid rgba(255,255,255,0.1);
    padding-top: 15px;
    margin-top: 15px;
}

.sidebar .logos {
    padding: 20px;
    text-align: center;
    border-top: 1px solid rgba(255,255,255,0.1);
    margin-top: auto;
}

.sidebar .logos img {
    max-width: 80%;
    margin: 5px 0;
    display: block;
    margin-left: auto;
    margin-right: auto;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .sidebar {
        width: 60px;
        padding: 10px 0;
    }
    
    .sidebar h2, 
    .sidebar a span {
        display: none;
    }
    
    .sidebar a {
        justify-content: center;
        padding: 15px 0;
        margin: 2px 5px;
    }
    
    .sidebar a i {
        margin-right: 0;
        font-size: 1.2em;
    }
    
    .sidebar .logos {
        padding: 10px 5px;
    }
    
    .sidebar .logos img {
        max-width: 100%;
    }
}
</style>
