<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Student.php';
require_once 'classes/Database.php';

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

$student = new Student($student_id);
$placement = $student->getPlacement();
$results = $student->getResults();
$info = $student->getStudentInfo();

$message = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'placement';

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
        $placement_status_text = 'Ends Today!';
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
        $message = "✅ Profile updated successfully!";
    } else {
        $error = "❌ Failed to update profile.";
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
            background: url('images/dashboard-bg.jpg') center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: -1;
        }
        
        /* Header */
        .header {
            background: #1a2a6c;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: white;
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
            color: #1a2a6c;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .btn-logout {
            background: rgba(255, 255, 255, 0.2);
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
        
        /* Navigation Tabs */
        .nav-tabs {
            background: white;
            display: flex;
            gap: 5px;
            padding: 0 20px;
            border-bottom: 1px solid #e0e0e0;
            flex-wrap: wrap;
        }
        
        .nav-tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            transition: 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .nav-tab:hover {
            color: #1a2a6c;
        }
        
        .nav-tab.active {
            color: #1a2a6c;
            border-bottom: 3px solid #c3a343;
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Welcome Card */
        .welcome-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border-left: 5px solid #c3a343;
        }
        
        .welcome-card h2 {
            color: #1a2a6c;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .welcome-card p {
            color: #666;
        }
        
        /* Content Sections */
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
        
        /* Cards */
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        
        .card:hover {
            transform: translateY(-3px);
        }
        
        .card h2 {
            color: #1a2a6c;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
            padding-left: 15px;
            font-size: 1.3rem;
        }
        
        .card h3 {
            color: #1a2a6c;
            margin-bottom: 15px;
        }
        
        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1a2a6c;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        /* Placement Info */
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
            color: #1a2a6c;
        }
        
        .info-value {
            flex: 1;
            color: #444;
        }
        
        /* Results List */
        .results-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .result-item {
            background: #f8f9fa;
            border-radius: 16px;
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
            color: #1a2a6c;
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
        
        .lecturer-name {
            font-size: 0.7rem;
            color: #999;
            display: block;
            text-align: right;
            margin-top: 8px;
        }
        
        /* Profile Section */
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
            color: #1a2a6c;
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
            color: #1a2a6c;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: #d4b353;
        }
        
        /* Badges */
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
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .error-msg {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .nav-tab { padding: 10px 15px; font-size: 0.9rem; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
            .result-scores { flex-direction: column; }
            .profile-info { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 Daeyang University - Student Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Student</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="?tab=placement" class="nav-tab <?php echo $active_tab == 'placement' ? 'active' : ''; ?>">📍 My Placement</a>
        <a href="?tab=results" class="nav-tab <?php echo $active_tab == 'results' ? 'active' : ''; ?>">⭐ Assessment Results</a>
        <a href="?tab=profile" class="nav-tab <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">👤 My Profile</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Placement Tab -->
        <div id="placementSection" class="content-section <?php echo $active_tab == 'placement' ? 'active' : ''; ?>">
            <div class="card">
                <h2>📍 My Clinical Placement</h2>
                <?php if ($placement): ?>
                    <div class="placement-info">
                        <div class="info-row">
                            <span class="info-label">🏥 Hospital/Site:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['site_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📍 Location:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['location']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">👤 Contact Person:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['contact_person']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">💼 Role:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['role']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📅 Start Date:</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($placement['start_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📅 End Date:</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($placement['end_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">⏰ Days Remaining:</span>
                            <span class="info-value">
                                <?php if ($placement['status'] == 'completed'): ?>
                                    <span class="badge-completed">Completed</span>
                                <?php elseif ($days_remaining < 0): ?>
                                    <span class="badge-overdue">Overdue by <?php echo abs($days_remaining); ?> days</span>
                                <?php elseif ($days_remaining == 0): ?>
                                    <span class="badge-warning">Ends Today!</span>
                                <?php elseif ($days_remaining <= 7): ?>
                                    <span class="badge-warning"><?php echo $days_remaining; ?> days remaining ⚠️</span>
                                <?php else: ?>
                                    <span class="badge-active"><?php echo $days_remaining; ?> days remaining ✅</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📊 Status:</span>
                            <span class="info-value">
                                <span class="<?php echo $placement_status_class; ?>"><?php echo $placement_status_text; ?></span>
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="no-data">No active placement assigned yet. Please contact your coordinator.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Results Tab -->
        <div id="resultsSection" class="content-section <?php echo $active_tab == 'results' ? 'active' : ''; ?>">
            <div class="card">
                <h2>⭐ My Assessment Results</h2>
                <?php if (count($results) > 0): ?>
                    <div class="results-list">
                        <?php foreach ($results as $result): ?>
                            <div class="result-item">
                                <div class="result-header">
                                    <span class="result-date">📅 <?php echo date('M d, Y', strtotime($result['assessment_date'])); ?></span>
                                    <span class="result-site">📍 <?php echo htmlspecialchars($result['site_name']); ?></span>
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
                                <?php if ($result['comments']): ?>
                                    <div class="result-comments">
                                        <span class="comments-label">💬 Lecturer's Comments:</span>
                                        <p>"<?php echo htmlspecialchars($result['comments']); ?>"</p>
                                        <span class="lecturer-name">— <?php echo htmlspecialchars($result['lecturer_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-data">No assessment results yet. Your lecturer will assess you during your placement.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Profile Tab -->
        <div id="profileSection" class="content-section <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">
            <div class="card">
                <h2>👤 My Profile</h2>
                <div class="profile-info">
                    <div class="info-box">
                        <label>📛 Full Name</label>
                        <p><?php echo htmlspecialchars($student_name); ?></p>
                    </div>
                    <div class="info-box">
                        <label>🆔 Student Number</label>
                        <p><?php echo htmlspecialchars($student_number); ?></p>
                    </div>
                    <div class="info-box">
                        <label>📧 Email Address</label>
                        <p><?php echo htmlspecialchars($student_email); ?></p>
                    </div>
                    <div class="info-box">
                        <label>📚 Cohort</label>
                        <p><?php echo htmlspecialchars($student_cohort); ?></p>
                    </div>
                    <div class="info-box">
                        <label>🎓 Mode of Entry</label>
                        <p><?php echo htmlspecialchars($student_mode); ?></p>
                    </div>
                </div>
                
                <h3 style="margin-top: 20px;">✏️ Edit Profile</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($student_name); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student_email); ?>" required>
                    </div>
                    <button type="submit" name="update_profile" class="btn-secondary">💾 Update Profile</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>