<?php
// student_directory.php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Handle filters
$search = sanitize_input($_GET['search'] ?? '');
$class_filter = sanitize_input($_GET['class'] ?? '');

try {
    // Build the query with filters
    $where_conditions = ["u.user_type = 'student'"];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "(u.first_name LIKE :search1 OR u.last_name LIKE :search2 OR u.user_id LIKE :search3 OR u.email LIKE :search4)";
        $params['search1'] = "%$search%";
        $params['search2'] = "%$search%";
        $params['search3'] = "%$search%";
        $params['search4'] = "%$search%";
    }
    
    if ($class_filter) {
        $where_conditions[] = "c.class_code = :class_filter";
        $params['class_filter'] = $class_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get students with their class information and attendance stats
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.status,
            u.profile_image,
            GROUP_CONCAT(DISTINCT c.class_code ORDER BY c.class_code SEPARATOR ', ') as classes,
            COUNT(DISTINCT ar.id) as total_sessions,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND(
                CASE 
                    WHEN COUNT(DISTINCT ar.id) > 0 
                    THEN (SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(DISTINCT ar.id)) * 100 
                    ELSE 0 
                END, 1
            ) as attendance_percentage
        FROM users u
        LEFT JOIN student_enrollments se ON u.id = se.student_id AND se.status = 'enrolled'
        LEFT JOIN classes c ON se.class_id = c.id
        LEFT JOIN timetable_sessions ts ON c.id = ts.class_id
        LEFT JOIN session_instances si ON ts.id = si.timetable_session_id AND si.status = 'completed'
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id AND ar.student_id = u.id
        WHERE $where_clause
        GROUP BY u.id, u.user_id, u.first_name, u.last_name, u.email, u.phone, u.status, u.profile_image
        ORDER BY u.last_name, u.first_name
    ");
    
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    
    // Get all classes for filter dropdown
    $stmt = $conn->query("SELECT class_code, class_name FROM classes WHERE status = 'active' ORDER BY class_code");
    $all_classes = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $students = [];
    $all_classes = [];
}

function getInitials($firstName, $lastName) {
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}

function getStatusClass($status) {
    switch ($status) {
        case 'active': return 'present';
        case 'inactive': return 'absent';
        case 'pending': return 'late';
        default: return 'absent';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Student Directory - Full Attend</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>"/>
    <link rel="stylesheet" href="student.css?v=<?php echo time(); ?>"/>
</head>
<body class="admin-portal">
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>FULL ATTEND</h2>
            <nav>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="class_management.php">Class Management</a>
                <a href="student_directory.php" class="active">Student Directory</a>
                <a href="student_registration.php">Student Management</a>
                <a href="admin/lecturer_management.php">Lecturer Management</a>
                <a href="timetable_management.php">Timetable</a>
                <a href="reports.php">Reports</a>
                <a href="settings.php">Settings</a>
                <a href="logout.php">Logout</a>
            </nav>
            <div class="logos">
                <img src="images/fullattend_logo.png" alt="FullAttend Logo"/>
            </div>
        </aside>

        <!-- Main -->
        <main class="dashboard-content">
            <!-- Image Banner -->
            <div class="banner" style="width: 100%; height: 200px; overflow: hidden; border-radius: 8px; margin-bottom: 20px;">
                <img src="images/classroom-banner.jpg" alt="Classroom" style="width: 100%; height: 100%; object-fit: cover;">
            </div>
            
            <!-- Search and Filters -->
            <div class="search-section" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom: 15px;">
                    <div>
                        <h1 style="margin: 0; color: #1a237e;">Student Directory</h1>
                        <p class="muted" style="margin: 5px 0 0 0;">Search and manage student profiles (<?php echo count($students); ?> students found)</p>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <a href="student_registration.php" class="btn" style="background: #4f46e5; color: white; display: flex; align-items: center; gap: 5px; text-decoration: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Add Student
                        </a>
                        <form method="GET" style="display:flex; gap:8px; flex-wrap: wrap;">
                        <div style="position:relative; flex:1; min-width: 250px;">
                            <input 
                                type="text" 
                                name="search" 
                                placeholder="Search by name, ID, or email..." 
                                value="<?php echo htmlspecialchars($search); ?>"
                                class="search-input"
                                style="width: 100%; padding-left: 35px;"
                                id="searchInput"
                                autocomplete="off"
                            />
                            <div style="position:absolute; left:10px; top:50%; transform: translateY(-50%); color:#6b7280;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                        </div>
                        <select name="class" class="select" style="min-width: 180px;">
                            <option value="">All Classes</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class_code']); ?>" 
                                    <?php echo $class_filter === $class['class_code'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn" style="min-width: 100px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 5px;">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            Search
                        </button>
                        <?php if ($search || $class_filter): ?>
                            <a href="student_directory.php" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 5px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                                Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <!-- End Search and Filters -->
            
            <?php if ($search || $class_filter): ?>
                <div class="search-summary" style="margin-bottom: 20px; padding: 10px 15px; background: #f8f9ff; border-radius: 8px; border-left: 4px solid #4f46e5;">
                    <p style="margin: 0; color: #4b5563; font-size: 14px;">
                        <?php if ($search && $class_filter): ?>
                            Showing results for: <strong><?php echo htmlspecialchars($search); ?></strong> in <strong><?php echo htmlspecialchars($class_filter); ?></strong>
                        <?php elseif ($search): ?>
                            Showing results for: <strong><?php echo htmlspecialchars($search); ?></strong>
                        <?php else: ?>
                            Showing students in: <strong><?php echo htmlspecialchars($class_filter); ?></strong>
                        <?php endif; ?>
                        <a href="student_directory.php" style="margin-left: 10px; color: #4f46e5; text-decoration: none;">
                            Clear all filters
                        </a>
                    </p>
                </div>
            <?php endif; ?>    <?php if (!empty($_GET['msg'])): ?>
                <div class="success-message" style="max-width: 900px; width:100%; margin-top: 10px;">
                    <?php echo htmlspecialchars($_GET['msg']); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (isset($error)): ?>
                <div style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-top: 16px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <section class="scanning-status" style="margin-top:16px;">
                <table class="table" id="directoryTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Student ID</th>
                            <th>Classes</th>
                            <th>Email</th>
                            <th>Attendance</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #6b7280; padding: 30px;">
                                <?php echo $search || $class_filter ? 'No students found matching your criteria.' : 'No students found.'; ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <div class="student-name">
                                    <?php if ($student['profile_image'] && file_exists('uploads/profiles/' . $student['profile_image'])): ?>
                                        <img src="uploads/profiles/<?php echo htmlspecialchars($student['profile_image']); ?>" 
                                             class="avatar" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <span class="avatar"><?php echo getInitials($student['first_name'], $student['last_name']); ?></span>
                                    <?php endif; ?>
                                    <span class="name">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="sid"><?php echo htmlspecialchars($student['user_id']); ?></td>
                            <td class="cls"><?php echo htmlspecialchars($student['classes'] ?: 'Not enrolled'); ?></td>
                            <td class="email"><?php echo htmlspecialchars($student['email']); ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <span style="font-weight: 600;"><?php echo $student['attendance_percentage']; ?>%</span>
                                    <span class="muted" style="font-size: 0.8em;">
                                        (<?php echo $student['present_count']; ?>/<?php echo $student['total_sessions']; ?>)
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="pill">
                                    <span class="status-dot <?php echo getStatusClass($student['status']); ?>"></span> 
                                    <?php echo ucfirst($student['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <a class="btn" href="view_student.php?id=<?php echo $student['id']; ?>" title="View Details">View</a>
                                    <a class="btn" href="edit_student.php?id=<?php echo $student['id']; ?>" title="Edit" 
                                       style="background: #f59e0b; color: white;">Edit</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <!-- Summary Statistics -->
            <?php if (!empty($students)): ?>
            <div class="class-cards" style="margin-top: 20px;">
                <?php
                $total = count($students);
                $active = array_filter($students, fn($s) => $s['status'] === 'active');
                $avgAttendance = $total > 0 ? array_sum(array_column($students, 'attendance_percentage')) / $total : 0;
                ?>
                <div class="card">
                    <h3>Total Students</h3>
                    <p class="muted">In directory</p>
                    <h2><?php echo $total; ?></h2>
                </div>
                <div class="card">
                    <h3>Active Students</h3>
                    <p class="muted">Currently enrolled</p>
                    <h2><?php echo count($active); ?></h2>
                </div>
                <div class="card">
                    <h3>Average Attendance</h3>
                    <p class="muted">Across all students</p>
                    <h2><?php echo number_format($avgAttendance, 1); ?>%</h2>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Add live search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const searchForm = searchInput.closest('form');
            const table = document.getElementById('directoryTable');
            
            // Debounce function to prevent too many requests
            function debounce(func, wait) {
                let timeout;
                return function() {
                    const context = this, args = arguments;
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(context, args), wait);
                };
            }
            
            // Auto-submit form when search input changes (with debounce)
            searchInput.addEventListener('input', debounce(function() {
                searchForm.submit();
            }, 500));
            
            // Focus search input on page load
            searchInput.focus();
            
            // Highlight search terms in table
            if (searchInput.value) {
                const searchTerm = searchInput.value.toLowerCase();
                const rows = table.getElementsByTagName('tr');
                
                for (let i = 1; i < rows.length; i++) {
                    const row = rows[i];
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.backgroundColor = '#f8f9ff';
                    }
                }
            }
        
        // Cleanup on modal close
        document.getElementById('addStudentModal').addEventListener('hidden.bs.modal', function () {
            stopAdminCamera();
        });
    </script>
</body>
<script>
    // Add live search functionality
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const searchForm = searchInput.closest('form');
        const table = document.getElementById('directoryTable');
        
        // Debounce function to prevent too many requests
        function debounce(func, wait) {
            let timeout;
            return function() {
                const context = this, args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        }
        
        // Auto-submit form when search input changes (with debounce)
        searchInput.addEventListener('input', debounce(function() {
            searchForm.submit();
        }, 500));
        
        // Focus search input on page load
        searchInput.focus();
        
        // Highlight search terms in table
        if (searchInput.value) {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.backgroundColor = '#f8f9ff';
                }
            }
        }
    });
</script>
</html>