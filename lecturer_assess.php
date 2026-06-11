<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Lecturer.php';
require_once 'classes/Database.php';

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

$lecturer = new Lecturer($lecturer_id);

$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : 0;
$site_id = isset($_GET['site_id']) ? $_GET['site_id'] : 0;

if (!$student_id || !$site_id) {
    header("Location: Lecturer_Dashboard.php?error=Invalid request");
    exit();
}

// Get student details
$studentQuery = "SELECT s.*, a.role, a.start_date, a.end_date, cs.name as site_name, cs.location 
                 FROM student s
                 JOIN allocation a ON s.student_id = a.student_id
                 JOIN clinical_site cs ON a.site_id = cs.site_id
                 WHERE s.student_id = :student_id AND a.site_id = :site_id AND a.status = 'active'
                 LIMIT 1";
$studentStmt = $conn->prepare($studentQuery);
$studentStmt->bindParam(':student_id', $student_id);
$studentStmt->bindParam(':site_id', $site_id);
$studentStmt->execute();
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: Lecturer_Dashboard.php?error=Student not found");
    exit();
}

// Check if matron has finalized assessment (not required - lecturer can assess regardless)
$matronStatus = $lecturer->isMatronFinalized($student_id, $site_id);

if (!$matronStatus['finalized']) {
    // Matron hasn't finalized, use 0 aggregate and allow lecturer to assess anyway
    $matronStatus['aggregate'] = 0;
}
// Get existing assessment if any
$existingAssessment = $lecturer->getExistingAssessment($student_id, $site_id);
$is_editing = ($existingAssessment && !is_null($existingAssessment['lecturer_final_submitted'] ?? null));

// Get daily marks for display
$dailyMarks = $lecturer->getStudentDailyMarksList($student_id, $site_id);
$dailySummary = $lecturer->getStudentDailySummary($student_id, $site_id);

// Handle assessment submission
$message = '';
$error = '';
$assessment_result = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_assessment'])) {
    $scores = [
        'punctuality' => $_POST['punctuality'],
        'dressing' => $_POST['dressing'],
        'communication' => $_POST['communication']
    ];
    $comments = $_POST['comments'];
    
    $result = $lecturer->saveAssessment(
        $student_id,
        $site_id,
        $scores['punctuality'],
        $scores['dressing'],
        $scores['communication'],
        $comments
    );
    
    if ($result['success']) {
        $assessment_result = $result;
        $message = "Assessment submitted successfully! Final Grade: " . $result['final_grade'] . "% (" . $result['status'] . ")";
        // Refresh existing assessment
        $existingAssessment = $lecturer->getExistingAssessment($student_id, $site_id);
        $is_editing = true;
    } else {
        $error = $result['error'] ?? "Failed to save assessment.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lecturer Assessment - <?php echo htmlspecialchars($student['name']); ?></title>
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
        
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .breadcrumb {
            margin-bottom: 20px;
        }
        
        .breadcrumb a {
            color: #c3a343;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
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
            font-size: 1.1rem;
        }
        
        .student-info {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .info-item {
            flex: 1;
            min-width: 200px;
        }
        
        .info-item label {
            font-size: 0.7rem;
            color: #666;
            display: block;
            margin-bottom: 5px;
        }
        
        .info-item p {
            font-weight: 600;
            color: #4a2f1a;
            font-size: 1rem;
        }
        
        .matron-aggregate-box {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .matron-score {
            font-size: 2rem;
            font-weight: 700;
            color: #28a745;
        }
        
        .matron-label {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        
        .daily-marks-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .daily-marks-table th,
        .daily-marks-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .daily-marks-table th {
            background: #4a2f1a;
            color: white;
            font-weight: 600;
        }
        
        .daily-marks-table tr:hover {
            background: #f5f5f5;
        }
        
        .score-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .score-item {
            flex: 1;
            min-width: 120px;
        }
        
        .score-item label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a2f1a;
        }
        
        .score-item input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            text-align: center;
        }
        
        .score-item input:focus {
            outline: none;
            border-color: #c3a343;
        }
        
        .score-item input:disabled {
            background: #f0f0f0;
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
        
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
        }
        
        .form-group textarea:focus {
            outline: none;
            border-color: #c3a343;
        }
        
        .grade-preview {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        
        .grade-preview span {
            font-weight: bold;
            color: #1565c0;
            font-size: 1.2rem;
        }
        
        .assessment-result {
            background: #d4edda;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .assessment-result.pass {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .assessment-result.fail {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .btn-save {
            background: #4a2f1a;
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: 0.3s;
        }
        
        .btn-save:hover {
            background: #654321;
        }
        
        .btn-save:disabled {
            background: #999;
            cursor: not-allowed;
        }
        
        .btn-back {
            background: #c3a343;
            color: #4a2f1a;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: 0.3s;
        }
        
        .btn-back:hover {
            background: #b8922e;
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
        
        .summary-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-badge {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            text-align: center;
            flex: 1;
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #4a2f1a;
        }
        
        .stat-label {
            font-size: 0.7rem;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .student-info { flex-direction: column; }
            .score-row { flex-direction: column; }
            .daily-marks-table { font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Lecturer Final Assessment</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($lecturer_name); ?></span>
            <span class="role-badge">Lecturer</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="Lecturer_Dashboard.php">Dashboard</a> / 
            <a href="Lecturer_Dashboard.php?tab=assessment&site_id=<?php echo $site_id; ?>">Final Assessment</a> / 
            <span><?php echo htmlspecialchars($student['name']); ?></span>
        </div>
        
        <?php if ($message): ?>
            <div class="success-msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($assessment_result): ?>
            <div class="assessment-result <?php echo strtolower($assessment_result['status']); ?>">
                <strong>Assessment Complete!</strong><br>
                Assessment Date: <strong><?php echo $assessment_result['assessment_date']; ?></strong><br>
                Matron Score: <?php echo $assessment_result['matron_score']; ?>% (50%)<br>
                Lecturer Score: <?php echo $assessment_result['lecturer_score']; ?>% (50%)<br>
                Final Grade: <strong><?php echo $assessment_result['final_grade']; ?>%</strong><br>
                Status: <strong><?php echo $assessment_result['status']; ?></strong>
            </div>
        <?php endif; ?>
        
        <?php if ($existingAssessment && !is_null($existingAssessment['lecturer_final_submitted'] ?? null)): ?>
            <div class="assessment-result pass" style="text-align: center; margin-bottom: 20px;">
                <strong>✅ Already Assessed</strong><br>
                Assessment Date: <?php echo $existingAssessment['assessment_date']; ?><br>
                Final Grade: <?php echo $existingAssessment['final_grade'] ?? '-'; ?>% 
                (<?php echo $existingAssessment['pass_fail_status'] ?? 'Pending'; ?>)<br><br>
                <a href="Lecturer_Dashboard.php?tab=assessment&site_id=<?php echo $site_id; ?>" class="btn-back">Back to Dashboard</a>
            </div>
        <?php endif; ?>
        
        <!-- Student Information Card -->
        <div class="card">
            <h2>Student Information</h2>
            <div class="student-info">
                <div class="info-item">
                    <label>Student Name</label>
                    <p><?php echo htmlspecialchars($student['name']); ?></p>
                </div>
                <div class="info-item">
                    <label>Student Number</label>
                    <p><?php echo htmlspecialchars($student['student_number']); ?></p>
                </div>
                <div class="info-item">
                    <label>Program & Cohort</label>
                    <p><?php echo htmlspecialchars($student['program'] ?? 'Nursing'); ?> | <?php echo htmlspecialchars($student['cohort']); ?></p>
                </div>
                <div class="info-item">
                    <label>Clinical Site</label>
                    <p><?php echo htmlspecialchars($student['site_name']); ?> (<?php echo htmlspecialchars($student['location']); ?>)</p>
                </div>
                <div class="info-item">
                    <label>Assigned Role</label>
                    <p><?php echo htmlspecialchars($student['role']); ?></p>
                </div>
                <div class="info-item">
                    <label>Placement Period</label>
                    <p><?php echo date('M d, Y', strtotime($student['start_date'])); ?> - <?php echo date('M d, Y', strtotime($student['end_date'])); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Matron Daily Marks Summary -->
        <div class="card">
            <h2>Matron's Daily Clinical Assessment</h2>
            
            <?php if (count($dailyMarks) > 0): ?>
                <div class="summary-stats">
                    <div class="stat-badge">
                        <div class="stat-value"><?php echo $dailySummary['total_days']; ?></div>
                        <div class="stat-label">Total Days Assessed</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-value"><?php echo $dailySummary['days_present']; ?></div>
                        <div class="stat-label">Days Present</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-value"><?php echo $dailySummary['days_absent']; ?></div>
                        <div class="stat-label">Days Absent</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-value"><?php echo $dailySummary['days_late']; ?></div>
                        <div class="stat-label">Days Late</div>
                    </div>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="daily-marks-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Attendance</th>
                                <th>Punctuality</th>
                                <th>Performance</th>
                                <th>Behavior</th>
                                <th>Daily Avg</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyMarks as $mark): 
                                $dailyAvg = round(($mark['punctuality'] + $mark['performance'] + $mark['behavior']) / 3, 1);
                            ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($mark['marking_date'])); ?></td>
                                <td class="attendance-<?php echo strtolower($mark['attendance']); ?>">
                                    <?php echo htmlspecialchars($mark['attendance']); ?>
                                </td>
                                <td><?php echo $mark['punctuality']; ?>/5</td>
                                <td><?php echo $mark['performance']; ?>/5</td>
                                <td><?php echo $mark['behavior']; ?>/5</td>
                                <td><strong><?php echo $dailyAvg; ?>/5</strong></td>
                                <td><?php echo htmlspecialchars(substr($mark['comments'] ?? '', 0, 40)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="matron-aggregate-box">
                    <div class="matron-score"><?php echo $matronStatus['aggregate']; ?>%</div>
                    <div class="matron-label">Matron's Final Aggregate Score (Weight: 50%)</div>
                </div>
            <?php else: ?>
                <div class="info-box" style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;border-radius:8px;">
                    <strong>Note:</strong> No daily marks recorded by the matron for this student yet. 
                    The final grade will be based solely on your lecturer assessment scores.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Lecturer Assessment Form -->
        <div class="card">
            <h2><?php echo $is_editing ? 'Update Final Assessment' : 'Final Assessment (Lecturer)'; ?></h2>
            
                <form method="POST">
                    <div class="score-row">
                        <div class="score-item">
                            <label>Punctuality & Time Management (1-5)</label>
                            <input type="number" name="punctuality" id="punctuality" 
                                   min="1" max="5" step="0.1" required
                                   value="<?php echo $existingAssessment['punctuality_score'] ?? ''; ?>"
                                   <?php echo $is_editing ? 'disabled' : ''; ?>>
                        </div>
                        <div class="score-item">
                            <label>Dressing & Professional Appearance (1-5)</label>
                            <input type="number" name="dressing" id="dressing" 
                                   min="1" max="5" step="0.1" required
                                   value="<?php echo $existingAssessment['dressing_score'] ?? ''; ?>"
                                   <?php echo $is_editing ? 'disabled' : ''; ?>>
                        </div>
                        <div class="score-item">
                            <label>Communication & Interpersonal Skills (1-5)</label>
                            <input type="number" name="communication" id="communication" 
                                   min="1" max="5" step="0.1" required
                                   value="<?php echo $existingAssessment['communication_score'] ?? ''; ?>"
                                   <?php echo $is_editing ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    
                    <div class="grade-preview" id="gradePreview">
                        Final Grade Calculation: 
                        (<?php echo $matronStatus['aggregate']; ?>% × 50%) + (Lecturer Score × 50%) = 
                        <span id="previewGrade">-</span>%
                    </div>
                    
                    <div class="form-group">
                        <label>Additional Comments</label>
                        <textarea name="comments" rows="4" 
                                  placeholder="Add your final observations and recommendations..."
                                  <?php echo $is_editing ? 'disabled' : ''; ?>><?php echo htmlspecialchars($existingAssessment['comments'] ?? ''); ?></textarea>
                    </div>
                    
                    <?php if (!$is_editing): ?>
                        <button type="submit" name="submit_assessment" class="btn-save" 
                                onclick="return confirm('Submit final assessment? This will calculate the final grade and cannot be modified without coordinator approval.')">
                            Submit Final Assessment
                        </button>
                    <?php else: ?>
                        <div class="assessment-result pass" style="text-align: center;">
                            <strong>Assessment Already Completed</strong><br>
                            Final Grade: <?php echo $existingAssessment['final_grade'] ?? '-'; ?>% 
                            (<?php echo $existingAssessment['pass_fail_status'] ?? 'Pending'; ?>)
                            <br><br>
                            <a href="Lecturer_Dashboard.php?tab=assessment&site_id=<?php echo $site_id; ?>" class="btn-back">Back to Dashboard</a>
                        </div>
                    <?php endif; ?>
                </form>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="Lecturer_Dashboard.php?tab=assessment&site_id=<?php echo $site_id; ?>" class="btn-back">Back to Dashboard</a>
        </div>
    </div>
    
    <script>
        // Grade preview calculation
        const matronAggregate = <?php echo $matronStatus['aggregate']; ?>;
        
        function updateGradePreview() {
            const punctuality = parseFloat(document.getElementById('punctuality').value) || 0;
            const dressing = parseFloat(document.getElementById('dressing').value) || 0;
            const communication = parseFloat(document.getElementById('communication').value) || 0;
            
            let lecturerScore = (punctuality + dressing + communication) / 3;
            // Convert from 1-5 scale to percentage
            lecturerScore = (lecturerScore / 5) * 100;
            
            const finalGrade = (matronAggregate * 0.5) + (lecturerScore * 0.5);
            document.getElementById('previewGrade').textContent = finalGrade.toFixed(1);
        }
        
        // Add event listeners
        const inputs = ['punctuality', 'dressing', 'communication'];
        inputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', updateGradePreview);
                input.addEventListener('change', updateGradePreview);
            }
        });
        
        // Initial preview
        updateGradePreview();
    </script>
</body>
</html>