<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'matron') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Matron.php';
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$db = new Database();
$conn = $db->getConnection();

$matron_id = $_SESSION['user_id'];
$matron_name = $_SESSION['name'];
$matron_email = $_SESSION['email'];

// Initialize Notification class
$notification = new Notification($conn);
$unread_count = $notification->getUnreadCount($matron_id, 'matron');
$notifications = $notification->getAllNotifications($matron_id, 'matron', 10);

$matron = new Matron($matron_id);

$message = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'assessment';

// Mark notification as read if requested
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification->markAsRead($_GET['mark_read']);
    header("Location: matron_dashboard.php?tab=" . $active_tab);
    exit();
}

// Mark all as read if requested
if (isset($_GET['mark_all_read'])) {
    $notification->markAllAsRead($matron_id, 'matron');
    header("Location: matron_dashboard.php?tab=" . $active_tab);
    exit();
}

// Get the matron's assigned site
$assignedSite = $matron->getAssignedSite();

if (!$assignedSite) {
    $error = "You have not been assigned to any clinical site. Please contact the coordinator.";
    $selected_site_id = null;
    $studentsAtSite = [];
} else {
    $selected_site_id = $assignedSite['site_id'];
    $studentsAtSite = $matron->getStudentsAtSite($selected_site_id);
}

// Handle assessment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_assessment'])) {
    if ($matron->saveAssessment($_POST['student_id'], $_POST['site_id'], $_POST['punctuality'], $_POST['dressing'], $_POST['communication'], $_POST['comments'])) {
        $message = "Initial Assessment saved successfully";
        if ($selected_site_id) {
            $studentsAtSite = $matron->getStudentsAtSite($selected_site_id);
        }
    } else {
        $error = "Failed to save assessment";
    }
}

// Handle daily marking submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_daily_mark'])) {
    if ($matron->saveOrUpdateDailyMark(
        $_POST['student_id'], 
        $_POST['site_id'], 
        $_POST['attendance'], 
        $_POST['punctuality'], 
        $_POST['performance'], 
        $_POST['behavior'], 
        $_POST['comments']
    )) {
        $message = "Daily mark recorded successfully for " . date('Y-m-d');
        if ($selected_site_id) {
            $studentsAtSite = $matron->getStudentsAtSite($selected_site_id);
        }
    } else {
        $error = "Failed to save daily mark";
    }
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $updateQuery = "UPDATE matron SET name = :name, email = :email WHERE matron_id = :matron_id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(':name', $_POST['name']);
    $updateStmt->bindParam(':email', $_POST['email']);
    $updateStmt->bindParam(':matron_id', $matron_id);
    if ($updateStmt->execute()) {
        $_SESSION['name'] = $_POST['name'];
        $_SESSION['email'] = $_POST['email'];
        $matron_name = $_POST['name'];
        $matron_email = $_POST['email'];
        $message = "Profile updated successfully";
    } else {
        $error = "Failed to update profile";
    }
}

// Get daily marking history for the assigned site
$dailyMarkingHistory = [];
if ($selected_site_id && $active_tab == 'daily_marking') {
    $dailyMarkingHistory = $matron->getDailyMarkingHistory($selected_site_id, 30);
}

// Get today's marks for the assigned site
$todaysMarks = [];
$markedStudentIds = [];
if ($selected_site_id) {
    $todaysMarks = $matron->getTodaysDailyMarks($selected_site_id);
    foreach ($todaysMarks as $mark) {
        $markedStudentIds[] = $mark['student_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Matron Dashboard - Daeyang University</title>
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
            font-size: 1.3rem;
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
            margin: 10px 0;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-marked {
            background: #d4edda;
            color: #155724;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-unmarked {
            background: #fff3cd;
            color: #856404;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            display: inline-block;
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
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-box {
            background: #f8f9fa;
            padding: 18px 20px;
            border-radius: 12px;
            border-bottom: 2px solid #c3a343;
        }
        
        .info-box label {
            font-weight: 600;
            color: #4a2f1a;
            display: block;
            margin-bottom: 8px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-box p {
            color: #333;
            font-size: 1rem;
            font-weight: 500;
        }
        
        .edit-profile-form {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
        }
        
        .edit-profile-form h3 {
            color: #4a2f1a;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #c3a343;
            display: inline-block;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            background: white;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #c3a343;
            box-shadow: 0 0 0 3px rgba(195, 163, 67, 0.1);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 550px;
            padding: 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .modal-header {
            background: #4a2f1a;
            padding: 20px 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            color: white;
            margin: 0;
            font-size: 1.2rem;
        }
        
        .modal-header .close {
            color: white;
            font-size: 28px;
            cursor: pointer;
            transition: 0.2s;
            line-height: 1;
        }
        
        .modal-header .close:hover {
            color: #c3a343;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .student-info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid #c3a343;
        }
        
        .student-info-card p {
            margin: 8px 0;
            color: #555;
        }
        
        .student-info-card strong {
            color: #4a2f1a;
            width: 100px;
            display: inline-block;
        }
        
        .score-row {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .score-item {
            flex: 1;
            min-width: 100px;
        }
        
        .score-item label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a2f1a;
            font-size: 0.85rem;
        }
        
        .score-item input, .score-item select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .score-item input:focus, .score-item select:focus {
            outline: none;
            border-color: #c3a343;
            box-shadow: 0 0 0 3px rgba(195, 163, 67, 0.1);
        }
        
        .comments-section {
            margin-bottom: 25px;
        }
        
        .comments-section label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a2f1a;
            font-size: 0.85rem;
        }
        
        .comments-section textarea {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            resize: vertical;
            transition: all 0.3s;
            background: #fafafa;
        }
        
        .comments-section textarea:focus {
            outline: none;
            border-color: #c3a343;
            background: white;
            box-shadow: 0 0 0 3px rgba(195, 163, 67, 0.1);
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
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.3s;
        }
        
        .btn-save:hover {
            background: #654321;
            transform: translateY(-2px);
        }
        
        .btn-cancel {
            flex: 1;
            background: #f0f0f0;
            color: #666;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.3s;
        }
        
        .btn-cancel:hover {
            background: #e0e0e0;
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
        
        .comment-cell {
            max-width: 250px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .students-grid { grid-template-columns: 1fr; }
            .score-row { flex-direction: column; }
            .modal-buttons { flex-direction: column; }
            .profile-info { grid-template-columns: 1fr; }
            .student-info-card p strong {
                width: auto;
                display: inline;
                margin-right: 5px;
            }
            .history-table {
                font-size: 0.8rem;
            }
            .history-table th,
            .history-table td {
                padding: 8px;
            }
            .comment-cell {
                max-width: 150px;
            }
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Matron / Clinical Supervisor Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($matron_name); ?></span>
            <span class="role-badge">Matron</span>
            
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
                            <a href="?mark_all_read=1&tab=<?php echo $active_tab; ?>">Mark all as read</a>
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
                                        <a href="?mark_read=<?php echo $notif['notification_id']; ?>&tab=<?php echo $active_tab; ?>" class="mark-read-btn">Mark as read</a>
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
        <a href="?tab=assessment" class="nav-tab <?php echo $active_tab == 'assessment' ? 'active' : ''; ?>">Initial Assessment</a>
        <a href="?tab=daily_marking" class="nav-tab <?php echo $active_tab == 'daily_marking' ? 'active' : ''; ?>">Daily Marking</a>
        <a href="?tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'active' : ''; ?>">Assessment History</a>
        <a href="?tab=profile" class="nav-tab <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">My Profile</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="welcome-card">
            <h2>Welcome, <?php echo htmlspecialchars($matron_name); ?></h2>
            <p>Manage initial assessments and daily marking for your assigned clinical site.</p>
        </div>
        
        <?php if (!$assignedSite): ?>
            <div class="error-msg">
                <strong>No clinical site assigned!</strong> Please contact the coordinator to assign you to a clinical site.
            </div>
        <?php else: ?>
        
        <!-- Assigned Site Info -->
        <div class="assigned-site-info">
            <p><strong>Your Assigned Clinical Site:</strong> <?php echo htmlspecialchars($assignedSite['name']); ?> (<?php echo htmlspecialchars($assignedSite['location']); ?>)</p>
            <p><strong>Contact:</strong> <?php echo htmlspecialchars($assignedSite['contact_person']); ?> | <?php echo htmlspecialchars($assignedSite['contact_phone']); ?></p>
        </div>
        
        <!-- Initial Assessment Section -->
        <div id="assessmentSection" class="content-section <?php echo $active_tab == 'assessment' ? 'active' : ''; ?>">
            <div class="card">
                <h2>Initial Assessment (Matron)</h2>
                
                <?php if (count($studentsAtSite) > 0): ?>
                    <div class="students-grid">
                        <?php foreach ($studentsAtSite as $student): ?>
                            <div class="student-card">
                                <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                                <p>ID: <?php echo htmlspecialchars($student['student_number']); ?></p>
                                <p>Cohort: <?php echo htmlspecialchars($student['cohort']); ?></p>
                                <p>Role: <?php echo htmlspecialchars($student['role']); ?></p>
                                <?php
                                $initialDone = $student['already_assessed'] > 0;
                                if ($initialDone):
                                ?>
                                    <span class="badge-success">Initial Assessment Complete</span>
                                    <button class="btn-secondary assess-btn" 
                                        data-student='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>' 
                                        data-siteid="<?php echo $selected_site_id; ?>">
                                        View Assessment
                                    </button>
                                <?php else: ?>
                                    <span class="badge-warning">Initial Assessment Pending</span>
                                    <button class="btn-primary assess-btn" 
                                        data-student='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>' 
                                        data-siteid="<?php echo $selected_site_id; ?>">
                                        Start Initial Assessment
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-data">No students allocated to your clinical site.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Daily Marking Section -->
        <div id="dailyMarkingSection" class="content-section <?php echo $active_tab == 'daily_marking' ? 'active' : ''; ?>">
            <div class="card">
                <h2>Daily Marking - <?php echo date('l, F j, Y'); ?></h2>
                <p style="color: #666; margin-bottom: 20px;">Record daily performance and attendance for students at your clinical site.</p>
                
                <?php if (count($studentsAtSite) > 0): ?>
                    <div class="students-grid">
                        <?php foreach ($studentsAtSite as $student): 
                            $isMarkedToday = in_array($student['student_id'], $markedStudentIds);
                        ?>
                            <div class="student-card">
                                <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                                <p>ID: <?php echo htmlspecialchars($student['student_number']); ?></p>
                                <p>Role: <?php echo htmlspecialchars($student['role']); ?></p>
                                <p>Cohort: <?php echo htmlspecialchars($student['cohort']); ?></p>
                                <?php if ($isMarkedToday): ?>
                                    <span class="badge-marked">✓ Marked Today</span>
                                <?php else: ?>
                                    <span class="badge-unmarked">⏳ Not Marked Yet</span>
                                <?php endif; ?>
                                <button type="button" class="btn-primary" style="margin-top: 10px;" 
                                    onclick="openDailyMarkModal(<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $selected_site_id; ?>)">
                                    Record Daily Mark
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-data">No students allocated to your clinical site.</p>
                <?php endif; ?>
            </div>
            
            <!-- Daily Marking History -->
            <?php if (!empty($dailyMarkingHistory)): ?>
            <div class="card">
                <h2>Recent Daily Marking History</h2>
                <div style="overflow-x: auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Attendance</th>
                                <th>Punctuality</th>
                                <th>Performance</th>
                                <th>Behavior</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyMarkingHistory as $mark): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($mark['marking_date'])); ?>\(
                                <td><?php echo htmlspecialchars($mark['student_name']); ?> (<?php echo $mark['student_number']; ?>)\(
                                <td>
                                    <span class="badge <?php echo $mark['attendance'] == 'Present' ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo $mark['attendance']; ?>
                                    </span>
                                \(
                                <td><?php echo $mark['punctuality']; ?>/5\(
                                <td><?php echo $mark['performance']; ?>/5\(
                                <td><?php echo $mark['behavior']; ?>/5\(
                                <td><?php echo htmlspecialchars(substr($mark['comments'] ?? '', 0, 50)); ?>\(
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Assessment History Section -->
        <div id="historySection" class="content-section <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Assessment History</h2>
                <?php
                $history = $matron->getAssessmentHistory();
                if (count($history) > 0):
                ?>
                <div style="overflow-x: auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Site</th>
                                <th>Punctuality</th>
                                <th>Dressing</th>
                                <th>Communication</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($h['assessment_date'])); ?>\(
                                <td>
                                    <?php echo htmlspecialchars($h['student_name']); ?><br>
                                    <small class="text-muted">(<?php echo htmlspecialchars($h['student_number']); ?>)</small>
                                \(
                                <td><?php echo htmlspecialchars($h['site_name']); ?>\(
                                <td><?php echo intval($h['punctuality_score']); ?>/5\(
                                <td><?php echo intval($h['dressing_score']); ?>/5\(
                                <td><?php echo intval($h['communication_score']); ?>/5\(
                                <td class="comment-cell" title="<?php echo htmlspecialchars($h['comments'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php 
                                    $comments = trim($h['comments']);
                                    if (strlen($comments) > 50) {
                                        echo htmlspecialchars(substr($comments, 0, 50), ENT_QUOTES, 'UTF-8') . '...';
                                    } else {
                                        echo htmlspecialchars($comments, ENT_QUOTES, 'UTF-8');
                                    }
                                    ?>
                                \(
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
        
        <?php endif; ?>
        
        <!-- Profile Section (always visible) -->
        <div id="profileSection" class="content-section <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-box">
                        <label>Full Name</label>
                        <p><?php echo htmlspecialchars($matron_name); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Email Address</label>
                        <p><?php echo htmlspecialchars($matron_email); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Role</label>
                        <p>Matron / Clinical Supervisor</p>
                    </div>
                </div>
                
                <div class="edit-profile-form">
                    <h3>Edit Profile</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($matron_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($matron_email); ?>" required>
                        </div>
                        <button type="submit" name="update_profile" class="btn-secondary">Update Profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assessment Modal -->
    <div id="assessmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Initial Assessment (Matron)</h3>
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
                    
                    <div class="comments-section">
                        <label for="comments">Comments / Observations</label>
                        <textarea id="comments" name="comments" rows="4" placeholder="Write your observations about the student's clinical performance..."></textarea>
                    </div>
                    
                    <div class="modal-buttons">
                        <button type="submit" name="submit_assessment" class="btn-save">Save Initial Assessment</button>
                        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Daily Marking Modal -->
    <div id="dailyMarkModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Daily Mark - <?php echo date('Y-m-d'); ?></h3>
                <span class="close" onclick="closeDailyMarkModal()">&times;</span>
            </div>
            <form method="POST" id="dailyMarkForm">
                <div class="modal-body">
                    <input type="hidden" id="dailyStudentId" name="student_id">
                    <input type="hidden" id="dailySiteId" name="site_id">
                    
                    <div class="student-info-card">
                        <p><strong>Student:</strong> <span id="dailyStudentName"></span></p>
                        <p><strong>Student ID:</strong> <span id="dailyStudentNumber"></span></p>
                        <p><strong>Role:</strong> <span id="dailyStudentRole"></span></p>
                    </div>
                    
                    <div class="score-row">
                        <div class="score-item">
                            <label>Attendance</label>
                            <select id="attendance" name="attendance" required>
                                <option value="">-- Select --</option>
                                <option value="Present">Present</option>
                                <option value="Absent">Absent</option>
                                <option value="Late">Late</option>
                            </select>
                        </div>
                        <div class="score-item">
                            <label>Punctuality (1-5)</label>
                            <input type="number" id="punctuality_daily" name="punctuality" min="1" max="5" required>
                        </div>
                    </div>
                    
                    <div class="score-row">
                        <div class="score-item">
                            <label>Performance (1-5)</label>
                            <input type="number" id="performance" name="performance" min="1" max="5" required>
                        </div>
                        <div class="score-item">
                            <label>Behavior (1-5)</label>
                            <input type="number" id="behavior" name="behavior" min="1" max="5" required>
                        </div>
                    </div>
                    
                    <div class="comments-section">
                        <label for="daily_comments">Comments</label>
                        <textarea id="daily_comments" name="comments" rows="3" placeholder="Observations for today..."></textarea>
                    </div>
                    
                    <div class="modal-buttons">
                        <button type="submit" name="submit_daily_mark" class="btn-save">Save Daily Mark</button>
                        <button type="button" class="btn-cancel" onclick="closeDailyMarkModal()">Cancel</button>
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
        
        // Assessment Modal Functions
        document.querySelectorAll('.assess-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const student = JSON.parse(this.dataset.student);
                const siteId = this.dataset.siteid;
                openAssessmentModal(student, siteId);
            });
        });
        
        function openAssessmentModal(student, siteId) {
            document.getElementById('assessStudentId').value = student.student_id;
            document.getElementById('assessSiteId').value = siteId;
            document.getElementById('studentName').textContent = student.name;
            document.getElementById('studentNumber').textContent = student.student_number;
            document.getElementById('studentRole').textContent = student.role || 'General Nursing';
            document.getElementById('studentCohort').textContent = student.cohort || '2024';
            
            document.getElementById('punctuality').value = '';
            document.getElementById('dressing').value = '';
            document.getElementById('communication').value = '';
            document.getElementById('comments').value = '';
            
            document.getElementById('assessmentModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('assessmentModal').style.display = 'none';
        }
        
        // Daily Marking Modal Functions
        function openDailyMarkModal(student, siteId) {
            document.getElementById('dailyStudentId').value = student.student_id;
            document.getElementById('dailySiteId').value = siteId;
            document.getElementById('dailyStudentName').textContent = student.name;
            document.getElementById('dailyStudentNumber').textContent = student.student_number;
            document.getElementById('dailyStudentRole').textContent = student.role || 'General Nursing';
            
            document.getElementById('attendance').value = '';
            document.getElementById('punctuality_daily').value = '';
            document.getElementById('performance').value = '';
            document.getElementById('behavior').value = '';
            document.getElementById('daily_comments').value = '';
            
            document.getElementById('dailyMarkModal').style.display = 'flex';
        }
        
        function closeDailyMarkModal() {
            document.getElementById('dailyMarkModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const assessModal = document.getElementById('assessmentModal');
            const dailyModal = document.getElementById('dailyMarkModal');
            if (event.target == assessModal) {
                closeModal();
            }
            if (event.target == dailyModal) {
                closeDailyMarkModal();
            }
        }
    </script>
</body>
</html>