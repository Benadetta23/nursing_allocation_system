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

// Fix: Use the correct connection method from your Database class
if (method_exists($database, 'getConnection')) {
    $conn = $database->getConnection();
} elseif (method_exists($database, 'connect')) {
    $conn = $database->connect();
} else {
    // Fallback direct connection
    $host = 'localhost';
    $dbname = 'nursing_allocation';
    $username = 'root';
    $password = '';
    
    try {
        $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

$message = '';
$error = '';
$allocation_result = [];

// Helper function to clean display
function cleanDisplay($text) {
    if ($text === null) return '';
    $text = str_replace(['(', ')', '\\'], '', $text);
    return trim($text);
}

// Get unallocated students
$all_students = $coordinator->getStudents();
$sites = $coordinator->getSites();

// Get sites with their offered roles
$site_roles = [];
foreach ($sites as $site) {
    $roles_offered = $site['roles_offered'] ?? '';
    $site_roles[$site['site_id']] = array_map('trim', explode(',', $roles_offered));
}

// Get students with active allocations
$allocated_students = [];
$allocations = $coordinator->getAllocationsWithDaysRemaining();
foreach ($allocations as $alloc) {
    $allocated_students[] = $alloc['student_id'];
}

// Get unallocated students with year info
$unallocated_students = [];
foreach ($all_students as $student) {
    if (!in_array($student['student_id'], $allocated_students)) {
        $unallocated_students[] = $student;
    }
}

// Calculate total available capacity per site
$site_capacity = [];
$total_available = 0;
foreach ($sites as $site) {
    $site_capacity[$site['site_id']] = [
        'name' => $site['name'],
        'capacity' => $site['max_students'],
        'current' => 0,
        'available' => $site['max_students'],
        'roles_offered' => $site_roles[$site['site_id']] ?? ['General Nursing']
    ];
    $total_available += $site['max_students'];
}

// Count current allocations per site
foreach ($allocations as $alloc) {
    if (isset($site_capacity[$alloc['site_id']])) {
        $site_capacity[$alloc['site_id']]['current']++;
        $site_capacity[$alloc['site_id']]['available'] = $site_capacity[$alloc['site_id']]['capacity'] - $site_capacity[$alloc['site_id']]['current'];
    }
}

// Calculate total available slots
$available_slots = array_sum(array_column($site_capacity, 'available'));

// Role mapping based on year of study (FULL Year 1-4)
$role_by_year = [
    1 => 'General Nursing',
    2 => 'Midwifery',
    3 => 'Critical Care',
    4 => 'Preceptorship'
];

// Function to get role for student based on year
function getRoleForStudent($student_year, $role_mode, $default_role) {
    global $role_by_year;
    if ($role_mode == 'auto') {
        return $role_by_year[$student_year] ?? 'General Nursing';
    }
    return $default_role;
}

// Function to check if a site offers a specific role
function siteOffersRole($site, $role) {
    $roles_offered = $site['roles_offered'] ?? [];
    return in_array($role, $roles_offered);
}

// Function to log notification status
function logNotification($conn, $student_id, $allocation_id, $recipient_email, $recipient_name, $status, $error_message = null) {
    $stmt = $conn->prepare("
        INSERT INTO notification_log (student_id, allocation_id, recipient_email, recipient_name, notification_type, status, error_message, sent_at) 
        VALUES (?, ?, ?, ?, 'email', ?, ?, NOW())
    ");
    $stmt->execute([$student_id, $allocation_id, $recipient_email, $recipient_name, $status, $error_message]);
}

// Function to log allocation debug info
function logAllocationDebug($conn, $run_id, $student_id, $site_id, $role, $status, $reason = null) {
    $stmt = $conn->prepare("
        INSERT INTO allocation_debug_log (run_id, student_id, site_id, role_assigned, status, reason) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$run_id, $student_id, $site_id, $role, $status, $reason]);
}

// Handle auto allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['auto_allocate'])) {
    $allocation_count = 0;
    $allocation_errors = [];
    $notified_students = [];
    $notification_failures = [];
    
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $role_mode = $_POST['role_mode'] ?? 'auto';
    $default_role = $_POST['default_role'] ?? 'General Nursing';
    $send_notifications = isset($_POST['send_notifications']) ? true : false;
    
    // Generate unique run ID for debugging
    $run_id = uniqid('alloc_');
    
    // Reset site load for this allocation run
    $current_site_load = $site_capacity;
    
    // Sort sites by available capacity (most available first)
    $sorted_sites = $current_site_load;
    uasort($sorted_sites, function($a, $b) {
        return $b['available'] - $a['available'];
    });
    
    $notification = new Notification($conn);
    
    // First, count how many students we need to allocate
    $students_to_allocate = count($unallocated_students);
    $total_available_capacity = array_sum(array_column($current_site_load, 'available'));
    
    if ($students_to_allocate > $total_available_capacity) {
        $allocation_errors[] = "Warning: Not enough capacity! Need $students_to_allocate slots, only $total_available_capacity available.";
    }
    
    // Get list of sites that have available capacity
    $available_site_ids = array_keys(array_filter($sorted_sites, function($site) {
        return $site['available'] > 0;
    }));
    
    // Track year distribution for reporting (FIXED: removed the syntax error)
    $year_allocation_count = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    
    // Smart allocation with role-site matching
    $failed_students = [];
    
    foreach ($unallocated_students as $student) {
        $allocated = false;
        $student_year = $student['year_of_study'] ?? 1;
        $required_role = getRoleForStudent($student_year, $role_mode, $default_role);
        
        // Track year distribution
        if (isset($year_allocation_count[$student_year])) {
            $year_allocation_count[$student_year]++;
        }
        
        // First, try to find sites that offer this specific role AND have capacity
        $eligible_sites = [];
        foreach ($available_site_ids as $site_id) {
            if ($current_site_load[$site_id]['available'] > 0 && siteOffersRole($current_site_load[$site_id], $required_role)) {
                $eligible_sites[] = $site_id;
            }
        }
        
        // If no site offers this role, try to find ANY site with capacity (fallback)
        if (empty($eligible_sites)) {
            foreach ($available_site_ids as $site_id) {
                if ($current_site_load[$site_id]['available'] > 0) {
                    $eligible_sites[] = $site_id;
                }
            }
            if (!empty($eligible_sites)) {
                $allocation_errors[] = "Note: Student {$student['name']} (Year $student_year) requires '$required_role' but no site offers it. Allocated to available site with default role.";
                $required_role = $default_role;
            }
        }
        
        // Try to allocate to eligible sites
        foreach ($eligible_sites as $site_id) {
            if ($allocated) break;
            
            if ($current_site_load[$site_id]['available'] > 0) {
                $result = $coordinator->createAllocation(
                    $student['student_id'],
                    $site_id,
                    $start_date,
                    $end_date,
                    $required_role
                );
                
                if ($result) {
                    $allocation_count++;
                    $current_site_load[$site_id]['current']++;
                    $current_site_load[$site_id]['available']--;
                    $available_site_ids = array_keys(array_filter($current_site_load, function($site) {
                        return $site['available'] > 0;
                    }));
                    $allocated = true;
                    
                    // Log success
                    logAllocationDebug($conn, $run_id, $student['student_id'], $site_id, $required_role, 'success', null);
                    
                    // Send notification if enabled
                    if ($send_notifications) {
                        try {
                            $studentQuery = "SELECT email, name FROM student WHERE student_id = :student_id";
                            $studentStmt = $conn->prepare($studentQuery);
                            $studentStmt->bindParam(':student_id', $student['student_id']);
                            $studentStmt->execute();
                            $studentData = $studentStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($studentData) {
                                $siteQuery = "SELECT name FROM clinical_site WHERE site_id = :site_id";
                                $siteStmt = $conn->prepare($siteQuery);
                                $siteStmt->bindParam(':site_id', $site_id);
                                $siteStmt->execute();
                                $siteData = $siteStmt->fetch(PDO::FETCH_ASSOC);
                                
                                $notify_result = $notification->sendAllocationNotification(
                                    $student['student_id'],
                                    $studentData['email'],
                                    $studentData['name'],
                                    $siteData['name'],
                                    $start_date,
                                    $end_date,
                                    $required_role,
                                    'both'
                                );
                                
                                // Log notification result
                                if ($notify_result['email_sent']) {
                                    $notified_students[] = $studentData['name'];
                                    logNotification($conn, $student['student_id'], $result, $studentData['email'], $studentData['name'], 'sent', null);
                                } else {
                                    $notification_failures[] = $studentData['name'];
                                    $error_msg = $notify_result['email_error'] ?? 'Unknown error';
                                    logNotification($conn, $student['student_id'], $result, $studentData['email'], $studentData['name'], 'failed', $error_msg);
                                }
                            }
                        } catch (Exception $e) {
                            $notification_failures[] = $student['name'];
                            logNotification($conn, $student['student_id'], $result, null, $student['name'], 'failed', $e->getMessage());
                        }
                    }
                    break;
                }
            }
        }
        
        if (!$allocated) {
            $failed_students[] = $student;
            $error_reason = "No available site with capacity offering role: $required_role (Year: $student_year)";
            $allocation_errors[] = "Failed to allocate: {$student['name']} ({$student['student_number']}) - Year $student_year needs '$required_role' - $error_reason";
            logAllocationDebug($conn, $run_id, $student['student_id'], null, $required_role, 'failed', $error_reason);
        }
    }
    
    // Build result message
    if ($allocation_count > 0) {
        $message = "Auto-allocation completed! Successfully allocated: $allocation_count students.";
        
        // Add year breakdown
        $message .= " (Year 1: {$year_allocation_count[1]}, Year 2: {$year_allocation_count[2]}, Year 3: {$year_allocation_count[3]}, Year 4: {$year_allocation_count[4]})";
        
        if ($send_notifications) {
            if (count($notified_students) > 0) {
                $message .= " Notifications sent to " . count($notified_students) . " students.";
            }
            if (count($notification_failures) > 0) {
                $message .= " Failed to send notifications to " . count($notification_failures) . " students. Check notification_log table.";
            }
        }
        if (count($failed_students) > 0) {
            $message .= " " . count($failed_students) . " students could not be allocated due to capacity or role mismatch.";
        }
        
        // Refresh data
        header("Location: auto_allocate.php?success=" . urlencode($message));
        exit;
    } else {
        $error = "No students were allocated. Please check site capacities, role offerings (especially for Year 4/Preceptorship), and available students.";
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

// Count unallocated students by year for better reporting
$unallocated_by_year = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($unallocated_students as $student) {
    $year = $student['year_of_study'] ?? 1;
    if (isset($unallocated_by_year[$year])) {
        $unallocated_by_year[$year]++;
    }
}

$total_allocated = count($allocated_students);
$total_capacity = array_sum(array_column($sites, 'max_students'));
$available_slots = $total_capacity - $total_allocated;

// Calculate per-site stats for display
$site_stats = [];
foreach ($sites as $site) {
    $current = 0;
    foreach ($allocations as $alloc) {
        if ($alloc['site_id'] == $site['site_id']) {
            $current++;
        }
    }
    $roles_display = isset($site_roles[$site['site_id']]) ? implode(', ', $site_roles[$site['site_id']]) : 'All roles';
    
    // Check if site offers Preceptorship (Year 4)
    $has_preceptorship = in_array('Preceptorship', $site_roles[$site['site_id']] ?? []);
    $preceptorship_badge = $has_preceptorship ? ' ✅ Year 4 Ready' : '';
    
    $site_stats[$site['site_id']] = [
        'name' => $site['name'],
        'capacity' => $site['max_students'],
        'current' => $current,
        'available' => $site['max_students'] - $current,
        'roles_offered' => $roles_display . $preceptorship_badge
    ];
}
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
            max-width: 1000px;
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
        
        .site-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .site-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 3px solid #c3a343;
        }
        
        .site-name {
            font-weight: 600;
            color: #4a2f1a;
            margin-bottom: 10px;
        }
        
        .site-capacity {
            font-size: 0.8rem;
            color: #666;
        }
        
        .site-roles {
            font-size: 0.7rem;
            color: #c3a343;
            margin-top: 8px;
            font-style: italic;
        }
        
        .capacity-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            margin: 10px 0;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            background: #28a745;
            border-radius: 4px;
            transition: width 0.3s;
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
        
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
            cursor: pointer;
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
        
        .info-box {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border-left: 4px solid #c3a343;
        }
        
        .info-box ul {
            margin-left: 20px;
            margin-top: 5px;
        }
        
        .info-box li {
            font-size: 0.8rem;
            margin: 3px 0;
        }
        
        .manual-role-div {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #c3a343;
        }
        
        .warning-box {
            background: #fff3cd;
            color: #856404;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            font-size: 0.85rem;
        }
        
        .year-stats {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .year-badge {
            background: #e9ecef;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .year-badge.year4 {
            background: #c3a343;
            color: #4a2f1a;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .stats { flex-direction: column; }
            .site-stats { grid-template-columns: 1fr; }
            .radio-group { flex-direction: column; gap: 10px; }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Auto Allocation</h1>
        <div class="user-info">
            <span>Welcome, <?php echo cleanDisplay(htmlspecialchars($_SESSION['name'])); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="coordinator_Dashboard.php?tab=sites" class="nav-tab">Clinical Sites</a>
        <a href="coordinator_Dashboard.php?tab=students" class="nav-tab">Manage Students</a>
        <a href="upload_students.php" class="nav-tab">Bulk Upload</a>
        <a href="auto_allocate.php" class="nav-tab active">Auto Allocate</a>
        <a href="coordinator_Dashboard.php?tab=assign" class="nav-tab">Assign Staff</a>
        <a href="coordinator_reports.php" class="nav-tab">Reports</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo cleanDisplay(htmlspecialchars($message)); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo cleanDisplay(htmlspecialchars($error)); ?></div>
        <?php endif; ?>
        
        <!-- Role-Site Matching Info -->
        <div class="warning-box">
            <strong>🔧 Role-Site Matching Active:</strong> Students will only be allocated to sites that offer their required role.
            <br><strong>Year 4 (Preceptorship):</strong> Sites marked with "✅ Year 4 Ready" can accept final year students.
        </div>
        
        <!-- Site Capacity Overview -->
        <div class="card">
            <h2>Clinical Sites Capacity & Offered Roles</h2>
            <div class="site-stats">
                <?php foreach ($site_stats as $site): ?>
                <div class="site-card">
                    <div class="site-name"><?php echo cleanDisplay(htmlspecialchars($site['name'])); ?></div>
                    <div class="site-capacity">
                        <?php echo $site['current']; ?> / <?php echo $site['capacity']; ?> students
                    </div>
                    <div class="capacity-bar">
                        <div class="capacity-fill" style="width: <?php echo ($site['capacity'] > 0) ? ($site['current'] / $site['capacity']) * 100 : 0; ?>%"></div>
                    </div>
                    <div class="site-capacity">
                        Available: <?php echo $site['available']; ?> slots
                    </div>
                    <div class="site-roles">
                        🏥 Offers: <?php echo cleanDisplay(htmlspecialchars($site['roles_offered'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($unallocated_students); ?></div>
                <div class="stat-label">Pending Allocation</div>
                <?php if (count($unallocated_students) > 0): ?>
                <div class="year-stats">
                    <?php foreach ($unallocated_by_year as $year => $count): ?>
                        <?php if ($count > 0): ?>
                        <span class="year-badge <?php echo $year == 4 ? 'year4' : ''; ?>">Year <?php echo $year; ?>: <?php echo $count; ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
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
                        <label>Role Assignment Mode</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="role_mode" value="auto" checked> Auto (Based on Year of Study)
                            </label>
                            <label>
                                <input type="radio" name="role_mode" value="manual"> Manual (Same Role for All)
                            </label>
                        </div>
                    </div>
                    
                    <div id="manualRoleDiv" class="manual-role-div" style="display: none;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Default Role (for all students)</label>
                            <input type="text" name="default_role" value="General Nursing">
                        </div>
                    </div>
                    
                    <div id="autoRoleInfo" class="info-box">
                        <p><strong>Auto Role Mapping (Based on Year of Study):</strong></p>
                        <ul>
                            <li>Year 1 → <strong>General Nursing</strong> (Basic Care)</li>
                            <li>Year 2 → <strong>Midwifery</strong> (Specialized Care)</li>
                            <li>Year 3 → <strong>Critical Care</strong> (Complex Care)</li>
                            <li>Year 4 → <strong>Preceptorship</strong> (Leadership & Teaching)</li>
                        </ul>
                        <p style="margin-top: 10px; font-size: 0.8rem; color: #c3a343;">
                            <strong>Note:</strong> Year 4 (Preceptorship) students will only be allocated to sites marked "Year 4 Ready" above.
                        </p>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="send_notifications" id="send_notifications" checked>
                        <label for="send_notifications">Send notifications to students (logged in notification_log table)</label>
                    </div>
                    
                    <button type="submit" name="auto_allocate" class="btn-primary">Run Auto Allocation</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle role mode
        const roleModeRadios = document.querySelectorAll('input[name="role_mode"]');
        const manualRoleDiv = document.getElementById('manualRoleDiv');
        const autoRoleInfo = document.getElementById('autoRoleInfo');
        
        function toggleRoleMode() {
            const selectedMode = document.querySelector('input[name="role_mode"]:checked').value;
            if (selectedMode === 'manual') {
                manualRoleDiv.style.display = 'block';
                autoRoleInfo.style.display = 'none';
            } else {
                manualRoleDiv.style.display = 'none';
                autoRoleInfo.style.display = 'block';
            }
        }
        
        roleModeRadios.forEach(radio => {
            radio.addEventListener('change', toggleRoleMode);
        });
        
        toggleRoleMode();
        
        // Set default dates
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');
        
        if (startDateInput && !startDateInput.value) {
            const nextMonday = new Date();
            const daysUntilMonday = (1 - nextMonday.getDay() + 7) % 7 || 7;
            nextMonday.setDate(nextMonday.getDate() + daysUntilMonday);
            startDateInput.value = nextMonday.toISOString().split('T')[0];
        }
        
        if (endDateInput && !endDateInput.value && startDateInput.value) {
            const endDate = new Date(startDateInput.value);
            endDate.setDate(endDate.getDate() + 84);
            endDateInput.value = endDate.toISOString().split('T')[0];
        }
    </script>
    <script src="js/page-loader.js"></script>
</body>
</html>