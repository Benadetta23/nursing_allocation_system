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
$lecturer_email = $lecturerData ? $lecturerData['email'] : $_SESSION['email'];

$lecturer = new Lecturer($lecturer_id);
$sites = $lecturer->getClinicalSites();

$message = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'assessment';

// Handle assessment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['submit_assessment'])) {
        if ($lecturer->saveAssessment($_POST['student_id'], $_POST['site_id'], $_POST['punctuality'], $_POST['dressing'], $_POST['communication'], $_POST['comments'])) {
            $message = "✅ Assessment saved successfully!";
        } else {
            $error = "❌ Failed to save assessment.";
        }
    }
    
    // Update profile
    if (isset($_POST['update_profile'])) {
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
            $message = "✅ Profile updated successfully!";
        } else {
            $error = "❌ Failed to update profile.";
        }
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
        
        /* Card */
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
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
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.9rem;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #c3a343;
        }
        
        /* Students Grid */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .student-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            border-left: 4px solid #c3a343;
            transition: all 0.2s;
        }
        
        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .student-card h4 {
            color: #1a2a6c;
            margin-bottom: 10px;
        }
        
        .student-card p {
            color: #555;
            font-size: 0.85rem;
            margin: 5px 0;
        }
        
        /* Badges */
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
            margin: 10px 0;
        }
        
        .btn-primary {
            background: #1a2a6c;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            transition: 0.3s;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: #c3a343;
            color: #1a2a6c;
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
        
        /* Modal */
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
            border-radius: 20px;
            width: 90%;
            max-width: 550px;
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
            color: #1a2a6c;
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
            border-left: 3px solid #c3a343;
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
            color: #1a2a6c;
        }
        
        .score-item input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            font-size: 1rem;
        }
        
        .modal-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-save {
            flex: 1;
            background: #1a2a6c;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-cancel {
            flex: 1;
            background: #f0f0f0;
            color: #666;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        
        /* History Table - Fixed */
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
            background: #f8f9fa;
            color: #1a2a6c;
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
            .nav-tabs { flex-wrap: wrap; }
            .nav-tab { padding: 10px 15px; font-size: 0.9rem; }
            .students-grid { grid-template-columns: 1fr; }
            .score-row { flex-direction: column; }
            .modal-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📖 Daeyang University - Lecturer Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Lecturer</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="?tab=assessment" class="nav-tab <?php echo $active_tab == 'assessment' ? 'active' : ''; ?>">📋 Student Assessment</a>
        <a href="?tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'active' : ''; ?>">📜 Assessment History</a>
        <a href="?tab=profile" class="nav-tab <?php echo $active_tab == 'profile' ? 'active' : ''; ?>">👤 My Profile</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Student Assessment Tab -->
        <div id="assessmentSection" class="content-section <?php echo $active_tab == 'assessment' ? 'active' : ''; ?>">
            <div class="card">
                <h2>📋 Student Assessment</h2>
                
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
        </div>
        
        <!-- Assessment History Tab -->
        <div id="historySection" class="content-section <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
            <div class="card">
                <h2>📜 My Assessment History</h2>
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
                </div>
                <?php else: ?>
                <p class="no-data">No assessments recorded yet.</p>
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
                        <p><?php echo htmlspecialchars($lecturer_name); ?></p>
                    </div>
                    <div class="info-box">
                        <label>📧 Email Address</label>
                        <p><?php echo htmlspecialchars($lecturer_email); ?></p>
                    </div>
                    <div class="info-box">
                        <label>👔 Role</label>
                        <p>Lecturer - Nursing Department</p>
                    </div>
                </div>
                
                <h3 style="margin-top: 20px;">✏️ Edit Profile</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($lecturer_name); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($lecturer_email); ?>" required>
                    </div>
                    <button type="submit" name="update_profile" class="btn-secondary">💾 Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assessment Modal -->
    <div id="assessmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📝 Student Assessment</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" id="assessmentForm">
                <input type="hidden" id="assessStudentId" name="student_id">
                <input type="hidden" id="assessSiteId" name="site_id">
                
                <div class="student-info-card">
                    <p><strong>👨‍🎓 Student:</strong> <span id="studentName"></span></p>
                    <p><strong>🆔 Student ID:</strong> <span id="studentNumber"></span></p>
                    <p><strong>💼 Role:</strong> <span id="studentRole"></span></p>
                    <p><strong>📚 Cohort:</strong> <span id="studentCohort"></span></p>
                </div>
                
                <div class="score-row">
                    <div class="score-item">
                        <label>🎯 Punctuality (1-5)</label>
                        <input type="number" id="punctuality" name="punctuality" min="1" max="5" required>
                    </div>
                    <div class="score-item">
                        <label>👔 Dressing (1-5)</label>
                        <input type="number" id="dressing" name="dressing" min="1" max="5" required>
                    </div>
                    <div class="score-item">
                        <label>💬 Communication (1-5)</label>
                        <input type="number" id="communication" name="communication" min="1" max="5" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>📝 Comments / Observations</label>
                    <textarea id="comments" name="comments" rows="4" placeholder="Write your observations about the student's clinical performance..."></textarea>
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" name="submit_assessment" class="btn-save">💾 Save Assessment</button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
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
                            '<span class="badge-success">✅ Assessed</span>' : 
                            '<span class="badge-warning">⚠️ Not Assessed</span>';
                        
                        html += `
                            <div class="student-card">
                                <h4>${student.name}</h4>
                                <p><strong>ID:</strong> ${student.student_number}</p>
                                <p><strong>Cohort:</strong> ${student.cohort}</p>
                                <p><strong>Role:</strong> ${student.role}</p>
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
                    
                    document.querySelectorAll('.assess-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const student = JSON.parse(this.dataset.student);
                            const siteId = this.dataset.siteid;
                            openAssessmentModal(student, siteId);
                        });
                    });
                })
                .catch(error => {
                    document.getElementById('studentsContainer').innerHTML = '<p class="error-msg">Error loading students. Please try again.</p>';
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
        
        window.onclick = function(event) {
            const modal = document.getElementById('assessmentModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>