<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Student.php';
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$db = new Database();
$conn = $db->getConnection();

$regNumber = $_SESSION['regNumber'];
$query = "SELECT student_id, name, email, student_number, cohort, mode_of_entry FROM student WHERE student_number = :regNumber";
$stmt = $conn->prepare($query);
$stmt->bindParam(':regNumber', $regNumber);
$stmt->execute();
$studentData = $stmt->fetch(PDO::FETCH_ASSOC);

$student_id = $studentData ? $studentData['student_id'] : 1;
$student_name = $studentData ? $studentData['name'] : $_SESSION['name'];
$student_email = $studentData ? $studentData['email'] : $_SESSION['email'];
$student_number = $studentData ? $studentData['student_number'] : $regNumber;
$student_cohort = $studentData ? $studentData['cohort'] : '2024';
$student_mode = $studentData ? $studentData['mode_of_entry'] : 'Generic';

// Initialize Notification class
$notification = new Notification($conn);
$unread_count = $notification->getUnreadCount($student_id, 'student');
$notifications = $notification->getAllNotifications($student_id, 'student', 10);

$student = new Student($student_id);
$placement = $student->getPlacement();
$results = $student->getResults();
$info = $student->getStudentInfo();
$finalGrade = $student->getFinalGrade();
$assessmentProgress = $student->getAssessmentProgress();

$message = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'placement';

// Mark notification as read if requested
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification->markAsRead($_GET['mark_read']);
    header("Location: Student_Dashboard.php?tab=" . $active_tab);
    exit();
}

// Mark all as read if requested
if (isset($_GET['mark_all_read'])) {
    $notification->markAllAsRead($student_id, 'student');
    header("Location: Student_Dashboard.php?tab=" . $active_tab);
    exit();
}

// Calculate days remaining for placement
$days_remaining = null;
$placement_status_text = '';
$placement_status_class = '';

if ($placement) {
    $today = new DateTime();
    $end_date = new DateTime($placement['end_date']);
    $days_remaining = $today->diff($end_date)->days;
    
    if ($placement['status'] == 'completed') {
        $placement_status_text = 'Completed';
        $placement_status_class = 'badge-completed';
    } elseif ($end_date < $today) {
        $days_remaining = -$days_remaining;
        $placement_status_text = 'Overdue';
        $placement_status_class = 'badge-overdue';
    } elseif ($days_remaining == 0) {
        $placement_status_text = 'Ends Today';
        $placement_status_class = 'badge-warning';
    } elseif ($days_remaining <= 7) {
        $placement_status_text = 'Ending Soon';
        $placement_status_class = 'badge-warning';
    } else {
        $placement_status_text = 'Active';
        $placement_status_class = 'badge-active';
    }
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $updateQuery = "UPDATE student SET name = :name, email = :email WHERE student_id = :student_id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(':name', $_POST['name']);
    $updateStmt->bindParam(':email', $_POST['email']);
    $updateStmt->bindParam(':student_id', $student_id);
    if ($updateStmt->execute()) {
        $_SESSION['name'] = $_POST['name'];
        $_SESSION['email'] = $_POST['email'];
        $student_name = $_POST['name'];
        $student_email = $_POST['email'];
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
    <title>Student Dashboard - Daeyang University</title>
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
        
        .card h3 {
            color: #4a2f1a;
            margin-bottom: 15px;
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
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .placement-info {
            margin-top: 10px;
        }
        
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #e8e8e8;
        }
        
        .info-label {
            width: 140px;
            font-weight: 600;
            color: #4a2f1a;
        }
        
        .info-value {
            flex: 1;
            color: #444;
        }
        
        .final-grade-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #c3a343;
            text-align: center;
        }
        
        .final-grade-score {
            font-size: 2rem;
            font-weight: 700;
            color: #4a2f1a;
        }
        
        .final-grade-letter {
            font-size: 1.2rem;
            font-weight: 600;
            color: #c3a343;
        }
        
        .badge-progress-warning {
            background: #ffc107;
            color: #333;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-progress-info {
            background: #17a2b8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-progress-success {
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .results-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .result-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 15px;
            border-left: 4px solid #c3a343;
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .result-date {
            font-size: 0.8rem;
            color: #666;
        }
        
        .result-site {
            font-size: 0.85rem;
            font-weight: 600;
            color: #4a2f1a;
        }
        
        .result-scores {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        
        .score {
            background: white;
            padding: 6px 12px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .score-label {
            font-size: 0.75rem;
            color: #666;
        }
        
        .score-value {
            font-weight: 700;
            font-size: 1rem;
        }
        
        .score-high {
            color: #28a745;
        }
        
        .score-medium {
            color: #fd7e14;
        }
        
        .score-low {
            color: #dc3545;
        }
        
        .result-comments {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
        }
        
        .comments-label {
            font-size: 0.7rem;
            color: #666;
            font-weight: 600;
        }
        
        .result-comments p {
            font-size: 0.85rem;
            color: #333;
            font-style: italic;
            margin: 5px 0;
        }
        
        .assessor-name {
            font-size: 0.7rem;
            color: #999;
            display: block;
            text-align: right;
            margin-top: 8px;
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
        
        .btn-secondary:hover {
            background: #d4b353;
        }
        
        .badge-active {
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-warning {
            background: #ffc107;
            color: #333;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-overdue {
            background: #dc3545;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .badge-completed {
            background: #6c757d;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
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
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
            .result-scores { flex-direction: column; }
            .profile-info { grid-template-columns: 1fr; }
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Student Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Student</span>
            
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
        <a href="?tab=placement" class="nav-tab <?php echo $active_tab == 'placement' ? 'active' : ''; ?>">My Placement</a>
        <a href="?tab=results" class="nav-tab <?php echo $active_tab == 'results' ? 'active' : ''; ?>">Assessment Results</a>
        <a href="?tab=profile" class="nav-tab <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">My Profile</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div id="placementSection" class="content-section <?php echo $active_tab == 'placement' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Clinical Placement</h2>
                <?php if ($placement): ?>
                    <div class="placement-info">
                        <div class="info-row">
                            <span class="info-label">Hospital / Site:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['site_name'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Location:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['location'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Contact Person:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['contact_person'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Role:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['role'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Start Date:</span>
                            <span class="info-value"><?php echo isset($placement['start_date']) ? date('M d, Y', strtotime($placement['start_date'])) : '-'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">End Date:</span>
                            <span class="info-value"><?php echo isset($placement['end_date']) ? date('M d, Y', strtotime($placement['end_date'])) : '-'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Days Remaining:</span>
                            <span class="info-value">
                                <?php if ($placement['status'] == 'completed'): ?>
                                    <span class="badge-completed">Completed</span>
                                <?php elseif ($days_remaining < 0): ?>
                                    <span class="badge-overdue">Overdue by <?php echo abs($days_remaining); ?> days</span>
                                <?php elseif ($days_remaining == 0): ?>
                                    <span class="badge-warning">Ends Today</span>
                                <?php elseif ($days_remaining <= 7): ?>
                                    <span class="badge-warning"><?php echo $days_remaining; ?> days remaining</span>
                                <?php else: ?>
                                    <span class="badge-active"><?php echo $days_remaining; ?> days remaining</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status:</span>
                            <span class="info-value">
                                <span class="<?php echo $placement_status_class; ?>"><?php echo $placement_status_text; ?></span>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Assessment Progress:</span>
                            <span class="info-value">
                                <?php if ($assessmentProgress): ?>
                                    <span class="<?php echo $assessmentProgress['badge_class']; ?>">
                                        <?php echo $assessmentProgress['message']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-progress-warning">No assessments yet</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Final Grade Card -->
                    <?php if ($finalGrade): ?>
                    <div class="final-grade-card">
                        <div class="final-grade-score"><?php echo $finalGrade['score']; ?> / 5</div>
                        <div class="final-grade-letter">Grade: <?php echo $finalGrade['grade']; ?> (<?php echo $finalGrade['grade_description']; ?>)</div>
                        <div style="font-size: 0.75rem; color: #666; margin-top: 5px;">
                            Based on <?php echo $finalGrade['total_assessments']; ?> assessment(s)
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="final-grade-card">
                        <div style="color: #666;">Final Grade: Not yet available</div>
                        <div style="font-size: 0.75rem; color: #999; margin-top: 5px;">
                            <?php if ($assessmentProgress && $assessmentProgress['matron_done'] && !$assessmentProgress['lecturer_done']): ?>
                                Initial assessment complete. Waiting for final assessment.
                            <?php elseif ($assessmentProgress && !$assessmentProgress['matron_done']): ?>
                                Waiting for initial assessment.
                            <?php else: ?>
                                Waiting for assessments from matron and lecturer.
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <p class="no-data">No active placement assigned yet. Please contact your coordinator.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="resultsSection" class="content-section <?php echo $active_tab == 'results' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Assessment Results</h2>
                <?php if (count($results) > 0): ?>
                    <div class="results-list">
                        <?php foreach ($results as $result): ?>
                            <div class="result-item">
                                <div class="result-header">
                                    <span class="result-date"><?php echo date('M d, Y', strtotime($result['assessment_date'])); ?></span>
                                    <span class="result-site"><?php echo htmlspecialchars($result['site_name'] ?? ''); ?></span>
                                    <span class="assessor-name" style="margin-top: 0;">
                                        <?php 
                                        if ($result['assessor_type'] == 'matron') {
                                            echo 'Initial Assessment (Matron)';
                                        } else {
                                            echo 'Final Assessment (Lecturer)';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="result-scores">
                                    <div class="score">
                                        <span class="score-label">Punctuality</span>
                                        <span class="score-value <?php echo $result['punctuality_score'] >= 4 ? 'score-high' : ($result['punctuality_score'] >= 3 ? 'score-medium' : 'score-low'); ?>"><?php echo $result['punctuality_score']; ?>/5</span>
                                    </div>
                                    <div class="score">
                                        <span class="score-label">Dressing</span>
                                        <span class="score-value <?php echo $result['dressing_score'] >= 4 ? 'score-high' : ($result['dressing_score'] >= 3 ? 'score-medium' : 'score-low'); ?>"><?php echo $result['dressing_score']; ?>/5</span>
                                    </div>
                                    <div class="score">
                                        <span class="score-label">Communication</span>
                                        <span class="score-value <?php echo $result['communication_score'] >= 4 ? 'score-high' : ($result['communication_score'] >= 3 ? 'score-medium' : 'score-low'); ?>"><?php echo $result['communication_score']; ?>/5</span>
                                    </div>
                                </div>
                                <?php if (!empty($result['comments'])): ?>
                                    <div class="result-comments">
                                        <span class="comments-label">Comments:</span>
                                        <p>"<?php echo htmlspecialchars($result['comments'] ?? ''); ?>"</p>
                                        <span class="assessor-name">— <?php echo htmlspecialchars($result['assessor_name'] ?? ($result['assessor_type'] == 'matron' ? 'Matron' : 'Lecturer')); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-data">No assessment results yet. Your matron will conduct an initial assessment, followed by a final assessment from your lecturer.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="profileSection" class="content-section <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">
            <div class="card">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-box">
                        <label>Full Name</label>
                        <p><?php echo htmlspecialchars($student_name); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Student Number</label>
                        <p><?php echo htmlspecialchars($student_number); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Email Address</label>
                        <p><?php echo htmlspecialchars($student_email); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Cohort</label>
                        <p><?php echo htmlspecialchars($student_cohort); ?></p>
                    </div>
                    <div class="info-box">
                        <label>Mode of Entry</label>
                        <p><?php echo htmlspecialchars($student_mode); ?></p>
                    </div>
                </div>
                
                <h3 style="margin-top: 20px;">Edit Profile</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($student_name); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student_email); ?>" required>
                    </div>
                    <button type="submit" name="update_profile" class="btn-secondary">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const bell = document.getElementById('notificationBell');
            const dropdown = document.getElementById('notificationDropdown');
            
            if (!bell.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        // Prevent dropdown from closing when clicking inside
        document.getElementById('notificationDropdown').addEventListener('click', function(event) {
            event.stopPropagation();
        });
    </script>
    <script src="js/page-loader.js"></script>
</body>
</html>
