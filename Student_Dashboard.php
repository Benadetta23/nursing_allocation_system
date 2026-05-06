<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Student.php';

// Get student ID from database based on registration number
require_once 'classes/Database.php';
$db = new Database();
$conn = $db->getConnection();

// Find student by registration number
$regNumber = $_SESSION['regNumber'];
$query = "SELECT student_id FROM student WHERE student_number = :regNumber";
$stmt = $conn->prepare($query);
$stmt->bindParam(':regNumber', $regNumber);
$stmt->execute();
$studentData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$studentData) {
    // For demo, use student_id = 1 if not found
    $student_id = 1;
} else {
    $student_id = $studentData['student_id'];
}

$student = new Student($student_id);
$placement = $student->getPlacement();
$results = $student->getResults();
$info = $student->getStudentInfo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Student Dashboard - Nursing Allocation System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1>🎓 Student Dashboard</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <span class="role-badge">Student</span>
                <a href="actions/logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="dashboard-content">
            <!-- Placement Card -->
            <div class="card">
                <h3>📍 My Clinical Placement</h3>
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
                            <span class="info-label">📞 Contact Phone:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['contact_phone']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">💼 Role:</span>
                            <span class="info-value"><?php echo htmlspecialchars($placement['role']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📅 Period:</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($placement['start_date'])); ?> - <?php echo date('M d, Y', strtotime($placement['end_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">📊 Status:</span>
                            <span class="info-value"><span class="badge badge-active"><?php echo ucfirst($placement['status']); ?></span></span>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="no-data">No active placement assigned yet. Please contact your coordinator.</p>
                <?php endif; ?>
            </div>
            
            <!-- Assessment Results Card -->
            <div class="card">
                <h3>⭐ My Assessment Results</h3>
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
                                        <span class="score-value <?php echo getScoreClass($result['punctuality_score']); ?>"><?php echo $result['punctuality_score']; ?>/5</span>
                                    </div>
                                    <div class="score">
                                        <span class="score-label">Dressing</span>
                                        <span class="score-value <?php echo getScoreClass($result['dressing_score']); ?>"><?php echo $result['dressing_score']; ?>/5</span>
                                    </div>
                                    <div class="score">
                                        <span class="score-label">Communication</span>
                                        <span class="score-value <?php echo getScoreClass($result['communication_score']); ?>"><?php echo $result['communication_score']; ?>/5</span>
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
        
        <!-- Student Information Card -->
        <div class="card full-width">
            <h3>👤 My Information</h3>
            <div class="info-grid">
                <div class="info-row">
                    <span class="info-label">Student Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($info['student_number'] ?? $_SESSION['regNumber']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Full Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?php echo htmlspecialchars($info['email'] ?? 'student@daeyang.edu'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Cohort:</span>
                    <span class="info-value"><?php echo htmlspecialchars($info['cohort'] ?? '2024'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Program:</span>
                    <span class="info-value"><?php echo htmlspecialchars($info['program'] ?? 'BSc Nursing'); ?></span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Helper function for score styling
function getScoreClass($score) {
    if ($score >= 4) return 'score-high';
    if ($score >= 3) return 'score-medium';
    return 'score-low';
}
?>