<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Lecturer.php';
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$db = new Database();
$conn = $db->getConnection();

$email = $_SESSION['email'] ?? 'lecturer@daeyang.edu';
$query = "SELECT lecturer_id, name, email FROM lecturer WHERE email = :email";
$stmt = $conn->prepare($query);
$stmt->bindParam(':email', $email);
$stmt->execute();
$lecturerData = $stmt->fetch(PDO::FETCH_ASSOC);

$lecturer_id = $lecturerData ? $lecturerData['lecturer_id'] : 1;
$lecturer_name = $lecturerData ? $lecturerData['name'] : $_SESSION['name'];
$lecturer_email = $lecturerData ? $lecturerData['email'] : $_SESSION['email'];

// Initialize Notification class
$notification = new Notification($conn);
$unread_count = $notification->getUnreadCount($lecturer_id, 'lecturer');
$notifications = $notification->getAllNotifications($lecturer_id, 'lecturer', 10);

$lecturer = new Lecturer($lecturer_id);

// Get assigned sites for this lecturer
$assigned_sites = $lecturer->getAssignedSites();

$message = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'assessment';
$selected_site_id = isset($_GET['site_id']) ? $_GET['site_id'] : '';

// If no site selected but there are assigned sites, auto-select the first one
if (empty($selected_site_id) && !empty($assigned_sites)) {
    $selected_site_id = $assigned_sites[0]['site_id'];
}

// Mark notification as read if requested
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification->markAsRead($_GET['mark_read']);
    header("Location: Lecturer_Dashboard.php?tab=" . $active_tab . "&site_id=" . $selected_site_id);
    exit();
}

// Mark all as read if requested
if (isset($_GET['mark_all_read'])) {
    $notification->markAllAsRead($lecturer_id, 'lecturer');
    header("Location: Lecturer_Dashboard.php?tab=" . $active_tab . "&site_id=" . $selected_site_id);
    exit();
}

// Get students based on selected site
$studentsAtSite = [];
$pendingCount = 0;
$completedCount = 0;
$readyForAssessmentCount = 0;
$dailyMarkedStudents = [];

if ($selected_site_id) {
    $studentsAtSite = $lecturer->getStudentsBySite($selected_site_id);
    $readyForAssessment = $lecturer->getStudentsReadyForAssessment($selected_site_id);
    $dailyMarkedStudents = $lecturer->getStudentsWithDailyMarks($selected_site_id);
    
    // Create lookup for ready students
    $readyLookup = [];
    foreach ($readyForAssessment as $ready) {
        $readyLookup[$ready['student_id']] = $ready;
    }
    
    // Calculate pending and completed counts
    foreach ($studentsAtSite as $student) {
        $matronDone = ($student['matron_assessed'] > 0);
        $matronFinalized = !is_null($student['matron_finalized'] ?? null);
        $lecturerDone = ($student['already_assessed'] > 0);
        
        if ($matronFinalized && !$lecturerDone) {
            $readyForAssessmentCount++;
        } elseif ($matronDone && !$lecturerDone) {
            $pendingCount++;
        } elseif ($lecturerDone) {
            $completedCount++;
        }
    }
    
    // Merge daily marked students info
    $dailyMarkedMap = [];
    foreach ($dailyMarkedStudents as $dm) {
        $dailyMarkedMap[$dm['student_id']] = $dm;
    }
    
    // Add daily mark info to students
    foreach ($studentsAtSite as &$student) {
        if (isset($dailyMarkedMap[$student['student_id']])) {
            $student['daily_mark_count'] = $dailyMarkedMap[$student['student_id']]['daily_mark_count'];
            $student['avg_performance'] = $dailyMarkedMap[$student['student_id']]['avg_performance'];
            $student['last_marked_date'] = $dailyMarkedMap[$student['student_id']]['last_marked_date'];
        }
        $student['matron_finalized'] = $student['matron_finalized'] ?? null;
        $student['ready_for_assessment'] = isset($readyLookup[$student['student_id']]);
    }
}

// Handle assessment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_assessment'])) {
    $result = $lecturer->saveAssessment(
        $_POST['student_id'], 
        $_POST['site_id'], 
        $_POST['punctuality'], 
        $_POST['dressing'], 
        $_POST['communication'], 
        $_POST['comments']
    );
    
    if ($result['success']) {
        $message = "Final Assessment saved successfully. Final Grade: " . $result['final_grade'] . "% (" . $result['status'] . ")";
        if ($selected_site_id) {
            $studentsAtSite = $lecturer->getStudentsBySite($selected_site_id);
            $readyForAssessment = $lecturer->getStudentsReadyForAssessment($selected_site_id);
            $readyLookup = [];
            foreach ($readyForAssessment as $ready) {
                $readyLookup[$ready['student_id']] = $ready;
            }
            foreach ($studentsAtSite as &$student) {
                $student['ready_for_assessment'] = isset($readyLookup[$student['student_id']]);
            }
        }
    } else {
        $error = $result['error'] ?? "Failed to save assessment. Matron assessment must be completed first.";
    }
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $updateQuery = "UPDATE lecturer SET name = :name, email = :email WHERE lecturer_id = :lecturer_id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(':name', $_POST['name']);
    $updateStmt->bindParam(':email', $_POST['email']);
    $updateStmt->bindParam(':lecturer_id', $lecturer_id);
    if ($updateStmt->execute()) {
        $_SESSION['name'] = $_POST['name'];
        $_SESSION['email'] = $_POST['email'];
        $lecturer_name = $_POST['name'];
        $lecturer_email = $_POST['email'];
        $message = "Profile updated successfully";
    } else {
        $error = "Failed to update profile";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lecturer Dashboard - Daeyang University</title>
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
        
        /* Notification Bell Styles */
        .notification-bell {
            position: relative;
            cursor: pointer;
            display: inline-block;
        }
        
        .bell-icon {
            font-size: 24px;
            color: #c3a343;
            background: none;
            border: none;
            cursor: pointer;
            position: relative;
        }
        
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }
        
        .notification-dropdown {
            position: absolute;
            right: 0;
            top: 35px;
            width: 350px;
            max-height: 400px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            overflow: hidden;
        }
        
        .notification-dropdown.show {
            display: block;
        }
        
        .dropdown-header {
            background: #4a2f1a;
            color: white;
            padding: 12px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dropdown-header h4 {
            margin: 0;
            font-size: 1rem;
        }
        
        .dropdown-header a {
            color: #c3a343;
            font-size: 0.75rem;
            text-decoration: none;
        }
        
        .dropdown-header a:hover {
            text-decoration: underline;
        }
        
        .dropdown-body {
            max-height: 350px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        
        .notification-item:hover {
            background: #f5f5f5;
        }
        
        .notification-item.unread {
            background: #f0f7ff;
            border-left: 3px solid #c3a343;
        }
        
        .notification-item.read {
            background: white;
        }
        
        .notification-title {
            font-weight: 600;
            color: #4a2f1a;
            margin-bottom: 5px;
            font-size: 0.85rem;
        }
        
        .notification-message {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 5px;
            line-height: 1.4;
        }
        
        .notification-time {
            font-size: 0.65rem;
            color: #999;
        }
        
        .no-notifications {
            text-align: center;
            padding: 30px;
            color: #999;
            font-size: 0.85rem;
        }
        
        .mark-read-btn {
            font-size: 0.7rem;
            color: #c3a343;
            text-decoration: none;
            margin-top: 5px;
            display: inline-block;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .welcome-card {
            background: white;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #c3a343;
        }
        
        .welcome-card h2 {
            color: #4a2f1a;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .welcome-card p {
            color: #666;
        }
        
        .content-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .content-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .assigned-site-info {
            background: #f0f7ff;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
        }
        
        .assigned-site-info p {
            margin: 5px 0;
            font-size: 0.9rem;
        }
        
        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            flex: 1;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-bottom: 2px solid #c3a343;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #4a2f1a;
        }
        
        .stat-label {
            font-size: 0.7rem;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a2f1a;
        }
        
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .student-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid #c3a343;
            transition: all 0.2s;
        }
        
        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .student-card h4 {
            color: #4a2f1a;
            margin-bottom: 10px;
        }
        
        .student-card p {
            color: #555;
            font-size: 0.85rem;
            margin: 5px 0;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-secondary {
            background: #6c757d;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-primary {
            background: #c3a343;
            color: #4a2f1a;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
            font-weight: bold;
        }
        
        .btn-primary {
            background: #4a2f1a;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            transition: 0.3s;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: #654321;
        }
        
        .btn-secondary {
            background: #c3a343;
            color: #4a2f1a;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.3s;
            font-weight: 500;
        }
        
        .profile-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
        }
        
        .info-box label {
            font-weight: 600;
            color: #4a2f1a;
            display: block;
            margin-bottom: 5px;
            font-size: 0.8rem;
        }
        
        .info-box p {
            color: #333;
            font-size: 1rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 650px;
            padding: 25px;
            border-top: 5px solid #c3a343;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            color: #4a2f1a;
        }
        
        .close {
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: 0.3s;
        }
        
        .close:hover {
            color: #dc3545;
        }
        
        .student-info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
        }
        
        .student-info-card p {
            margin: 5px 0;
        }
        
        .daily-marks-preview {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .daily-marks-preview h4 {
            color: #2e7d32;
            margin-bottom: 10px;
        }
        
        .daily-marks-table {
            width: 100%;
            font-size: 0.75rem;
            border-collapse: collapse;
        }
        
        .daily-marks-table th,
        .daily-marks-table td {
            padding: 6px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .matron-score {
            font-size: 0.9rem;
            font-weight: bold;
            color: #28a745;
            margin-top: 10px;
        }
        
        .score-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .score-item {
            flex: 1;
        }
        
        .score-item label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4a2f1a;
        }
        
        .score-item input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            text-align: center;
        }
        
        .grade-preview {
            background: #e3f2fd;
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }
        
        .grade-preview span {
            font-weight: bold;
            color: #1565c0;
        }
        
        .modal-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-save {
            flex: 1;
            background: #4a2f1a;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-save:hover {
            background: #654321;
        }
        
        .btn-cancel {
            flex: 1;
            background: #f0f0f0;
            color: #666;
            padding: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .history-table th,
        .history-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .history-table th {
            background: #4a2f1a;
            color: white;
            font-weight: 600;
        }
        
        .history-table tr:hover {
            background: #f5f5f5;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 40px;
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
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .students-grid { grid-template-columns: 1fr; }
            .score-row { flex-direction: column; }
            .modal-buttons { flex-direction: column; }
            .profile-info { grid-template-columns: 1fr; }
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
            .stats-row { flex-direction: column; }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Lecturer Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($lecturer_name); ?></span>
            <span class="role-badge">Lecturer</span>
            
            <!-- Notification Bell -->
            <div class="notification-bell" id="notificationBell">
                <button class="bell-icon" onclick="toggleNotifications()">🔔</button>
                <?php if ($unread_count > 0): ?>
                    <span class="notification-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
                
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="dropdown-header">
                        <h4>Notifications</h4>
                        <?php if ($unread_count > 0): ?>
                            <a href="?mark_all_read=1&tab=<?php echo $active_tab; ?>&site_id=<?php echo $selected_site_id; ?>">Mark all as read</a>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-body">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $notif): ?>
                                <div class="notification-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>">
                                    <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notification-message"><?php echo htmlspecialchars(substr($notif['message'], 0, 100)); ?></div>
                                    <div class="notification-time"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
                                    <?php if (!$notif['is_read']): ?>
                                        <a href="?mark_read=<?php echo $notif['notification_id']; ?>&tab=<?php echo $active_tab; ?>&site_id=<?php echo $selected_site_id; ?>" class="mark-read-btn">Mark as read</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-notifications">No notifications yet</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="?tab=assessment" class="nav-tab <?php echo $active_tab == 'assessment' ? 'active' : ''; ?>">Final Assessment</a>
        <a href="?tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'active' : ''; ?>">Assessment History</a>
        <a href="?tab=profile" class="nav-tab <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">My Profile</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="welcome-card">
            <h2>Welcome, <?php echo htmlspecialchars($lecturer_name); ?></h2>
            <p>Conduct final assessments for students at your assigned clinical sites.</p>
        </div>
        
        <div id="assessmentSection" class="content-section <?php echo $active_tab == 'assessment' ? 'active' : ''; ?>">
            <div class="card">
                <h2>Final Assessment (Lecturer)</h2>
                
                <?php if (count($assigned_sites) == 0): ?>
                    <div class="error-msg">
                        You have not been assigned to any clinical sites. Please contact the coordinator to assign you to sites.
                    </div>
                <?php else: ?>
                
                <!-- Site Selector (only shows assigned sites) -->
                <div class="form-group">
                    <label for="siteSelect">Select Clinical Site</label>
                    <select id="siteSelect" onchange="window.location.href='?tab=assessment&site_id='+this.value">
                        <?php foreach ($assigned_sites as $site): ?>
                            <option value="<?php echo $site['site_id']; ?>" <?php echo $selected_site_id == $site['site_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?> (<?php echo htmlspecialchars($site['location']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Assigned Site Info -->
                <?php if ($selected_site_id): 
                    $currentSite = array_filter($assigned_sites, function($s) use ($selected_site_id) {
                        return $s['site_id'] == $selected_site_id;
                    });
                    $currentSite = reset($currentSite);
                ?>
                <div class="assigned-site-info">
                    <p><strong>Current Site:</strong> <?php echo htmlspecialchars($currentSite['name']); ?> (<?php echo htmlspecialchars($currentSite['location']); ?>)</p>
                    <p><strong>Contact:</strong> <?php echo htmlspecialchars($currentSite['contact_person']); ?> | <?php echo htmlspecialchars($currentSite['contact_phone']); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($selected_site_id && count($studentsAtSite) > 0): ?>
                    
                    <!-- Statistics -->
                    <div class="stats-row">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count($studentsAtSite); ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #28a745;"><?php echo $readyForAssessmentCount; ?></div>
                            <div class="stat-label">Ready for Assessment</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $pendingCount; ?></div>
                            <div class="stat-label">Awaiting Matron Final</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $completedCount; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    
                    <div class="students-grid">
                        <?php foreach ($studentsAtSite as $student): ?>
                            <div class="student-card">
                                <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                                <p>ID: <?php echo htmlspecialchars($student['student_number']); ?></p>
                                <p>Cohort: <?php echo htmlspecialchars($student['cohort']); ?></p>
                                <p>Role: <?php echo htmlspecialchars($student['role']); ?></p>
                                <?php
                                $matronDone = ($student['matron_assessed'] > 0);
                                $matronFinalized = !is_null($student['matron_finalized'] ?? null);
                                $lecturerDone = ($student['already_assessed'] > 0);
                                
                                if ($lecturerDone):
                                ?>
                                    <span class="badge-success">Final Assessment Complete</span>
                                    <button class="btn-secondary assess-btn" 
                                        data-student='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>' 
                                        data-siteid="<?php echo $selected_site_id; ?>"
                                        data-viewonly="1">
                                        View Assessment
                                    </button>
                                <?php elseif ($student['ready_for_assessment']): ?>
                                    <span class="badge-primary">Ready for Assessment</span>
                                    <span class="badge-success" style="margin-top: 5px;">Matron Finalized</span>
                                    <?php if (isset($student['daily_mark_count']) && $student['daily_mark_count'] > 0): ?>
                                        <p style="font-size: 0.7rem; color: #28a745; margin-top: 5px;">
                                            Daily Marks: <?php echo $student['daily_mark_count']; ?> days
                                        </p>
                                    <?php endif; ?>
                                    <button class="btn-primary assess-btn" 
                                        data-student='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>' 
                                        data-siteid="<?php echo $selected_site_id; ?>">
                                        Start Final Assessment
                                    </button>
                                <?php elseif ($matronFinalized && !$lecturerDone): ?>
                                    <span class="badge-primary">Ready for Assessment</span>
                                    <button class="btn-primary assess-btn" 
                                        data-student='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>' 
                                        data-siteid="<?php echo $selected_site_id; ?>">
                                        Start Final Assessment
                                    </button>
                                <?php elseif ($matronDone && !$lecturerDone): ?>
                                    <span class="badge-warning">Awaiting Matron Finalization</span>
                                    <button class="btn-secondary" disabled>Matron Assessment in Progress</button>
                                <?php elseif (!$matronDone): ?>
                                    <span class="badge-secondary">Awaiting Matron Initial Assessment</span>
                                    <button class="btn-secondary" disabled>Not Available</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($selected_site_id): ?>
                    <p class="no-data">No students allocated to this clinical site.</p>
                <?php else: ?>
                    <p class="no-data">Please select a clinical site to view students.</p>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>
        
        <div id="historySection" class="content-section <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Assessment History</h2>
                <?php
                $history = $lecturer->getAssessmentHistory();
                if (count($history) > 0):
                ?>
                <div style="overflow-x: auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Site</th>
                                <th>Matron Score</th>
                                <th>Final Grade</th>
                                <th>Status</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($h['assessment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($h['student_name']); ?> (<?php echo htmlspecialchars($h['student_number']); ?>)</td>
                                <td><?php echo htmlspecialchars($h['site_name']); ?></td>
                                <td><?php echo $h['daily_marks_aggregate'] ?? '-'; ?>%</td>
                                <td><strong><?php echo $h['final_grade'] ?? '-'; ?>%</strong></td>
                                <td class="<?php echo ($h['pass_fail_status'] ?? 'Pending') == 'Pass' ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $h['pass_fail_status'] ?? 'Pending'; ?>
                                 </td>
                                <td><?php echo htmlspecialchars(substr($h['comments'], 0, 50)); ?>...</td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="no-data">No assessments recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="profileSection" class="content-section <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-box">
                        <label>Full Name</label>
                        <p><?php echo htmlspecialchars($lecturer_name); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Email Address</label>
                        <p><?php echo htmlspecialchars($lecturer_email); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Role</label>
                        <p>Lecturer - Nursing Department</p>
                    </div>
                </div>
                
                <h3 style="margin-top: 20px;">Edit Profile</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($lecturer_name); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($lecturer_email); ?>" required>
                    </div>
                    <button type="submit" name="update_profile" class="btn-secondary">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assessment Modal -->
    <div id="assessmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Final Assessment (Lecturer)</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" id="assessmentForm">
                <div class="modal-body">
                    <input type="hidden" id="assessStudentId" name="student_id">
                    <input type="hidden" id="assessSiteId" name="site_id">
                    
                    <div class="student-info-card">
                        <p><strong>Student:</strong> <span id="studentName"></span></p>
                        <p><strong>Student ID:</strong> <span id="studentNumber"></span></p>
                        <p><strong>Role:</strong> <span id="studentRole"></span></p>
                        <p><strong>Cohort:</strong> <span id="studentCohort"></span></p>
                    </div>
                    
                    <div id="dailyMarksPreview" class="daily-marks-preview" style="display: none;">
                        <h4>Matron's Daily Marks Summary</h4>
                        <div id="dailyMarksContent">Loading...</div>
                        <div id="matronAggregate" class="matron-score"></div>
                    </div>
                    
                    <div class="score-row">
                        <div class="score-item">
                            <label>Punctuality (1-5)</label>
                            <input type="number" id="punctuality" name="punctuality" min="1" max="5" required>
                        </div>
                        <div class="score-item">
                            <label>Dressing (1-5)</label>
                            <input type="number" id="dressing" name="dressing" min="1" max="5" required>
                        </div>
                        <div class="score-item">
                            <label>Communication (1-5)</label>
                            <input type="number" id="communication" name="communication" min="1" max="5" required>
                        </div>
                    </div>
                    
                    <div id="gradePreview" class="grade-preview">
                        Final Grade Calculation: (Matron Score × 60%) + (Lecturer Score × 40%) = <span id="previewGrade">-</span>%
                    </div>
                    
                    <div class="form-group">
                        <label>Comments</label>
                        <textarea id="comments" name="comments" rows="4" placeholder="Add your final observations..."></textarea>
                    </div>
                    
                    <div class="modal-buttons">
                        <button type="submit" name="submit_assessment" class="btn-save">Save Final Assessment</button>
                        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Notification toggle function
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const bell = document.getElementById('notificationBell');
            const dropdown = document.getElementById('notificationDropdown');
            
            if (bell && !bell.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        // Prevent dropdown from closing when clicking inside
        const dropdown = document.getElementById('notificationDropdown');
        if (dropdown) {
            dropdown.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }
        
        function openAssessmentModal(student, siteId, viewOnly = false) {
            document.getElementById('assessStudentId').value = student.student_id;
            document.getElementById('assessSiteId').value = siteId;
            document.getElementById('studentName').textContent = student.name;
            document.getElementById('studentNumber').textContent = student.student_number;
            document.getElementById('studentRole').textContent = student.role || 'General Nursing';
            document.getElementById('studentCohort').textContent = student.cohort || '2024';
            
            // Load daily marks preview
            loadDailyMarksPreview(student.student_id, siteId);
            
            // If view only (already assessed), disable inputs
            if (viewOnly) {
                document.getElementById('punctuality').disabled = true;
                document.getElementById('dressing').disabled = true;
                document.getElementById('communication').disabled = true;
                document.getElementById('comments').disabled = true;
                document.querySelector('button[name="submit_assessment"]').style.display = 'none';
            } else {
                document.getElementById('punctuality').disabled = false;
                document.getElementById('dressing').disabled = false;
                document.getElementById('communication').disabled = false;
                document.getElementById('comments').disabled = false;
                document.querySelector('button[name="submit_assessment"]').style.display = 'block';
            }
            
            // If editing existing assessment, populate fields
            if (student.already_assessed > 0) {
                fetch('ajax/get_assessment.php?student_id=' + student.student_id + '&site_id=' + siteId + '&type=final')
                    .then(response => response.json())
                    .then(data => {
                        if (data) {
                            document.getElementById('punctuality').value = data.punctuality_score || '';
                            document.getElementById('dressing').value = data.dressing_score || '';
                            document.getElementById('communication').value = data.communication_score || '';
                            document.getElementById('comments').value = data.comments || '';
                        }
                    });
            } else {
                document.getElementById('punctuality').value = '';
                document.getElementById('dressing').value = '';
                document.getElementById('communication').value = '';
                document.getElementById('comments').value = '';
            }
            
            // Add input listeners for grade preview
            const inputs = ['punctuality', 'dressing', 'communication'];
            inputs.forEach(id => {
                document.getElementById(id).addEventListener('input', updateGradePreview);
            });
            
            document.getElementById('assessmentModal').style.display = 'flex';
        }
        
        function loadDailyMarksPreview(studentId, siteId) {
            const previewDiv = document.getElementById('dailyMarksPreview');
            const contentDiv = document.getElementById('dailyMarksContent');
            const aggregateDiv = document.getElementById('matronAggregate');
            
            fetch('ajax/get_daily_marks_preview.php?student_id=' + studentId + '&site_id=' + siteId)
                .then(response => response.json())
                .then(data => {
                    if (data.has_marks) {
                        previewDiv.style.display = 'block';
                        let html = '<table class="daily-marks-table">';
                        html += '<tr><th>Date</th><th>Punctuality</th><th>Performance</th><th>Behavior</th></tr>';
                        data.marks.forEach(mark => {
                            html += '<tr>';
                            html += '<td>' + mark.marking_date + '</td>';
                            html += '<td>' + mark.punctuality + '/5</td>';
                            html += '<td>' + mark.performance + '/5</td>';
                            html += '<td>' + mark.behavior + '/5</td>';
                            html += '</tr>';
                        });
                        html += '</table>';
                        contentDiv.innerHTML = html;
                        aggregateDiv.innerHTML = 'Matron Aggregate Score: <strong>' + data.aggregate + '%</strong> (Weight: 60%)';
                        window.matronAggregate = data.aggregate;
                    } else {
                        previewDiv.style.display = 'none';
                        window.matronAggregate = 0;
                    }
                    updateGradePreview();
                })
                .catch(error => {
                    previewDiv.style.display = 'none';
                    window.matronAggregate = 0;
                });
        }
        
        function updateGradePreview() {
            const punctuality = parseFloat(document.getElementById('punctuality').value) || 0;
            const dressing = parseFloat(document.getElementById('dressing').value) || 0;
            const communication = parseFloat(document.getElementById('communication').value) || 0;
            
            let lecturerScore = (punctuality + dressing + communication) / 3;
            if (lecturerScore <= 5) {
                lecturerScore = (lecturerScore / 5) * 100;
            }
            
            const matronScore = window.matronAggregate || 0;
            const finalGrade = (matronScore * 0.6) + (lecturerScore * 0.4);
            
            document.getElementById('previewGrade').textContent = finalGrade.toFixed(1);
        }
        
        function closeModal() {
            document.getElementById('assessmentModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('assessmentModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Attach assess-btn click handlers
        document.querySelectorAll('.assess-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const student = JSON.parse(this.dataset.student);
                const siteId = this.dataset.siteid;
                const viewOnly = this.dataset.viewonly === '1';
                openAssessmentModal(student, siteId, viewOnly);
            });
        });
    </script>
    </body>
</html>