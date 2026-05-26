<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'matron') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Matron.php';
require_once 'classes/Database.php';

$matron_id = $_SESSION['user_id'];
$matron_name = $_SESSION['name'];

$matron = new Matron($matron_id);
$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';
$selected_site_id = $_GET['site_id'] ?? '';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$active_tab = $_GET['tab'] ?? 'daily_marking';

// Get sites for matron
$sites = $matron->getAllClinicalSites();

// Handle daily marking submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_daily_marking'])) {
    $student_id = $_POST['student_id'];
    $site_id = $_POST['site_id'];
    $attendance = $_POST['attendance'];
    $punctuality = $_POST['punctuality'];
    $performance = $_POST['performance'];
    $behavior = $_POST['behavior'];
    $comments = $_POST['comments'];
    
    if ($matron->saveOrUpdateDailyMark($student_id, $site_id, $attendance, $punctuality, $performance, $behavior, $comments)) {
        $message = "Daily mark saved successfully for student!";
    } else {
        $error = "Failed to save daily mark.";
    }
}

// Handle marking all students at once
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_all_students'])) {
    $site_id = $_POST['site_id'];
    $students_data = $_POST['students'];
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($students_data as $student_id => $data) {
        $result = $matron->saveOrUpdateDailyMark(
            $student_id,
            $site_id,
            $data['attendance'],
            $data['punctuality'],
            $data['performance'],
            $data['behavior'],
            $data['comments']
        );
        
        if ($result) {
            $success_count++;
        } else {
            $fail_count++;
        }
    }
    
    $message = "Daily marks saved! Success: $success_count, Failed: $fail_count";
}

// Get students for selected site
$students_at_site = [];
$todays_marks = [];
if ($selected_site_id) {
    $students_at_site = $matron->getStudentsAtSite($selected_site_id);
    $todays_marks = $matron->getTodaysDailyMarks($selected_site_id);
    
    // Create an associative array for easy lookup
    $marks_lookup = [];
    foreach ($todays_marks as $mark) {
        $marks_lookup[$mark['student_id']] = $mark;
    }
}

// Get marking history for selected site
$marking_history = [];
if ($selected_site_id && $active_tab == 'history') {
    $marking_history = $matron->getDailyMarkingHistory($selected_site_id, 30);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Matron Daily Marking - Daeyang University</title>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a2f1a;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .filter-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .btn-primary {
            background: #4a2f1a;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.3s;
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
        }
        
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .student-mark-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid #c3a343;
            transition: all 0.2s;
        }
        
        .student-mark-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .student-header {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .student-header h4 {
            color: #4a2f1a;
            font-size: 1rem;
        }
        
        .student-header p {
            color: #666;
            font-size: 0.8rem;
        }
        
        .mark-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-top: 5px;
        }
        
        .status-marked {
            background: #d4edda;
            color: #155724;
        }
        
        .status-unmarked {
            background: #fff3cd;
            color: #856404;
        }
        
        .score-row {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        
        .score-item {
            flex: 1;
            min-width: 80px;
        }
        
        .score-item label {
            font-size: 0.7rem;
            display: block;
            margin-bottom: 5px;
            color: #666;
        }
        
        .score-item select,
        .score-item input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        
        .attendance-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
        }
        
        .attendance-present {
            background: #d4edda;
            color: #155724;
        }
        
        .attendance-absent {
            background: #f8d7da;
            color: #721c24;
        }
        
        .attendance-late {
            background: #fff3cd;
            color: #856404;
        }
        
        .btn-save-student {
            background: #4a2f1a;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn-save-student:hover {
            background: #654321;
        }
        
        .btn-mark-all {
            background: #28a745;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-bottom: 20px;
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
        
        .summary-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            flex: 1;
            border-bottom: 2px solid #c3a343;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #4a2f1a;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .students-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .score-row { flex-direction: column; }
            .history-table { font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Daily Student Assessment</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($matron_name); ?></span>
            <span class="role-badge">Matron</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="matron_daily_marking.php?tab=daily_marking" class="nav-tab <?php echo $active_tab == 'daily_marking' ? 'active' : ''; ?>">Daily Marking</a>
        <a href="matron_daily_marking.php?tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'active' : ''; ?>">Marking History</a>
        <a href="matron_dashboard.php" class="nav-tab">Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Daily Marking Tab -->
        <?php if ($active_tab == 'daily_marking'): ?>
        
        <div class="card">
            <h2>Daily Student Assessment</h2>
            <p style="margin-bottom: 15px; color: #666;">Record daily performance for students at your clinical site.</p>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Select Clinical Site</label>
                    <select id="siteSelect" onchange="window.location.href='?tab=daily_marking&site_id='+this.value">
                        <option value="">-- Select a Clinical Site --</option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['site_id']; ?>" <?php echo $selected_site_id == $site['site_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?> (<?php echo htmlspecialchars($site['location']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Marking Date</label>
                    <input type="date" value="<?php echo $selected_date; ?>" disabled>
                </div>
            </div>
            
            <?php if ($selected_site_id && count($students_at_site) > 0): ?>
                
                <!-- Summary Stats -->
                <div class="summary-stats">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($students_at_site); ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todays_marks); ?></div>
                        <div class="stat-label">Marked Today</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($students_at_site) - count($todays_marks); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                
                <!-- Mark All Form -->
                <form method="POST">
                    <input type="hidden" name="site_id" value="<?php echo $selected_site_id; ?>">
                    <button type="submit" name="mark_all_students" class="btn-mark-all">Mark All Students with Same Scores</button>
                    
                    <div class="students-grid">
                        <?php foreach ($students_at_site as $student): 
                            $existing_mark = $marks_lookup[$student['student_id']] ?? null;
                        ?>
                        <div class="student-mark-card">
                            <div class="student-header">
                                <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                                <p><?php echo htmlspecialchars($student['student_number']); ?> | Cohort: <?php echo $student['cohort']; ?> | Role: <?php echo $student['role']; ?></p>
                                <?php if ($existing_mark): ?>
                                    <span class="mark-status status-marked">✓ Marked Today</span>
                                <?php else: ?>
                                    <span class="mark-status status-unmarked">⏳ Pending</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="score-row">
                                <div class="score-item">
                                    <label>Attendance</label>
                                    <select name="students[<?php echo $student['student_id']; ?>][attendance]" class="attendance-select">
                                        <option value="Present" <?php echo ($existing_mark['attendance'] ?? '') == 'Present' ? 'selected' : ''; ?>>Present</option>
                                        <option value="Absent" <?php echo ($existing_mark['attendance'] ?? '') == 'Absent' ? 'selected' : ''; ?>>Absent</option>
                                        <option value="Late" <?php echo ($existing_mark['attendance'] ?? '') == 'Late' ? 'selected' : ''; ?>>Late</option>
                                    </select>
                                </div>
                                <div class="score-item">
                                    <label>Punctuality (1-5)</label>
                                    <select name="students[<?php echo $student['student_id']; ?>][punctuality]">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($existing_mark['punctuality'] ?? 3) == $i ? 'selected' : ''; ?>><?php echo $i; ?> - <?php echo $i == 5 ? 'Excellent' : ($i == 4 ? 'Good' : ($i == 3 ? 'Average' : ($i == 2 ? 'Poor' : 'Very Poor'))); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="score-row">
                                <div class="score-item">
                                    <label>Performance (1-5)</label>
                                    <select name="students[<?php echo $student['student_id']; ?>][performance]">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($existing_mark['performance'] ?? 3) == $i ? 'selected' : ''; ?>><?php echo $i; ?> - <?php echo $i == 5 ? 'Excellent' : ($i == 4 ? 'Good' : ($i == 3 ? 'Average' : ($i == 2 ? 'Poor' : 'Very Poor'))); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="score-item">
                                    <label>Behavior (1-5)</label>
                                    <select name="students[<?php echo $student['student_id']; ?>][behavior]">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($existing_mark['behavior'] ?? 3) == $i ? 'selected' : ''; ?>><?php echo $i; ?> - <?php echo $i == 5 ? 'Excellent' : ($i == 4 ? 'Good' : ($i == 3 ? 'Average' : ($i == 2 ? 'Poor' : 'Very Poor'))); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="score-item" style="margin-bottom: 10px;">
                                <label>Comments</label>
                                <input type="text" name="students[<?php echo $student['student_id']; ?>][comments]" placeholder="Optional comments..." value="<?php echo htmlspecialchars($existing_mark['comments'] ?? ''); ?>">
                            </div>
                            
                            <button type="submit" name="save_daily_marking" value="1" class="btn-save-student">Save for <?php echo htmlspecialchars($student['name']); ?></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </form>
                
            <?php elseif ($selected_site_id): ?>
                <p class="no-data">No students allocated to this clinical site.</p>
            <?php else: ?>
                <p class="no-data">Please select a clinical site to start daily marking.</p>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
        <!-- Marking History Tab -->
        <?php if ($active_tab == 'history'): ?>
        
        <div class="card">
            <h2>Daily Marking History</h2>
            
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Select Clinical Site</label>
                    <select id="historySiteSelect" onchange="window.location.href='?tab=history&site_id='+this.value">
                        <option value="">-- Select a Clinical Site --</option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['site_id']; ?>" <?php echo $selected_site_id == $site['site_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?> (<?php echo htmlspecialchars($site['location']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <?php if ($selected_site_id && count($marking_history) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Attendance</th>
                                <th>Punctuality</th>
                                <th>Performance</th>
                                <th>Behavior</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($marking_history as $mark): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($mark['marking_date'])); ?>\(
                                <td><?php echo htmlspecialchars($mark['student_name']); ?>\(
                                <td><?php echo htmlspecialchars($mark['student_number']); ?>\(
                                <td>
                                    <span class="attendance-badge attendance-<?php echo strtolower($mark['attendance']); ?>">
                                        <?php echo $mark['attendance']; ?>
                                    </span>
                                </td>
                                <td><?php echo $mark['punctuality']; ?>/5</td>
                                <td><?php echo $mark['performance']; ?>/5</td>
                                <td><?php echo $mark['behavior']; ?>/5</td>
                                <td><?php echo htmlspecialchars(substr($mark['comments'] ?? '', 0, 50)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($selected_site_id): ?>
                <p class="no-data">No daily marking history found for this site.</p>
            <?php else: ?>
                <p class="no-data">Please select a clinical site to view marking history.</p>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>