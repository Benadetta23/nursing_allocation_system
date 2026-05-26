<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$coordinator = new Coordinator();
$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';
$allocation_result = [];

// Get unallocated students
$all_students = $coordinator->getStudents();
$sites = $coordinator->getSites();

// Get students with active allocations
$allocated_students = [];
$allocations = $coordinator->getAllocationsWithDaysRemaining();
foreach ($allocations as $alloc) {
    $allocated_students[] = $alloc['student_id'];
}

// Get unallocated students
$unallocated_students = [];
foreach ($all_students as $student) {
    if (!in_array($student['student_id'], $allocated_students)) {
        $unallocated_students[] = $student;
    }
}

// Calculate total available capacity
$total_capacity = 0;
$total_allocated = 0;
foreach ($sites as $site) {
    $total_capacity += $site['max_students'];
}
$total_allocated = count($allocated_students);
$available_slots = $total_capacity - $total_allocated;

// Handle auto allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['auto_allocate'])) {
    $allocation_count = 0;
    $allocation_errors = [];
    $notified_students = [];
    
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $default_role = $_POST['default_role'] ?? 'General Nursing';
    $send_notifications = isset($_POST['send_notifications']) ? true : false;
    
    // Calculate site load
    $site_load = [];
    foreach ($sites as $site) {
        $site_load[$site['site_id']] = [
            'name' => $site['name'],
            'capacity' => $site['max_students'],
            'current' => 0,
            'available' => $site['max_students']
        ];
    }
    
    // Count current allocations per site
    foreach ($allocations as $alloc) {
        if (isset($site_load[$alloc['site_id']])) {
            $site_load[$alloc['site_id']]['current']++;
            $site_load[$alloc['site_id']]['available'] = $site_load[$alloc['site_id']]['capacity'] - $site_load[$alloc['site_id']]['current'];
        }
    }
    
    // Sort sites by available capacity (most available first)
    uasort($site_load, function($a, $b) {
        return $b['available'] - $a['available'];
    });
    
    $notification = new Notification($conn);
    
    foreach ($unallocated_students as $student) {
        $allocated = false;
        
        // Try to allocate to site with available capacity
        foreach ($site_load as $site_id => $site) {
            if ($site['available'] > 0) {
                $result = $coordinator->createAllocation(
                    $student['student_id'],
                    $site_id,
                    $start_date,
                    $end_date,
                    $default_role
                );
                
                if ($result) {
                    $allocation_count++;
                    $site_load[$site_id]['current']++;
                    $site_load[$site_id]['available']--;
                    $allocated = true;
                    
                    // Send notification if enabled
                    if ($send_notifications) {
                        // Get student email and name
                        $studentQuery = "SELECT email, name FROM student WHERE student_id = :student_id";
                        $studentStmt = $conn->prepare($studentQuery);
                        $studentStmt->bindParam(':student_id', $student['student_id']);
                        $studentStmt->execute();
                        $studentData = $studentStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($studentData) {
                            // Get site name
                            $siteQuery = "SELECT name FROM clinical_site WHERE site_id = :site_id";
                            $siteStmt = $conn->prepare($siteQuery);
                            $siteStmt->bindParam(':site_id', $site_id);
                            $siteStmt->execute();
                            $siteData = $siteStmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Send email and in-app notification
                            $notify_result = $notification->sendAllocationNotification(
                                $student['student_id'],
                                $studentData['email'],
                                $studentData['name'],
                                $siteData['name'],
                                $start_date,
                                $end_date,
                                $default_role,
                                'both'
                            );
                            
                            if ($notify_result['email_sent']) {
                                $notified_students[] = $studentData['name'];
                            }
                        }
                    }
                    break;
                }
            }
        }
        
        if (!$allocated) {
            $allocation_errors[] = "No available capacity for student: " . $student['name'] . " (" . $student['student_number'] . ")";
        }
    }
    
    if ($allocation_count > 0) {
        $message = "Auto-allocation completed! Successfully allocated: $allocation_count students.";
        if ($send_notifications && count($notified_students) > 0) {
            $message .= " Notifications sent to " . count($notified_students) . " students.";
        }
        if (!empty($allocation_errors)) {
            $message .= " " . count($allocation_errors) . " students could not be allocated due to capacity issues.";
        }
        // Refresh data
        header("Location: auto_allocate.php?success=" . urlencode($message));
        exit;
    } else {
        $error = "No students were allocated. Please check site capacities and available students.";
    }
}

if (isset($_GET['success'])) {
    $message = $_GET['success'];
}

// Recalculate after potential allocation
$allocations = $coordinator->getAllocationsWithDaysRemaining();
$allocated_students = [];
foreach ($allocations as $alloc) {
    $allocated_students[] = $alloc['student_id'];
}

$unallocated_students = [];
foreach ($all_students as $student) {
    if (!in_array($student['student_id'], $allocated_students)) {
        $unallocated_students[] = $student;
    }
}

$total_allocated = count($allocated_students);
$available_slots = $total_capacity - $total_allocated;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Allocation - Coordinator Dashboard</title>
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
        
        .stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-box {
            flex: 1;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-bottom: 2px solid #c3a343;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #4a2f1a;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.75rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a2f1a;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #c3a343;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .checkbox-group input {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            font-weight: 500;
            color: #4a2f1a;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #4a2f1a;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: 0.3s;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #654321;
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
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .stats { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Auto Allocation</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="coordinator_Dashboard.php?tab=sites" class="nav-tab">Clinical Sites</a>
        <a href="coordinator_Dashboard.php?tab=students" class="nav-tab">Students</a>
        <a href="coordinator_Dashboard.php?tab=allocations" class="nav-tab">Allocations</a>
        <a href="upload_students.php" class="nav-tab">Bulk Upload</a>
        <a href="auto_allocate.php" class="nav-tab active">Auto Allocate</a>
        <a href="coordinator_reports.php" class="nav-tab">Reports</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($unallocated_students); ?></div>
                <div class="stat-label">Pending Allocation</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $available_slots; ?></div>
                <div class="stat-label">Available Slots</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo count($sites); ?></div>
                <div class="stat-label">Clinical Sites</div>
            </div>
        </div>
        
        <div class="card">
            <h2>Auto Allocation Settings</h2>
            
            <?php if (count($unallocated_students) == 0): ?>
                <div class="success-msg" style="text-align: center;">
                    All students have been allocated. No pending allocations.
                </div>
            <?php elseif ($available_slots <= 0): ?>
                <div class="error-msg" style="text-align: center;">
                    No available capacity. Please add more clinical sites or increase capacity.
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Placement Start Date</label>
                        <input type="date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label>Placement End Date</label>
                        <input type="date" name="end_date" required>
                    </div>
                    <div class="form-group">
                        <label>Default Role</label>
                        <input type="text" name="default_role" value="General Nursing" required>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="send_notifications" id="send_notifications" checked>
                        <label for="send_notifications">Send notifications to students</label>
                    </div>
                    
                    <button type="submit" name="auto_allocate" class="btn-primary">Run Auto Allocation</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>