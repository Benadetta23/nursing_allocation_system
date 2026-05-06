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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_site'])) {
        if ($coordinator->addSite($_POST['name'], $_POST['location'], $_POST['contact_person'], $_POST['contact_phone'], $_POST['capacity'])) {
            $message = "✅ Clinical site added successfully!";
        } else {
            $error = "❌ Failed to add clinical site.";
        }
    }
    
    if (isset($_POST['add_student'])) {
        if ($coordinator->addStudent($_POST['student_number'], $_POST['name'], $_POST['email'], $_POST['cohort'], $_POST['program'], 1)) {
            $message = "✅ Student added successfully! Password is 'pass'.";
        } else {
            $error = "❌ Failed to add student.";
        }
    }
    
    if (isset($_POST['allocate'])) {
        if ($coordinator->createAllocation($_POST['student_id'], $_POST['site_id'], $_POST['start_date'], $_POST['end_date'], $_POST['role'])) {
            $message = "✅ Allocation created successfully!";
        } else {
            $error = "❌ Failed to create allocation.";
        }
    }
    
    if (isset($_POST['delete_site'])) {
        $coordinator->deleteSite($_POST['site_id']);
        $message = "✅ Site deleted successfully!";
    }
    
    if (isset($_POST['delete_student'])) {
        $coordinator->deleteStudent($_POST['student_id']);
        $message = "✅ Student deleted successfully!";
    }
    
    if (isset($_POST['delete_allocation'])) {
        $coordinator->deleteAllocation($_POST['alloc_id']);
        $message = "✅ Allocation deleted successfully!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1>🏥 Coordinator Dashboard</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <span class="role-badge">Coordinator</span>
                <a href="actions/logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="dashboard-content">
            <?php if ($message): ?>
                <div class="success-msg"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-msg"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <h3>🏥 Add Clinical Site</h3>
                <form method="POST">
                    <input type="text" name="name" placeholder="Site Name" required>
                    <input type="text" name="location" placeholder="Location" required>
                    <input type="text" name="contact_person" placeholder="Contact Person">
                    <input type="text" name="contact_phone" placeholder="Contact Phone">
                    <input type="number" name="capacity" placeholder="Capacity" value="10">
                    <button type="submit" name="add_site" class="btn-primary">Add Site</button>
                </form>
            </div>
            
            <div class="card">
                <h3>👩‍🎓 Add Student</h3>
                <form method="POST">
                    <input type="text" name="student_number" placeholder="Student Number" required>
                    <input type="text" name="name" placeholder="Full Name" required>
                    <input type="email" name="email" placeholder="Email" required>
                    <input type="text" name="cohort" placeholder="Cohort">
                    <input type="text" name="program" placeholder="Program">
                    <button type="submit" name="add_student" class="btn-primary">Add Student</button>
                </form>
            </div>
            
            <div class="card">
                <h3>📌 Create Allocation</h3>
                <form method="POST">
                    <select name="student_id" required>
                        <option value="">Select Student</option>
                        <?php foreach ($coordinator->getStudents() as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>"><?php echo $student['student_number']; ?> - <?php echo $student['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="site_id" required>
                        <option value="">Select Site</option>
                        <?php foreach ($coordinator->getSites() as $site): ?>
                            <option value="<?php echo $site['site_id']; ?>"><?php echo $site['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="role" placeholder="Role" value="General Nursing">
                    <input type="date" name="start_date" required>
                    <input type="date" name="end_date" required>
                    <button type="submit" name="allocate" class="btn-primary">Create Allocation</button>
                </form>
            </div>
        </div>
        
        <div class="card full-width">
            <h3>🏥 Clinical Sites</h3>
            <table class="data-table">
                <thead><tr><th>Name</th><th>Location</th><th>Contact</th><th>Capacity</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($coordinator->getSites() as $site): ?>
                    <tr>
                        <td><?php echo $site['name']; ?></td>
                        <td><?php echo $site['location']; ?></td>
                        <td><?php echo $site['contact_person']; ?></td>
                        <td><?php echo $site['capacity']; ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="site_id" value="<?php echo $site['site_id']; ?>">
                                <button type="submit" name="delete_site" class="btn-danger" onclick="return confirm('Delete this site?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card full-width">
            <h3>👩‍🎓 Students</h3>
            <table class="data-table">
                <thead><tr><th>Number</th><th>Name</th><th>Email</th><th>Cohort</th><th>Program</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($coordinator->getStudents() as $student): ?>
                    <tr>
                        <td><?php echo $student['student_number']; ?></td>
                        <td><?php echo $student['name']; ?></td>
                        <td><?php echo $student['email']; ?></td>
                        <td><?php echo $student['cohort']; ?></td>
                        <td><?php echo $student['program']; ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                <button type="submit" name="delete_student" class="btn-danger" onclick="return confirm('Delete this student?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card full-width">
            <h3>📌 Allocations</h3>
            <table class="data-table">
                <thead><tr><th>Student</th><th>Site</th><th>Role</th><th>Period</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($coordinator->getAllocations() as $alloc): ?>
                    <tr>
                        <td><?php echo $alloc['student_name']; ?></td>
                        <td><?php echo $alloc['site_name']; ?></td>
                        <td><?php echo $alloc['role']; ?></td>
                        <td><?php echo $alloc['start_date']; ?> to <?php echo $alloc['end_date']; ?></td>
                        <td><?php echo $alloc['status']; ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="alloc_id" value="<?php echo $alloc['alloc_id']; ?>">
                                <button type="submit" name="delete_allocation" class="btn-danger" onclick="return confirm('Delete this allocation?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>