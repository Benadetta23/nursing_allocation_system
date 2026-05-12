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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['allocate'])) {
    if ($coordinator->createAllocation($_POST['student_id'], $_POST['site_id'], $_POST['start_date'], $_POST['end_date'], $_POST['role'])) {
        $message = "✅ Allocation created successfully!";
    } else {
        $error = "❌ Failed to create allocation.";
    }
}

if (isset($_POST['delete_allocation'])) {
    if ($coordinator->deleteAllocation($_POST['alloc_id'])) {
        $message = "✅ Allocation deleted successfully!";
    } else {
        $error = "❌ Failed to delete allocation.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Allocations</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            background: rgba(26, 42, 108, 0.9);
            z-index: -1;
        }
        .header {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { color: white; font-size: 1.3rem; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-info span { color: white; }
        .role-badge { background: #c3a343; color: #1a2a6c; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; }
        .btn-logout { background: rgba(255,255,255,0.2); color: white; padding: 6px 16px; border-radius: 30px; text-decoration: none; font-size: 0.8rem; }
        .btn-logout:hover { background: #dc3545; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
        .btn-back:hover { background: #c3a343; color: #1a2a6c; }
        .card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .card h2 { color: #1a2a6c; margin-bottom: 20px; border-left: 4px solid #c3a343; padding-left: 15px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        .btn-primary { background: #1a2a6c; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; }
        .btn-primary:hover { background: #2a3a7c; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 5px; cursor: pointer; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }
        .data-table th { background: #f8f9fa; color: #1a2a6c; }
        .badge-success { background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; }
        .message { padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏥 Daeyang University - Allocations Management</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    <div class="container">
        <a href="coordinator_dashboard.php" class="btn-back">← Back to Dashboard</a>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>➕ Create New Allocation</h2>
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
                <button type="submit" name="allocate" class="btn-primary">📌 Create Allocation</button>
            </form>
        </div>
        
        <div class="card">
            <h2>📋 Current Allocations</h2>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Student</th><th>Site</th><th>Role</th><th>Start Date</th><th>End Date</th><th>Status</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coordinator->getAllocations() as $alloc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($alloc['student_name']); ?> (<?php echo $alloc['student_number']; ?>)</td>
                            <td><?php echo htmlspecialchars($alloc['site_name']); ?></td>
                            <td><?php echo htmlspecialchars($alloc['role']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($alloc['start_date'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($alloc['end_date'])); ?></td>
                            <td><span class="badge-success"><?php echo ucfirst($alloc['status']); ?></span></td>
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
    </div>
</body>
</html>