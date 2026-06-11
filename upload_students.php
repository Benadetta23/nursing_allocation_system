<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
$coordinator = new Coordinator();

$message = '';
$error = '';
$upload_success = 0;
$upload_failed = 0;
$upload_errors = [];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['student_file'])) {
    $file = $_FILES['student_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Check file type (only CSV)
    if ($file_ext != 'csv') {
        $error = "Please upload CSV file only.";
    } else {
        // Move uploaded file
        $upload_dir = 'uploads/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_path = $upload_dir . time() . '_' . basename($file['name']);
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // Process CSV file
            if (($handle = fopen($file_path, 'r')) !== false) {
                $row_number = 0;
                while (($data = fgetcsv($handle)) !== false) {
                    $row_number++;
                    
                    // Skip header row
                    if ($row_number == 1) {
                        continue;
                    }
                    
                    // Skip empty rows
                    if (empty(array_filter($data))) {
                        continue;
                    }
                    
                    // Columns: Student Number, Name, Email, Cohort, Year of Study, Mode of Entry
                    $student_number = trim($data[0] ?? '');
                    $name = trim($data[1] ?? '');
                    $email = trim($data[2] ?? '');
                    $cohort = trim($data[3] ?? date('Y'));
                    $year_of_study = trim($data[4] ?? '1');
                    $mode_of_entry = trim($data[5] ?? 'Generic');
                    
                    if (empty($student_number) || empty($name) || empty($email)) {
                        $upload_errors[] = "Row $row_number: Missing required fields (Student Number, Name, Email)";
                        $upload_failed++;
                        continue;
                    }
                    
                    $result = $coordinator->addStudentWithDefaultAllocation($student_number, $name, $email, $cohort, $mode_of_entry, $_SESSION['user_id'], $year_of_study);
                    
                    if ($result) {
                        $upload_success++;
                    } else {
                        $upload_errors[] = "Row $row_number: Student number $student_number already exists";
                        $upload_failed++;
                    }
                }
                fclose($handle);
                
                // Determine message type based on results
                if ($upload_success > 0 && $upload_failed == 0) {
                    $message = "Upload completed! Successfully added: $upload_success students.";
                } elseif ($upload_success > 0 && $upload_failed > 0) {
                    $message = "Upload completed with warnings. Added: $upload_success students. Failed: $upload_failed students.";
                } elseif ($upload_success == 0 && $upload_failed > 0) {
                    $error = "Upload failed. No students were added. Failed: $upload_failed students.";
                } else {
                    $error = "No data found in the CSV file.";
                }
                
                // Delete file after processing
                unlink($file_path);
            } else {
                $error = "Failed to read CSV file.";
            }
        } else {
            $error = "Failed to upload file.";
        }
    }
}

// Download template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_upload_template.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Number', 'Name', 'Email', 'Cohort', 'Year of Study', 'Mode of Entry']);
    fputcsv($output, ['BScNM/2024/001', 'John Doe', 'john@example.com', '2024', '1', 'Generic']);
    fputcsv($output, ['BScNM/2024/002', 'Jane Smith', 'jane@example.com', '2024', '2', 'Upgrading']);
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Upload Students - Coordinator Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #654321;
            min-height: 100vh;
        }
        
        .header {
            background: #4a2f1a;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border-bottom: 1px solid #c3a343;
        }
        
        .header h1 {
            color: #c3a343;
            font-size: 1.3rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info span {
            color: white;
        }
        
        .role-badge {
            background: #c3a343;
            color: #4a2f1a;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 6px 16px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 0.8rem;
            transition: 0.3s;
        }
        
        .btn-logout:hover {
            background: #dc3545;
        }
        
        .nav-tabs {
            background: #5a3a2a;
            display: flex;
            gap: 5px;
            padding: 0 20px;
            flex-wrap: wrap;
        }
        
        .nav-tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            color: #ddd;
            transition: 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .nav-tab:hover {
            color: #c3a343;
        }
        
        .nav-tab.active {
            color: #c3a343;
            border-bottom: 3px solid #c3a343;
            background: #4a2f1a;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            color: #4a2f1a;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
            padding-left: 15px;
        }
        
        .upload-area {
            border: 2px dashed #c3a343;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            margin-bottom: 20px;
        }
        
        .upload-area h3 {
            color: #4a2f1a;
            margin-bottom: 10px;
        }
        
        .upload-area p {
            color: #666;
            margin-bottom: 20px;
        }
        
        .btn-upload {
            background: #4a2f1a;
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.3s;
        }
        
        .btn-upload:hover {
            background: #654321;
        }
        
        .btn-template {
            background: #c3a343;
            color: #4a2f1a;
            padding: 8px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .btn-template:hover {
            background: #d4b353;
        }
        
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .error-msg {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }
        
        .warning-msg {
            background: #fff3cd;
            color: #856404;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
        }
        
        .error-list {
            margin-top: 10px;
            padding-left: 20px;
            font-size: 0.85rem;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .upload-area { padding: 20px; }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Bulk Student Upload</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="coordinator_Dashboard.php?tab=sites" class="nav-tab">Clinical Sites</a>
        <a href="coordinator_Dashboard.php?tab=students" class="nav-tab">Students</a>
        <a href="upload_students.php" class="nav-tab active">Bulk Upload</a>
        <a href="auto_allocate.php" class="nav-tab">Auto Allocate</a>
        <a href="coordinator_Dashboard.php?tab=assign" class="nav-tab">Assign Staff</a>
        <a href="coordinator_reports.php" class="nav-tab">Reports</a>
    </div>
    
    <div class="container">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <?php if ($message): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: '<?php echo addslashes($message); ?>',
                    confirmButtonColor: '#4a2f1a',
                    timer: 3000,
                    timerProgressBar: true
                });
            </script>
        <?php endif; ?>
        <?php if ($error || !empty($upload_errors)): ?>
            <script>
                <?php 
                $htmlError = "";
                if ($error) {
                    $htmlError .= "<p>" . addslashes(htmlspecialchars($error)) . "</p>";
                }
                if (!empty($upload_errors)) {
                    $htmlError .= "<div style=\"text-align: left; margin-top: 10px;\"><strong>Errors encountered:</strong><ul style=\"margin-left: 20px; font-size: 14px; margin-top: 5px;\">";
                    foreach ($upload_errors as $err) {
                        $htmlError .= "<li>" . addslashes(htmlspecialchars($err)) . "</li>";
                    }
                    $htmlError .= "</ul></div>";
                }
                ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Issue',
                    html: '<?php echo $htmlError; ?>',
                    confirmButtonColor: '#dc3545'
                });
            </script>
        <?php endif; ?>
        
        <div class="card">
            <h2>Bulk Upload Students</h2>
            
            <div class="upload-area">
                <h3>Upload CSV File</h3>
                <p>Upload a CSV file with student details. The system will add all students automatically.</p>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="student_file" accept=".csv" required style="margin-bottom: 15px;">
                    <br>
                    <button type="submit" class="btn-upload">Upload and Add Students</button>
                </form>
            </div>
            
            <div style="text-align: center;">
                <a href="?download_template=1" class="btn-template">Download CSV Template</a>
            </div>
        </div>
    </div>
    </body>
</html>
