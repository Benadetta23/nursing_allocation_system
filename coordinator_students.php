<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
$coordinator = new Coordinator();

$message = '';
$error = '';

// Handle student operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_student'])) {
        if ($coordinator->addStudent($_POST['student_number'], $_POST['name'], $_POST['email'], $_POST['cohort'], $_POST['mode_of_entry'], 1)) {
            $message = "✅ Student added successfully! Password is 'pass'.";
        } else {
            $error = "❌ Failed to add student.";
        }
    }
    
    if (isset($_POST['delete_student'])) {
        if ($coordinator->deleteStudent($_POST['student_id'])) {
            $message = "✅ Student deleted successfully!";
        } else {
            $error = "❌ Failed to delete student.";
        }
    }
    
    // Handle lecturer operations
    if (isset($_POST['add_lecturer'])) {
        if ($coordinator->addLecturer($_POST['lecturer_id'], $_POST['lecturer_name'], $_POST['lecturer_email'])) {
            $message = "✅ Lecturer added successfully! Password is 'pass'.";
        } else {
            $error = "❌ Failed to add lecturer (ID or email may already exist).";
        }
    }
    
    if (isset($_POST['delete_lecturer'])) {
        if ($coordinator->deleteLecturer($_POST['lecturer_id'])) {
            $message = "✅ Lecturer deleted successfully!";
        } else {
            $error = "❌ Failed to delete lecturer.";
        }
    }
    
    // Handle matron operations
    if (isset($_POST['add_matron'])) {
        $site_id = !empty($_POST['matron_site_id']) ? $_POST['matron_site_id'] : null;
        if ($coordinator->addMatron($_POST['matron_id'], $_POST['matron_name'], $_POST['matron_email'], $site_id)) {
            $message = "✅ Matron added successfully! Password is 'pass'.";
        } else {
            $error = "❌ Failed to add matron (ID or email may already exist).";
        }
    }
    
    if (isset($_POST['delete_matron'])) {
        if ($coordinator->deleteMatron($_POST['matron_id'])) {
            $message = "✅ Matron deleted successfully!";
        } else {
            $error = "❌ Failed to delete matron.";
        }
    }
}

$sites = $coordinator->getSites();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Coordinator Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .header h1 { color: #c3a343; font-size: 1.3rem; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-info span { color: white; }
        .role-badge { background: #c3a343; color: #4a2f1a; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .btn-logout { background: rgba(255,255,255,0.2); color: white; padding: 6px 16px; border-radius: 30px; text-decoration: none; font-size: 0.8rem; transition: 0.3s; }
        .btn-logout:hover { background: #dc3545; }
        
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
        .nav-tab:hover { color: #c3a343; }
        .nav-tab.active { color: #c3a343; border-bottom: 3px solid #c3a343; background: #4a2f1a; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        
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
        
        .tab-nav {
            display: flex;
            gap: 0;
            margin-bottom: 25px;
            border-bottom: 2px solid #e0e0e0;
        }
        .tab-btn {
            padding: 12px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            transition: 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }
        .tab-btn:hover { color: #4a2f1a; }
        .tab-btn.active { color: #c3a343; border-bottom-color: #c3a343; font-weight: 600; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #333; font-size: 0.85rem; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #c3a343; }
        
        .btn-primary {
            background: #4a2f1a;
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn-primary:hover { background: #654321; }
        .btn-sm {
            background: #dc3545;
            color: white;
            padding: 5px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: 0.3s;
        }
        .btn-sm:hover { background: #c82333; }
        
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e0e0e0; font-size: 0.9rem; }
        .data-table th { background: #f8f9fa; color: #4a2f1a; font-weight: 600; }
        .data-table tr:hover { background: #f5f0eb; }
        
        .message { padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
        .success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .badge-site {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            background: #fff3cd;
            color: #856404;
        }
        .badge-unassigned {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            background: #e9ecef;
            color: #6c757d;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .user-count {
            font-size: 0.8rem;
            color: #666;
            background: #f8f9fa;
            padding: 4px 12px;
            border-radius: 12px;
        }
        
        .toggle-form-btn {
            background: #c3a343;
            color: #4a2f1a;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: 0.3s;
        }
        .toggle-form-btn:hover { background: #d4b35a; }
        
        .add-form { display: none; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 3px solid #c3a343; }
        .add-form.show { display: block; }
        
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .data-table { font-size: 0.8rem; }
            .data-table th, .data-table td { padding: 8px; }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <div class="header">
        <h1>🏥 Daeyang University - User Management</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="coordinator_Dashboard.php?tab=sites" class="nav-tab">Clinical Sites</a>
        <a href="coordinator_Dashboard.php?tab=students" class="nav-tab">Manage Students</a>
        <a href="upload_students.php" class="nav-tab">Bulk Upload</a>
        <a href="auto_allocate.php" class="nav-tab">Auto Allocate</a>
        <a href="coordinator_Dashboard.php?tab=assign" class="nav-tab">Assign Staff</a>
        <a href="coordinator_reports.php" class="nav-tab">Reports</a>
        <a href="coordinator_students.php" class="nav-tab active">All Users</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn active" onclick="openTab(event, 'students-tab')">🎓 Students</button>
            <button class="tab-btn" onclick="openTab(event, 'lecturers-tab')">👨‍🏫 Lecturers</button>
            <button class="tab-btn" onclick="openTab(event, 'matrons-tab')">👩‍⚕️ Matrons</button>
        </div>
        
        <!-- ============ STUDENTS TAB ============ -->
        <div id="students-tab" class="tab-content active">
            <div class="card">
                <div class="section-header">
                    <h2>🎓 Registered Students</h2>
                    <button class="toggle-form-btn" onclick="toggleForm('student-form')">➕ Add Student</button>
                </div>
                
                <div id="student-form" class="add-form">
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Student Number</label>
                                <input type="text" name="student_number" placeholder="e.g., STU001" required>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="name" placeholder="Full name" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" placeholder="Email address" required>
                            </div>
                            <div class="form-group">
                                <label>Cohort</label>
                                <input type="text" name="cohort" placeholder="e.g., 2024">
                            </div>
                            <div class="form-group">
                                <label>Mode of Entry</label>
                                <select name="mode_of_entry" required>
                                    <option value="">Select</option>
                                    <option value="Generic">Generic</option>
                                    <option value="Weekend">Weekend</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="add_student" class="btn-primary">➕ Add Student</button>
                    </form>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Number</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Cohort</th>
                                <th>Mode of Entry</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $students = $coordinator->getStudents(); ?>
                            <?php if (count($students) > 0): ?>
                                <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td><?php echo htmlspecialchars($student['cohort']); ?></td>
                                    <td><?php echo htmlspecialchars($student['mode_of_entry'] ?? 'Generic'); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete student <?php echo htmlspecialchars($student['name']); ?>?')">
                                            <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                            <button type="submit" name="delete_student" class="btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center; color: #999;">No students registered</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 10px; text-align: right;">
                    <span class="user-count">Total: <?php echo count($students); ?> students</span>
                </div>
            </div>
        </div>
        
        <!-- ============ LECTURERS TAB ============ -->
        <div id="lecturers-tab" class="tab-content">
            <div class="card">
                <div class="section-header">
                    <h2>👨‍🏫 Registered Lecturers</h2>
                    <button class="toggle-form-btn" onclick="toggleForm('lecturer-form')">➕ Add Lecturer</button>
                </div>
                
                <div id="lecturer-form" class="add-form">
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Lecturer ID</label>
                                <input type="text" name="lecturer_id" placeholder="e.g., LEC001" required>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="lecturer_name" placeholder="Full name" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="lecturer_email" placeholder="Email address" required>
                            </div>
                        </div>
                        <button type="submit" name="add_lecturer" class="btn-primary">➕ Add Lecturer</button>
                        <p style="margin-top: 8px; font-size: 0.8rem; color: #666;">Default password: <strong>pass</strong></p>
                    </form>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Lecturer ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $lecturers = $coordinator->getLecturers(); ?>
                            <?php if (count($lecturers) > 0): ?>
                                <?php foreach ($lecturers as $lecturer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($lecturer['lecturer_id']); ?></td>
                                    <td><?php echo htmlspecialchars($lecturer['name']); ?></td>
                                    <td><?php echo htmlspecialchars($lecturer['email']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete lecturer <?php echo htmlspecialchars($lecturer['name']); ?>?')">
                                            <input type="hidden" name="lecturer_id" value="<?php echo htmlspecialchars($lecturer['lecturer_id']); ?>">
                                            <button type="submit" name="delete_lecturer" class="btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; color: #999;">No lecturers registered</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 10px; text-align: right;">
                    <span class="user-count">Total: <?php echo count($lecturers); ?> lecturers</span>
                </div>
            </div>
        </div>
        
        <!-- ============ MATRONS TAB ============ -->
        <div id="matrons-tab" class="tab-content">
            <div class="card">
                <div class="section-header">
                    <h2>👩‍⚕️ Registered Matrons</h2>
                    <button class="toggle-form-btn" onclick="toggleForm('matron-form')">➕ Add Matron</button>
                </div>
                
                <div id="matron-form" class="add-form">
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Matron ID</label>
                                <input type="text" name="matron_id" placeholder="e.g., MAT001" required>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="matron_name" placeholder="Full name" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="matron_email" placeholder="Email address" required>
                            </div>
                            <div class="form-group">
                                <label>Assigned Clinical Site</label>
                                <select name="matron_site_id">
                                    <option value="">-- Not Assigned --</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?php echo $site['site_id']; ?>"><?php echo htmlspecialchars($site['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="add_matron" class="btn-primary">➕ Add Matron</button>
                        <p style="margin-top: 8px; font-size: 0.8rem; color: #666;">Default password: <strong>pass</strong></p>
                    </form>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Matron ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Assigned Site</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $matrons = $coordinator->getMatrons(); ?>
                            <?php if (count($matrons) > 0): ?>
                                <?php foreach ($matrons as $matron): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($matron['matron_id']); ?></td>
                                    <td><?php echo htmlspecialchars($matron['name']); ?></td>
                                    <td><?php echo htmlspecialchars($matron['email']); ?></td>
                                    <td>
                                        <?php if ($matron['site_id']): ?>
                                            <span class="badge-site"><?php echo htmlspecialchars($matron['site_name']); ?></span>
                                        <?php else: ?>
                                            <span class="badge-unassigned">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete matron <?php echo htmlspecialchars($matron['name']); ?>?')">
                                            <input type="hidden" name="matron_id" value="<?php echo htmlspecialchars($matron['matron_id']); ?>">
                                            <button type="submit" name="delete_matron" class="btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; color: #999;">No matrons registered</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 10px; text-align: right;">
                    <span class="user-count">Total: <?php echo count($matrons); ?> matrons</span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        function openTab(event, tabId) {
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            const tabBtns = document.getElementsByClassName('tab-btn');
            for (let i = 0; i < tabBtns.length; i++) {
                tabBtns[i].classList.remove('active');
            }
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }
        
        // Toggle add forms
        function toggleForm(formId) {
            const form = document.getElementById(formId);
            form.classList.toggle('show');
        }
        
        // Check URL hash for initial tab
        window.addEventListener('load', function() {
            const hash = window.location.hash.replace('#', '');
            if (hash) {
                const tabBtn = document.querySelector(`.tab-btn[onclick*="${hash}"]`);
                if (tabBtn) tabBtn.click();
            }
        });
    </script>
    </body>
</html>