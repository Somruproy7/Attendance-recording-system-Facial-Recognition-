<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <h2>STUDENT PANEL</h2>
    <nav>
        <a href="student_dashboard.php" class="<?php echo $current_page === 'student_dashboard.php' ? 'active' : ''; ?>">Dashboard</a><a href="give_attendance.php" class="<?php echo $current_page === 'give_attendance.php' ? 'active' : ''; ?>">Give Attendance</a><a href="my_attendance.php" class="<?php echo $current_page === 'my_attendance.php' ? 'active' : ''; ?>">My Attendance</a><a href="student_timetable.php" class="<?php echo $current_page === 'student_timetable.php' ? 'active' : ''; ?>">Timetable</a><a href="settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">Settings</a><a href="../logout.php" class="logout">Logout</a>
    </nav>
    <div class="logos vertical-logos">
        <img src="../images/fullattend_logo.png" alt="FullAttend Logo" class="fullattend-logo">
    </div>
</aside>
