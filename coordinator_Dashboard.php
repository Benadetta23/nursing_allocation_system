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
$active_tab = $_GET['tab'] ?? 'sites';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_site'])) {
        if ($coordinator->addSite($_POST['name'], $_POST['location'], $_POST['contact_person'], $_POST['contact_phone'], $_POST['capacity'])) {
            $message = "Clinical site added successfully!";
        } else {
            $error = "Failed to add clinical site.";
        }
    }
    
    if (isset($_POST['add_student'])) {
        if ($coordinator->addStudent($_POST['student_number'], $_POST['name'], $_POST['email'], $_POST['cohort'], $_POST['mode_of_entry'], 1)) {
            $message = "Student added successfully! Password is 'pass'.";
        } else {
            $error = "Failed to add student.";
        }
    }
    
    if (isset($_POST['allocate'])) {
        if ($coordinator->createAllocation($_POST['student_id'], $_POST['site_id'], $_POST['start_date'], $_POST['end_date'], $_POST['role'])) {
            $message = "Allocation created successfully!";
        } else {
            $error = "Failed to create allocation.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Dashboard - Daeyang University</title>
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
        
        /* Header */
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
        
        /* Navigation Tabs */
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
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Messages */
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
        
        .card h3 {
            color: #4a2f1a;
            margin-bottom: 15px;
        }
        
        /* Form Elements */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4a2f1a;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
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
        
        /* Action Bar */
        .action-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-view {
            background: #4a2f1a;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: 0.3s;
        }
        
        .btn-view:hover {
            background: #654321;
        }
        
        .btn-archive {
            background: #6c757d;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: 0.3s;
        }
        
        .btn-archive:hover {
            background: #5a6268;
        }
        
        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .data-table th {
            background: #4a2f1a;
            color: white;
            font-weight: 600;
        }
        
        .data-table tr:hover {
            background: #f5f5f5;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
        }
        
        /* Hidden class for data tables */
        .data-table-container {
            display: none;
            margin-top: 20px;
        }
        
        .data-table-container.visible {
            display: block;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .form-grid { grid-template-columns: 1fr; }
            .action-bar { flex-direction: column; }
            .action-buttons { justify-content: center; }
            .data-table th,
            .data-table td { padding: 8px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Nursing Allocation System</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="?tab=sites" class="nav-tab <?php echo $active_tab == 'sites' ? 'active' : ''; ?>">Clinical Sites</a>
        <a href="?tab=students" class="nav-tab <?php echo $active_tab == 'students' ? 'active' : ''; ?>">Students</a>
        <a href="?tab=allocations" class="nav-tab <?php echo $active_tab == 'allocations' ? 'active' : ''; ?>">Allocations</a>
        <a href="coordinator_reports.php" class="nav-tab">Reports</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Clinical Sites Section -->
        <div id="sitesSection" class="content-section <?php echo $active_tab == 'sites' ? 'active' : ''; ?>">
            <div class="card">
                <h2>Add Clinical Site</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Site Name</label>
                            <input type="text" name="name" placeholder="Enter site name" required>
                        </div>
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" placeholder="Enter location" required>
                        </div>
                        <div class="form-group">
                            <label>Contact Person</label>
                            <input type="text" name="contact_person" placeholder="Contact person name">
                        </div>
                        <div class="form-group">
                            <label>Contact Phone</label>
                            <input type="text" name="contact_phone" placeholder="Contact phone number">
                        </div>
                        <div class="form-group">
                            <label>Max Capacity</label>
                            <input type="number" name="capacity" placeholder="Maximum students" value="10">
                        </div>
                    </div>
                    <button type="submit" name="add_site" class="btn-primary">Add Site</button>
                </form>
            </div>
            
            <!-- Action Bar for Sites -->
            <div class="action-bar">
                <div class="action-buttons">
                    <button class="btn-view" onclick="toggleView('sitesTable')">View Clinical Sites</button>
                    <button class="btn-archive" onclick="archiveData('sites')">Archive Sites</button>
                </div>
                <span class="filter-info">Click View to see clinical sites list</span>
            </div>
            
            <!-- Sites Data Table (Hidden by default) -->
            <div id="sitesTable" class="data-table-container">
                <div class="card" style="padding: 0; overflow: hidden;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Location</th>
                                <th>Contact Person</th>
                                <th>Contact Phone</th>
                                <th>Max Students</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coordinator->getSites() as $site): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($site['name']); ?></td>
                                <td><?php echo htmlspecialchars($site['location']); ?></td>
                                <td><?php echo htmlspecialchars($site['contact_person']); ?></td>
                                <td><?php echo htmlspecialchars($site['contact_phone']); ?></td>
                                <td><?php echo $site['max_students']; ?></td>
                                <td>
                                    <?php if ($site['agreement_status'] == 'agreed'): ?>
                                        <span class="badge" style="background:#d4edda; color:#155724; padding:3px 10px; border-radius:15px;">Agreed</span>
                                    <?php elseif ($site['agreement_status'] == 'pending'): ?>
                                        <span class="badge" style="background:#fff3cd; color:#856404; padding:3px 10px; border-radius:15px;">Pending</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f8d7da; color:#721c24; padding:3px 10px; border-radius:15px;">Declined</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="site_id" value="<?php echo $site['site_id']; ?>">
                                        <button type="submit" name="delete_site" style="background:#dc3545; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer;" onclick="return confirm('Delete this site?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Students Section -->
        <div id="studentsSection" class="content-section <?php echo $active_tab == 'students' ? 'active' : ''; ?>">
            <div class="card">
                <h2>Add Student</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Student Number</label>
                            <input type="text" name="student_number" placeholder="e.g., BScNM/2021/001" required>
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
                                <option value="">Select Mode of Entry</option>
                                <option value="Generic">Generic</option>
                                <option value="Upgrading">Upgrading</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_student" class="btn-primary">Add Student</button>
                </form>
            </div>
            
            <!-- Action Bar for Students -->
            <div class="action-bar">
                <div class="action-buttons">
                    <button class="btn-view" onclick="toggleView('studentsTable')">View Students</button>
                    <button class="btn-archive" onclick="archiveData('students')">Archive Students</button>
                </div>
                <span class="filter-info">Click View to see students list</span>
            </div>
            
            <!-- Students Data Table (Hidden by default) -->
            <div id="studentsTable" class="data-table-container">
                <div class="card" style="padding: 0; overflow: hidden;">
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
                            <?php foreach ($coordinator->getStudents() as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['student_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                <td><?php echo htmlspecialchars($student['email']); ?></td>
                                <td><?php echo htmlspecialchars($student['cohort']); ?></td>
                                <td><?php echo htmlspecialchars($student['mode_of_entry'] ?? 'Generic'); ?></td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                        <button type="submit" name="delete_student" style="background:#dc3545; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer;" onclick="return confirm('Delete this student?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Allocations Section -->
        <div id="allocationsSection" class="content-section <?php echo $active_tab == 'allocations' ? 'active' : ''; ?>">
            <div class="card">
                <h2>Create Allocation</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Student</label>
                            <select name="student_id" required>
                                <option value="">Select Student</option>
                                <?php foreach ($coordinator->getStudents() as $student): ?>
                                    <option value="<?php echo $student['student_id']; ?>"><?php echo $student['student_number']; ?> - <?php echo $student['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Clinical Site</label>
                            <select name="site_id" required>
                                <option value="">Select Site</option>
                                <?php foreach ($coordinator->getSites() as $site): ?>
                                    <option value="<?php echo $site['site_id']; ?>"><?php echo $site['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" name="role" placeholder="e.g., General Nursing, Midwifery" value="General Nursing">
                        </div>
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" required>
                        </div>
                    </div>
                    <button type="submit" name="allocate" class="btn-primary">Create Allocation</button>
                </form>
            </div>
            
            <!-- Action Bar for Allocations -->
            <div class="action-bar">
                <div class="action-buttons">
                    <button class="btn-view" onclick="toggleView('allocationsTable')">View Allocations</button>
                    <button class="btn-archive" onclick="archiveData('allocations')">Archive Allocations</button>
                </div>
                <span class="filter-info">Click View to see allocations list</span>
            </div>
            
            <!-- Allocations Data Table (Hidden by default) -->
            <div id="allocationsTable" class="data-table-container">
                <div class="card" style="padding: 0; overflow: hidden;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Site</th>
                                <th>Role</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Days Left</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coordinator->getAllocationsWithDaysRemaining() as $alloc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($alloc['student_name']); ?> (<?php echo $alloc['student_number']; ?>)</td>
                                <td><?php echo htmlspecialchars($alloc['site_name']); ?></td>
                                <td><?php echo htmlspecialchars($alloc['role']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($alloc['start_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($alloc['end_date'])); ?></td>
                                <td>
                                    <?php 
                                    $days = $alloc['days_remaining'];
                                    if ($alloc['status'] == 'completed') {
                                        echo '<span style="color:#6c757d;">Completed</span>';
                                    } elseif ($days < 0) {
                                        echo '<span style="color:#dc3545;">Overdue by ' . abs($days) . ' days</span>';
                                    } elseif ($days == 0) {
                                        echo '<span style="color:#ffc107;">Ends Today</span>';
                                    } elseif ($days <= 7) {
                                        echo '<span style="color:#ffc107;">' . $days . ' days left</span>';
                                    } else {
                                        echo '<span style="color:#28a745;">' . $days . ' days left</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($alloc['placement_status'] == 'Active') {
                                        echo '<span style="background:#28a745; color:white; padding:3px 10px; border-radius:15px;">Active</span>';
                                    } elseif ($alloc['placement_status'] == 'Expiring Soon') {
                                        echo '<span style="background:#ffc107; color:#333; padding:3px 10px; border-radius:15px;">Expiring Soon</span>';
                                    } elseif ($alloc['placement_status'] == 'Overdue') {
                                        echo '<span style="background:#dc3545; color:white; padding:3px 10px; border-radius:15px;">Overdue</span>';
                                    } else {
                                        echo '<span style="background:#6c757d; color:white; padding:3px 10px; border-radius:15px;">Completed</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="alloc_id" value="<?php echo $alloc['alloc_id']; ?>">
                                        <button type="submit" name="delete_allocation" style="background:#dc3545; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer;" onclick="return confirm('Delete this allocation?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleView(tableId) {
            var table = document.getElementById(tableId);
            if (table.classList.contains('visible')) {
                table.classList.remove('visible');
                // Optionally, you could hide it completely
            } else {
                table.classList.add('visible');
            }
        }
        
        function archiveData(type) {
            if (confirm('Archive ' + type + ' data? This will save a snapshot of the current data.')) {
                alert(type.charAt(0).toUpperCase() + type.slice(1) + ' data archived successfully! (Demo feature)');
                // In production, you would save to an archive table
            }
        }
        
        // Initialize - hide all data tables
        document.addEventListener('DOMContentLoaded', function() {
            var tables = document.querySelectorAll('.data-table-container');
            tables.forEach(function(table) {
                table.classList.remove('visible');
            });
        });
    </script>
</body>
</html>