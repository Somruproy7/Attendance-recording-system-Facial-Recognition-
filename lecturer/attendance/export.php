<?php
session_start();
require_once '../../config/database.php';

if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
    $_SESSION['error_message'] = 'Invalid session ID';
    header('Location: ../sessions/');
    exit();
}

$sessionId = $_GET['session_id'];
$format = strtolower($_GET['format'] ?? 'excel');

$db = new Database();
$conn = $db->getConnection();

// Verify the session exists and belongs to the lecturer
$stmt = $conn->prepare("
    SELECT 
        si.id, 
        si.session_date,
        ts.session_title,
        ts.start_time,
        ts.end_time,
        c.class_code,
        c.class_name,
        ts.room_location
    FROM session_instances si
    JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
    JOIN classes c ON ts.class_id = c.id
    JOIN class_lecturers cl ON c.id = cl.class_id
    WHERE si.id = :id
    AND cl.lecturer_id = :lecturer_id
");

$stmt->execute([
    'id' => $sessionId,
    'lecturer_id' => $_SESSION['user_id']
]);

$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    $_SESSION['error_message'] = 'Session not found or access denied';
    header('Location: ../sessions/');
    exit();
}

// Get attendance records
$stmt = $conn->prepare("
    SELECT 
        u.user_id as student_id,
        u.first_name,
        u.last_name,
        u.email,
        ar.status,
        ar.check_in_time,
        ar.notes
    FROM attendance_records ar
    JOIN users u ON ar.student_id = u.id
    WHERE ar.session_instance_id = :session_id
    ORDER BY u.last_name, u.first_name
");

$stmt->execute(['session_id' => $sessionId]);
$attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set up export based on format
if ($format === 'pdf') {
    require_once '../../vendor/autoload.php';
    
    $mpdf = new \Mpdf\Mpdf([
        'tempDir' => sys_get_temp_dir() . '/mpdf',
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'helvetica'
    ]);
    
    $html = "
    <html>
    <head>
        <title>Attendance Report - {$session['class_code']} - {$session['session_date']}</title>
        <style>
            body { font-family: Arial, sans-serif; }
            h1 { color: #2c3e50; }
            .header { margin-bottom: 20px; }
            .class-info { margin-bottom: 15px; }
            .session-info { margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background-color: #f8f9fa; text-align: left; padding: 8px; border: 1px solid #dee2e6; }
            td { padding: 8px; border: 1px solid #dee2e6; }
            .present { color: #28a745; }
            .absent { color: #dc3545; }
            .late { color: #ffc107; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>Attendance Report</h1>
            <div class='class-info'>
                <strong>Class:</strong> {$session['class_code']} - {$session['class_name']}<br>
                <strong>Session:</strong> {$session['session_title']}<br>
                <strong>Date:</strong> " . date('F j, Y', strtotime($session['session_date'])) . "<br>
                <strong>Time:</strong> " . date('g:i A', strtotime($session['start_time'])) . " - " . date('g:i A', strtotime($session['end_time'])) . "<br>
                <strong>Location:</strong> {$session['room_location']}
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Check-in Time</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>";
    
    foreach ($attendance as $record) {
        $statusClass = strtolower($record['status']);
        $checkInTime = $record['check_in_time'] ? date('M j, g:i A', strtotime($record['check_in_time'])) : '-';
        
        $html .= "
                <tr>
                    <td>{$record['student_id']}</td>
                    <td>{$record['last_name']}, {$record['first_name']}</td>
                    <td>{$record['email']}</td>
                    <td class='{$statusClass}'>" . ucfirst($record['status']) . "</td>
                    <td>{$checkInTime}</td>
                    <td>" . htmlspecialchars($record['notes'] ?? '') . "</td>
                </tr>";
    }
    
    $html .= "
            </tbody>
        </table>
        <div style='margin-top: 20px; font-size: 12px; color: #6c757d;'>
            Generated on " . date('F j, Y \a\t g:i A') . "
        </div>
    </body>
    </html>";
    
    $filename = "attendance_{$session['class_code']}_" . date('Y-m-d', strtotime($session['session_date'])) . ".pdf";
    
    $mpdf->WriteHTML($html);
    $mpdf->Output($filename, 'D'); // D for download
    exit();
    
} else {
    // Default to Excel format
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="attendance_' . $session['class_code'] . '_' . date('Y-m-d', strtotime($session['session_date'])) . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    require_once '../../vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('FullAttend')
        ->setTitle('Attendance Report')
        ->setSubject('Class Attendance')
        ->setDescription('Attendance report generated by FullAttend');
    
    // Add headers
    $sheet->setCellValue('A1', 'Class:');
    $sheet->setCellValue('B1', $session['class_code'] . ' - ' . $session['class_name']);
    
    $sheet->setCellValue('A2', 'Session:');
    $sheet->setCellValue('B2', $session['session_title']);
    
    $sheet->setCellValue('A3', 'Date:');
    $sheet->setCellValue('B3', date('F j, Y', strtotime($session['session_date'])));
    
    $sheet->setCellValue('A4', 'Time:');
    $sheet->setCellValue('B4', date('g:i A', strtotime($session['start_time'])) . ' - ' . date('g:i A', strtotime($session['end_time'])));
    
    $sheet->setCellValue('A5', 'Location:');
    $sheet->setCellValue('B5', $session['room_location']);
    
    // Add column headers
    $sheet->setCellValue('A7', 'Student ID');
    $sheet->setCellValue('B7', 'Last Name');
    $sheet->setCellValue('C7', 'First Name');
    $sheet->setCellValue('D7', 'Email');
    $sheet->setCellValue('E7', 'Status');
    $sheet->setCellValue('F7', 'Check-in Time');
    $sheet->setCellValue('G7', 'Notes');
    
    // Style headers
    $headerStyle = [
        'font' => ['bold' => true],
        'borders' => ['bottom' => ['borderStyle' => 'thin']]
    ];
    
    $sheet->getStyle('A7:G7')->applyFromArray($headerStyle);
    
    // Add data rows
    $row = 8;
    foreach ($attendance as $record) {
        $sheet->setCellValue('A' . $row, $record['student_id']);
        $sheet->setCellValue('B' . $row, $record['last_name']);
        $sheet->setCellValue('C' . $row, $record['first_name']);
        $sheet->setCellValue('D' . $row, $record['email']);
        $sheet->setCellValue('E' . $row, ucfirst($record['status']));
        $sheet->setCellValue('F' . $row, $record['check_in_time'] ? date('M j, g:i A', strtotime($record['check_in_time'])) : '-');
        $sheet->setCellValue('G' . $row, $record['notes'] ?? '');
        $row++;
    }
    
    // Auto-size columns
    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}
