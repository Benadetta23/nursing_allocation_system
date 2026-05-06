<?php
session_start();

// Check if user is logged in and is lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Lecturer.php';

// Get lecturer ID from database based on registration number
require_once 'classes/Database.php';
$db = new Database();
$conn = $db->getConnection();

// Find lecturer by registration number (using email for demo)
$email = $_SESSION['email'] ?? 'lecturer@daeyang.edu';
$query = "SELECT lecturer_id FROM lecturer WHERE email = :email";
$stmt = $conn->prepare($query);
$stmt->bindParam(':email', $email);
$stmt->execute();
$lecturerData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturerData) {
    // For demo, use lecturer_id = 1
    $lecturer_id = 1;
} else {
    $lecturer_id = $lecturerData['lecturer_id'];
}

$lecturer = new Lecturer($lecturer_id);
$sites = $lecturer->getClinicalSites();

$message = '';
$error = '';

// Handle assessment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_assessment'])) {
    $student_id = $_POST['student_id'];
    $site_id = $_POST['site_id'];
    $punctuality = $_POST['punctuality'];
    $dressing = $_POST['dressing'];
    $communication = $_POST['communication'];
    $comments = $_POST['comments'];
    
    if ($lecturer->saveAssessment($student_id, $site_id, $punctuality, $dressing, $communication, $comments)) {
        $message = "✅ Assessment saved successfully!";
    } else {
        $error = "❌ Failed to save assessment.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lecturer Dashboard - Nursing Allocation System</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modal.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1>📖 Lecturer Dashboard</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <span class="role-badge">Lecturer</span>
                <a href="actions/logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="success-msg"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="dashboard-content">
            <!-- Assessment Section -->
            <div class="card full-width">
                <h3>📋 Student Assessment</h3>
                
                <div class="form-group">
                    <label for="siteSelect">Select Clinical Site</label>
                    <select id="siteSelect" class="form-control">
                        <option value="">-- Select a Clinical Site --</option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['site_id']; ?>"><?php echo htmlspecialchars($site['name']); ?> (<?php echo htmlspecialchars($site['location']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="studentsContainer" style="margin-top: 20px;">
                    <p class="no-data">Please select a clinical site to view students.</p>
                </div>
            </div>
            
            <!-- Assessment History Section -->
            <div class="card full-width">
                <h3>📜 My Assessment History</h3>
                <div id="historyContainer">
                    <?php
                    $history = $lecturer->getAssessmentHistory();
                    if (count($history) > 0):
                    ?>
                        <table class="data-table">
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
                                        <td><?php echo date('M d, Y', strtotime($h['assessment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($h['student_name']); ?> (<?php echo $h['student_number']; ?>)</td>
                                        <td><?php echo htmlspecialchars($h['site_name']); ?></td>
                                        <td><?php echo $h['punctuality_score']; ?>/5</td>
                                        <td><?php echo $h['dressing_score']; ?>/5</td>
                                        <td><?php echo $h['communication_score']; ?>/5</td>
                                        <td><?php echo htmlspecialchars(substr($h['comments'], 0, 50)); ?>...</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No assessments recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assessment Modal - Improved Version -->
    <div id="assessmentModal" class="modal">
        <div class="modal-container">
            <div class="modal-header">
                <h3>📝 Student Assessment</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" id="assessmentForm" class="modal-form">
                <input type="hidden" id="assessStudentId" name="student_id">
                <input type="hidden" id="assessSiteId" name="site_id">
                
                <div class="modal-body">
                    <!-- Student Information Card -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <span>👨‍🎓</span>
                            <h4>Student Information</h4>
                        </div>
                        <div class="info-card-body">
                            <div class="info-row">
                                <span class="info-label">Full Name:</span>
                                <span class="info-value" id="studentName">-</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Student ID:</span>
                                <span class="info-value" id="studentNumber">-</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Role:</span>
                                <span class="info-value" id="studentRole">-</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Cohort:</span>
                                <span class="info-value" id="studentCohort">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Assessment Scores Section -->
                    <div class="scores-card">
                        <div class="scores-card-header">
                            <span>⭐</span>
                            <h4>Assessment Scores</h4>
                        </div>
                        <div class="scores-card-body">
                            <div class="scores-grid">
                                <div class="score-field">
                                    <label>Punctuality</label>
                                    <div class="score-input-wrapper">
                                        <input type="number" id="punctuality" name="punctuality" min="1" max="5" class="score-input" required>
                                        <span class="score-hint">/5</span>
                                    </div>
                                    <div class="score-description">
                                        <span class="poor">1-2: Poor</span>
                                        <span class="good">3: Good</span>
                                        <span class="excellent">4-5: Excellent</span>
                                    </div>
                                </div>
                                <div class="score-field">
                                    <label>Dressing & Uniform</label>
                                    <div class="score-input-wrapper">
                                        <input type="number" id="dressing" name="dressing" min="1" max="5" class="score-input" required>
                                        <span class="score-hint">/5</span>
                                    </div>
                                    <div class="score-description">
                                        <span class="poor">1-2: Poor</span>
                                        <span class="good">3: Good</span>
                                        <span class="excellent">4-5: Excellent</span>
                                    </div>
                                </div>
                                <div class="score-field">
                                    <label>Communication</label>
                                    <div class="score-input-wrapper">
                                        <input type="number" id="communication" name="communication" min="1" max="5" class="score-input" required>
                                        <span class="score-hint">/5</span>
                                    </div>
                                    <div class="score-description">
                                        <span class="poor">1-2: Poor</span>
                                        <span class="good">3: Good</span>
                                        <span class="excellent">4-5: Excellent</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comments Section -->
                    <div class="comments-card">
                        <div class="comments-card-header">
                            <span>💬</span>
                            <h4>Comments & Observations</h4>
                        </div>
                        <div class="comments-card-body">
                            <textarea id="comments" name="comments" rows="4" placeholder="Write your observations about the student's clinical performance..."></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Footer Buttons -->
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal()">
                        <span>✖</span> Cancel
                    </button>
                    <button type="submit" name="submit_assessment" class="btn-save">
                        <span>💾</span> Save Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Get students by site
        document.getElementById('siteSelect').addEventListener('change', function() {
            const siteId = this.value;
            if (!siteId) {
                document.getElementById('studentsContainer').innerHTML = '<p class="no-data">Please select a clinical site to view students.</p>';
                return;
            }
            
            // Fetch students via AJAX
            fetch('ajax/get_students_by_site.php?site_id=' + siteId)
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        document.getElementById('studentsContainer').innerHTML = '<p class="no-data">No students allocated to this site.</p>';
                        return;
                    }
                    
                    let html = '<div class="students-grid">';
                    data.forEach(student => {
                        const assessedBadge = student.already_assessed > 0 ? 
                            '<span class="badge badge-success">✅ Assessed</span>' : 
                            '<span class="badge badge-warning">⚠️ Not Assessed</span>';
                        
                        html += `
                            <div class="student-card">
                                <h4>${student.name}</h4>
                                <p>ID: ${student.student_number}</p>
                                <p>Cohort: ${student.cohort}</p>
                                <p>Role: ${student.role}</p>
                                ${assessedBadge}
                                <button class="btn-primary assess-btn" 
                                    data-student='${JSON.stringify(student)}' 
                                    data-siteid="${siteId}">
                                    ${student.already_assessed > 0 ? 'Edit Assessment' : 'Assess Student'}
                                </button>
                            </div>
                        `;
                    });
                    html += '</div>';
                    document.getElementById('studentsContainer').innerHTML = html;
                    
                    // Add event listeners to assess buttons
                    document.querySelectorAll('.assess-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const student = JSON.parse(this.dataset.student);
                            const siteId = this.dataset.siteid;
                            openAssessmentModal(student, siteId);
                        });
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('studentsContainer').innerHTML = '<p class="error-msg">Error loading students.</p>';
                });
        });
        
        function openAssessmentModal(student, siteId) {
            document.getElementById('assessStudentId').value = student.student_id;
            document.getElementById('assessSiteId').value = siteId;
            
            // Fill student info
            document.getElementById('studentName').textContent = student.name;
            document.getElementById('studentNumber').textContent = student.student_number;
            document.getElementById('studentRole').textContent = student.role || 'General Nursing';
            document.getElementById('studentCohort').textContent = student.cohort || '2024';
            
            // Clear form
            document.getElementById('punctuality').value = '';
            document.getElementById('dressing').value = '';
            document.getElementById('communication').value = '';
            document.getElementById('comments').value = '';
            
            document.getElementById('assessmentModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('assessmentModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('assessmentModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>