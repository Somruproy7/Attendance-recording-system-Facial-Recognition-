<?php
session_start();
require_once 'config/database.php';
require_once 'includes/header.php';

$db = new Database();
$conn = $db->getConnection();

// Get classes taught by this lecturer
$stmt = $conn->prepare("
    SELECT c.id, c.class_code, c.class_name 
    FROM classes c
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE cl.lecturer_id = :lecturer_id
    AND c.status = 'active'
    ORDER BY c.class_code
");

$stmt->execute(['lecturer_id' => $_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default to first class if available
$selectedClass = $_GET['class_id'] ?? ($classes[0]['id'] ?? null);
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Get class statistics
$classStats = [];
$attendanceTrends = [];
$recentSessions = [];
$topStudents = [];

if ($selectedClass) {
    // Overall class statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT si.id) as total_sessions,
            COUNT(DISTINCT se.student_id) as total_students,
            AVG(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) * 100 as avg_attendance_rate,
            COUNT(DISTINCT CASE WHEN ar.status = 'present' THEN ar.student_id END) as avg_students_per_session
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        LEFT JOIN student_enrollments se ON c.id = se.class_id
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id AND se.student_id = ar.student_id
        WHERE c.id = :class_id
        AND si.session_date BETWEEN :start_date AND :end_date
        AND si.status = 'completed'
    ");
    
    $stmt->execute([
        'class_id' => $selectedClass,
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);
    
    $classStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Attendance trends over time
    $stmt = $conn->prepare("
        SELECT 
            DATE(si.session_date) as session_date,
            COUNT(DISTINCT ar.student_id) as present_count,
            COUNT(DISTINCT se.student_id) as total_students,
            (COUNT(DISTINCT ar.student_id) * 100.0) / NULLIF(COUNT(DISTINCT se.student_id), 0) as attendance_rate
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN student_enrollments se ON c.id = se.class_id
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id 
            AND se.student_id = ar.student_id 
            AND ar.status = 'present'
        WHERE c.id = :class_id
        AND si.session_date BETWEEN :start_date AND :end_date
        AND si.status = 'completed'
        GROUP BY si.session_date
        ORDER BY si.session_date
    ");
    
    $stmt->execute([
        'class_id' => $selectedClass,
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);
    
    $attendanceTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent sessions
    $stmt = $conn->prepare("
        SELECT 
            si.id,
            si.session_date,
            ts.session_title,
            COUNT(DISTINCT ar.student_id) as present_count,
            COUNT(DISTINCT se.student_id) as total_students,
            (COUNT(DISTINCT ar.student_id) * 100.0) / NULLIF(COUNT(DISTINCT se.student_id), 0) as attendance_rate
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN student_enrollments se ON c.id = se.class_id
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id 
            AND se.student_id = ar.student_id 
            AND ar.status = 'present'
        WHERE c.id = :class_id
        AND si.session_date <= :end_date
        AND si.status = 'completed'
        GROUP BY si.id, si.session_date, ts.session_title
        ORDER BY si.session_date DESC
        LIMIT 5
    ");
    
    $stmt->execute([
        'class_id' => $selectedClass,
        'end_date' => $endDate
    ]);
    
    $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top students by attendance
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.first_name,
            u.last_name,
            u.user_id as student_id,
            COUNT(DISTINCT si.id) as total_sessions,
            COUNT(DISTINCT ar.session_instance_id) as attended_sessions,
            (COUNT(DISTINCT ar.session_instance_id) * 100.0) / NULLIF(COUNT(DISTINCT si.id), 0) as attendance_rate
        FROM users u
        JOIN student_enrollments se ON u.id = se.student_id
        JOIN classes c ON se.class_id = c.id
        JOIN session_instances si ON c.id = (SELECT class_id FROM timetable_sessions WHERE id = si.timetable_session_id)
        LEFT JOIN attendance_records ar ON si.id = ar.session_instance_id 
            AND ar.student_id = u.id 
            AND ar.status = 'present'
        WHERE c.id = :class_id
        AND si.session_date BETWEEN :start_date AND :end_date
        AND si.status = 'completed'
        GROUP BY u.id, u.first_name, u.last_name, u.user_id
        HAVING COUNT(DISTINCT si.id) > 0
        ORDER BY attendance_rate DESC, u.last_name, u.first_name
        LIMIT 5
    ");
    
    $stmt->execute([
        'class_id' => $selectedClass,
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);
    
    $topStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Attendance Statistics</h1>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <label for="class_id" class="form-label">Class</label>
                    <select class="form-select" id="class_id" name="class_id" onchange="this.form.submit()">
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['class_code'] . ' - ' . $class['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" 
                           value="<?= htmlspecialchars($startDate) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($endDate) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($selectedClass && $classStats): ?>
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Average Attendance</h5>
                        <h2 class="mb-0"><?= number_format($classStats['avg_attendance_rate'] ?? 0, 1) ?>%</h2>
                        <div class="progress mt-3" style="height: 10px;">
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: <?= $classStats['avg_attendance_rate'] ?? 0 ?>%" 
                                 aria-valuenow="<?= $classStats['avg_attendance_rate'] ?? 0 ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Total Sessions</h5>
                        <h2 class="mb-0"><?= $classStats['total_sessions'] ?? 0 ?></h2>
                        <p class="text-muted mb-0">
                            <?= date('M j', strtotime($startDate)) ?> - <?= date('M j, Y', strtotime($endDate)) ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Total Students</h5>
                        <h2 class="mb-0"><?= $classStats['total_students'] ?? 0 ?></h2>
                        <p class="text-muted mb-0">Enrolled in class</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Avg. Students/Session</h5>
                        <h2 class="mb-0"><?= number_format($classStats['avg_students_per_session'] ?? 0, 1) ?></h2>
                        <p class="text-muted mb-0">Per session average</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts Row -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Attendance Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="attendanceTrendChart" height="300"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Top Students</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($topStudents)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($topStudents as $student): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h6>
                                            <small class="text-muted"><?= htmlspecialchars($student['student_id']) ?></small>
                                        </div>
                                        <span class="badge bg-success rounded-pill">
                                            <?= number_format($student['attendance_rate'], 0) ?>%
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-user-graduate fa-3x mb-2"></i>
                                <p class="mb-0">No attendance data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Sessions -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Recent Sessions</h5>
                <a href="sessions.php?class_id=<?= $selectedClass ?>" class="btn btn-sm btn-outline-primary">
                    View All <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($recentSessions)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Session</th>
                                    <th class="text-center">Attendance</th>
                                    <th class="text-end">Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSessions as $session): ?>
                                    <tr style="cursor: pointer;" 
                                        onclick="window.location.href='sessions/view.php?id=<?= $session['id'] ?>'"
                                        class="clickable-row">
                                        <td><?= date('M j, Y', strtotime($session['session_date'])) ?></td>
                                        <td><?= htmlspecialchars($session['session_title']) ?></td>
                                        <td class="text-center">
                                            <?= $session['present_count'] ?> / <?= $session['total_students'] ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex align-items-center justify-content-end">
                                                <span class="me-2"><?= number_format($session['attendance_rate'], 0) ?>%</span>
                                                <div class="progress flex-grow-1" style="height: 6px; max-width: 100px;">
                                                    <div class="progress-bar bg-success" 
                                                         role="progressbar" 
                                                         style="width: <?= $session['attendance_rate'] ?>%"
                                                         aria-valuenow="<?= $session['attendance_rate'] ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-calendar-alt fa-3x mb-2"></i>
                        <p class="mb-0">No recent sessions found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    <?php else: ?>
        <div class="text-center py-5">
            <div class="mb-3">
                <i class="fas fa-chart-pie fa-4x text-muted"></i>
            </div>
            <h4>No data available</h4>
            <p class="text-muted">Select a class to view attendance statistics</p>
        </div>
    <?php endif; ?>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize attendance trend chart if data exists
    <?php if (!empty($attendanceTrends)): ?>
    const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
    const attendanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [
                <?php 
                foreach ($attendanceTrends as $trend) {
                    echo '"' . date('M j', strtotime($trend['session_date'])) . '", ';
                }
                ?>
            ],
            datasets: [{
                label: 'Attendance Rate (%)',
                data: [
                    <?php 
                    foreach ($attendanceTrends as $trend) {
                        echo number_format($trend['attendance_rate'], 1) . ', ';
                    }
                    ?>
                ],
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 2,
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#fff',
                pointBorderColor: 'rgba(40, 167, 69, 1)',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 14 },
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y.toFixed(1) + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            elements: {
                line: {
                    borderJoinStyle: 'round'
                }
            }
        }
    });
    <?php endif; ?>
    
    // Make table rows clickable
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', function() {
            window.location = this.dataset.href;
        });
    });
});
</script>

<style>
.clickable-row {
    transition: background-color 0.2s;
}
.clickable-row:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}
.progress {
    border-radius: 10px;
    overflow: hidden;
}
.progress-bar {
    transition: width 0.6s ease;
}
</style>

<?php require_once 'includes/footer.php'; ?>
