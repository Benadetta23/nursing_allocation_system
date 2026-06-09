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

if (method_exists($database, 'getConnection')) {
    $conn = $database->getConnection();
} elseif (method_exists($database, 'connect')) {
    $conn = $database->connect();
} else {
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

function cleanDisplay($text) {
    if ($text === null) return '';
    $text = str_replace(['(', ')', '\\'], '', $text);
    return trim($text);
}

$all_students = $coordinator->getStudents();
$sites = $coordinator->getSites();

$allocated_students = [];
$allocations = $coordinator->getAllocationsWithDaysRemaining();
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
$total_capacity = array_sum(array_column($sites, 'max_students'));
$available_slots = $total_capacity - $total_allocated;

$unallocated_by_year = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($unallocated_students as $student) {
    $year = $student['year_of_study'] ?? 1;
    if (isset($unallocated_by_year[$year])) {
        $unallocated_by_year[$year]++;
    }
}

// Get site stats with student counts
$site_stats = [];
foreach ($sites as $site) {
    $current = 0;
    $site_students = [];
    foreach ($allocations as $alloc) {
        if ($alloc['site_id'] == $site['site_id']) {
            $current++;
            $site_students[] = $alloc;
        }
    }
    $site_stats[$site['site_id']] = [
        'name' => $site['name'],
        'location' => $site['location'] ?? '',
        'capacity' => $site['max_students'],
        'current' => $current,
        'available' => $site['max_students'] - $current,
        'students' => $site_students
    ];
}

$role_by_year = [
    1 => 'General Nursing',
    2 => 'Midwifery',
    3 => 'Critical Care',
    4 => 'Preceptorship'
];

function getRoleForStudent($student_year, $role_mode, $default_role) {
    global $role_by_year;
    if ($role_mode == 'auto') {
        return $role_by_year[$student_year] ?? 'General Nursing';
    }
    return $default_role;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['auto_allocate'])) {
    $allocation_count = 0;
    $notified_students = [];
    
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $role_mode = $_POST['role_mode'] ?? 'auto';
    $default_role = $_POST['default_role'] ?? 'General Nursing';
    $send_notifications = isset($_POST['send_notifications']) ? true : false;
    
    $conn->beginTransaction();
    try {
        $lockSites = $conn->prepare("SELECT site_id, max_students FROM clinical_site FOR UPDATE");
        $lockSites->execute();
        
        $freshSiteQuery = "SELECT cs.site_id, cs.name, cs.max_students, 
                                  (SELECT COUNT(*) FROM allocation a WHERE a.site_id = cs.site_id AND a.status = 'active') as current_count
                           FROM clinical_site cs ORDER BY cs.name";
        $freshStmt = $conn->prepare($freshSiteQuery);
        $freshStmt->execute();
        $freshSites = $freshStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $current_site_load = [];
        foreach ($freshSites as $site) {
            $site_id = $site['site_id'];
            $current_count = (int)$site['current_count'];
            $capacity = (int)$site['max_students'];
            $current_site_load[$site_id] = [
                'name' => $site['name'],
                'capacity' => $capacity,
                'current' => $current_count,
                'available' => $capacity - $current_count
            ];
        }
        
        $notification = new Notification($conn);
        
        $students_by_year = [];
        foreach ($unallocated_students as $s) {
            $yr = $s['year_of_study'] ?? 1;
            $students_by_year[$yr][] = $s;
        }
        
        $interleaved_students = [];
        $max_year_count = 0;
        foreach ($students_by_year as $yr => $list) {
            $max_year_count = max($max_year_count, count($list));
        }
        for ($i = 0; $i < $max_year_count; $i++) {
            for ($yr = 1; $yr <= 4; $yr++) {
                if (isset($students_by_year[$yr][$i])) {
                    $interleaved_students[] = $students_by_year[$yr][$i];
                }
            }
        }
        
        function pickBestSite($eligible_site_ids, &$site_load) {
            if (empty($eligible_site_ids)) return null;
            $best_site_id = null;
            $lowest_utilization = PHP_FLOAT_MAX;
            foreach ($eligible_site_ids as $site_id) {
                if (!isset($site_load[$site_id])) continue;
                $site = $site_load[$site_id];
                if ($site['available'] <= 0) continue;
                $utilization = $site['capacity'] > 0 ? ($site['current'] / $site['capacity']) : 1;
                $adjusted_utilization = $utilization + (mt_rand(0, 50) / 1000);
                if ($adjusted_utilization < $lowest_utilization) {
                    $lowest_utilization = $adjusted_utilization;
                    $best_site_id = $site_id;
                }
            }
            return $best_site_id;
        }
        
        $insertAllocStmt = $conn->prepare("INSERT INTO allocation (student_id, site_id, start_date, end_date, role, status) VALUES (:student_id, :site_id, :start_date, :end_date, :role, 'active')");
        $checkAllocStmt = $conn->prepare("SELECT alloc_id FROM allocation WHERE student_id = :student_id AND status = 'active'");
        
        foreach ($interleaved_students as $student) {
            $allocated = false;
            $student_year = $student['year_of_study'] ?? 1;
            $required_role = getRoleForStudent($student_year, $role_mode, $default_role);
            
            $checkAllocStmt->bindParam(':student_id', $student['student_id'], PDO::PARAM_INT);
            $checkAllocStmt->execute();
            if ($checkAllocStmt->fetch()) {
                continue;
            }
            
            $eligible_sites = [];
            foreach ($current_site_load as $site_id => $site_data) {
                if ($site_data['available'] > 0) {
                    $eligible_sites[] = $site_id;
                }
            }
            
            $best_site_id = pickBestSite($eligible_sites, $current_site_load);
            
            if ($best_site_id !== null) {
                $insertAllocStmt->bindParam(':student_id', $student['student_id'], PDO::PARAM_INT);
                $insertAllocStmt->bindParam(':site_id', $best_site_id, PDO::PARAM_INT);
                $insertAllocStmt->bindParam(':start_date', $start_date);
                $insertAllocStmt->bindParam(':end_date', $end_date);
                $insertAllocStmt->bindParam(':role', $required_role);
                $insertResult = $insertAllocStmt->execute();
                
                if ($insertResult) {
                    $allocation_count++;
                    $current_site_load[$best_site_id]['current']++;
                    $current_site_load[$best_site_id]['available']--;
                    $allocated = true;
                    
                    if ($send_notifications) {
                        try {
                            $studentEmailStmt = $conn->prepare("SELECT email, name FROM student WHERE student_id = :student_id");
                            $studentEmailStmt->bindParam(':student_id', $student['student_id'], PDO::PARAM_INT);
                            $studentEmailStmt->execute();
                            $studentData = $studentEmailStmt->fetch(PDO::FETCH_ASSOC);
                            if ($studentData) {
                                $siteNameStmt = $conn->prepare("SELECT name FROM clinical_site WHERE site_id = :site_id");
                                $siteNameStmt->bindParam(':site_id', $best_site_id, PDO::PARAM_INT);
                                $siteNameStmt->execute();
                                $siteData = $siteNameStmt->fetch(PDO::FETCH_ASSOC);
                                $notification->sendAllocationNotification(
                                    $student['student_id'], $studentData['email'], $studentData['name'],
                                    $siteData['name'], $start_date, $end_date, $required_role, 'both'
                                );
                                $notified_students[] = $studentData['name'];
                            }
                        } catch (Exception $e) {}
                    }
                }
            }
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error: " . $e->getMessage();
    }
    
    if ($allocation_count > 0) {
        $message = "Allocated $allocation_count students.";
        if (count($notified_students) > 0) {
            $message .= " Notified " . count($notified_students) . " students.";
        }
        header("Location: auto_allocate.php?success=" . urlencode($message));
        exit;
    }
}

if (isset($_GET['success'])) {
    $message = $_GET['success'];
}

// Refresh allocations after potential changes
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

// Refresh site stats with updated student lists
$site_stats = [];
foreach ($sites as $site) {
    $current = 0;
    $site_students = [];
    foreach ($allocations as $alloc) {
        if ($alloc['site_id'] == $site['site_id']) {
            $current++;
            $site_students[] = $alloc;
        }
    }
    $site_stats[$site['site_id']] = [
        'name' => $site['name'],
        'location' => $site['location'] ?? '',
        'capacity' => $site['max_students'],
        'current' => $current,
        'available' => $site['max_students'] - $current,
        'students' => $site_students
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
            background: #ffffff;
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
            color: #ffffff;
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
            font-size: 1rem;
            font-weight: 500;
            color: #ffffff;
            text-decoration: none;
            display: inline-block;
            transition: 0.3s;
            cursor: pointer;
        }
        
        .nav-tab:hover {
            color: #ffffff;
            background: #654321;
        }
        
        .nav-tab.active {
            color: #ffffff;
            background: #4a2f1a;
            border-bottom: 3px solid #c3a343;
        }
        
        .container {
            max-width: 1200px;
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
            font-size: 1.3rem;
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
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        
        .site-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid #c3a343;
            transition: 0.2s;
        }
        
        .site-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .site-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #4a2f1a;
            margin-bottom: 8px;
        }
        
        .site-location {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 12px;
        }
        
        .site-capacity {
            font-size: 0.85rem;
            color: #555;
            margin: 8px 0;
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
        
        .view-btn {
            background: #c3a343;
            color: #4a2f1a;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            width: 100%;
            margin-top: 12px;
        }
        
        .view-btn:hover {
            background: #d4b35a;
        }
        
        .students-list {
            margin-top: 15px;
            background: white;
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #e0e0e0;
            display: none;
        }
        
        .students-list h4 {
            font-size: 0.85rem;
            color: #4a2f1a;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        
        .students-list ul {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .students-list li {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.8rem;
            color: #333;
        }
        
        .students-list li:last-child {
            border-bottom: none;
        }
        
        .student-role {
            font-size: 0.7rem;
            color: #c3a343;
            margin-left: 8px;
        }
        
        .empty-list {
            color: #999;
            font-size: 0.8rem;
            text-align: center;
            padding: 10px;
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
            width: 100%;
            transition: 0.3s;
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
            .container {
                padding: 20px;
            }
            .header {
                flex-direction: column;
                text-align: center;
            }
            .nav-tabs {
                justify-content: center;
            }
            .stats {
                flex-direction: column;
            }
            .site-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
        
        <!-- Statistics -->
        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($unallocated_students); ?></div>
                <div class="stat-label">Unallocated Students</div>
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
        
        <!-- Auto Allocation Form -->
        <div class="card">
            <h2>Auto Allocation</h2>
            <?php if (count($unallocated_students) == 0): ?>
                <div class="success-msg" style="text-align:center;">All students have been allocated. No pending allocations.</div>
            <?php elseif ($available_slots <= 0): ?>
                <div class="success-msg" style="text-align:center;background:#fff3cd;color:#856404;border-left-color:#ffc107;">No available slots. Please increase site capacity or add more clinical sites.</div>
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
                            <label><input type="radio" name="role_mode" value="auto" checked> Auto (Based on Year of Study)</label>
                            <label><input type="radio" name="role_mode" value="manual"> Manual (Same Role for All)</label>
                        </div>
                    </div>
                    
                    <div id="manualRoleDiv" class="manual-role-div" style="display:none;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Default Role</label>
                            <input type="text" name="default_role" value="General Nursing">
                        </div>
                    </div>
                    
                    <div id="autoRoleInfo" class="info-box">
                        <p><strong>Year to Role Mapping:</strong></p>
                        <ul>
                            <li>Year 1 -> General Nursing (Basic Care)</li>
                            <li>Year 2 -> Midwifery (Specialized Care)</li>
                            <li>Year 3 -> Critical Care (Complex Care)</li>
                            <li>Year 4 -> Preceptorship (Leadership)</li>
                        </ul>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="send_notifications" id="send_notifications" checked>
                        <label for="send_notifications">Send email notifications to students</label>
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
            const mode = document.querySelector('input[name="role_mode"]:checked').value;
            manualRoleDiv.style.display = mode === 'manual' ? 'block' : 'none';
            autoRoleInfo.style.display = mode === 'auto' ? 'block' : 'none';
        }
        roleModeRadios.forEach(r => r.addEventListener('change', toggleRoleMode));
        toggleRoleMode();
        
        // Toggle site details
        function toggleSite(id) {
            const el = document.getElementById('site-' + id);
            if (el.style.display === 'none' || el.style.display === '') {
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
            }
        }
        
        // Hide all site details initially
        document.querySelectorAll('.students-list').forEach(el => el.style.display = 'none');
        
        // Set default dates
        const startInput = document.querySelector('input[name="start_date"]');
        const endInput = document.querySelector('input[name="end_date"]');
        if (startInput && !startInput.value) {
            const nextMon = new Date();
            nextMon.setDate(nextMon.getDate() + ((1 - nextMon.getDay() + 7) % 7 || 7));
            startInput.value = nextMon.toISOString().split('T')[0];
        }
        if (endInput && !endInput.value && startInput.value) {
            const end = new Date(startInput.value);
            end.setDate(end.getDate() + 84);
            endInput.value = end.toISOString().split('T')[0];
        }
    </script>
</body>
</html>