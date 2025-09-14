<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Portal - FullAttend</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../student.css?v=<?php echo time(); ?>">
    <style>
        /* Main Layout */
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8f9fa;
        }
        
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        .dashboard-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem;
            overflow-x: auto;
        }
        
        /* Cards and Components */
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-content h1 {
            margin: 0;
            color: #1f2937;
            font-size: 2rem;
            font-weight: 600;
        }
        
        .header-content p {
            margin: 0.5rem 0 0 0;
            color: #6b7280;
        }
        
        .card {
            background: white;
            border: none;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: none;
            border-bottom: 1px solid #e5e7eb;
            padding: 1.5rem;
            border-radius: 12px 12px 0 0;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        
        /* Tables */
        .table {
            margin: 0;
        }
        
        .table th {
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
        }
    </style>
</head>
<body class="lecturer-portal">
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <main class="dashboard-content">
