<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
$coordinator = new Coordinator();
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
            background: rgba(26, 42, 108, 0.85);
            z-index: -1;
        }
        
        /* Header */
        .header {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            color: white;
            font-size: 1.3rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
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
        
        /* Main Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        /* Welcome Card */
        .welcome-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border-left: 5px solid #c3a343;
        }
        
        .welcome-card h2 {
            color: #1a2a6c;
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .welcome-card p {
            color: #666;
        }
        
        /* Navigation Tabs */
        .nav-tabs {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }
        
        .nav-tab {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(5px);
            border: none;
            padding: 14px 30px;
            border-radius: 50px;
            color: white;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .nav-tab:hover {
            background: #c3a343;
            color: #1a2a6c;
            transform: translateY(-3px);
        }
        
        /* Quick Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 40px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(5px);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: 0.3s;
            border-bottom: 3px solid #c3a343;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.95);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1a2a6c;
        }
        
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        
        /* Additional Stats Row */
        .stats-row-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .info-card {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(5px);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }
        
        .info-card h4 {
            color: #1a2a6c;
            margin-bottom: 10px;
        }
        
        .info-card p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-active {
            background: #28a745;
            color: white;
        }
        
        .badge-warning {
            background: #ffc107;
            color: #333;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .welcome-card h2 { font-size: 1.3rem; }
            .nav-tab { padding: 10px 20px; font-size: 0.9rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏥 Daeyang University - Nursing Allocation System</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['name']); ?>! 👋</h2>
            <p>Manage clinical sites, nursing students, and allocations from your dashboard.</p>
        </div>
        
        <!-- Navigation Tabs -->
        <div class="nav-tabs">
            <a href="coordinator_sites.php" class="nav-tab">🏥 Clinical Sites</a>
            <a href="coordinator_students.php" class="nav-tab">👩‍🎓 Students</a>
            <a href="coordinator_allocations.php" class="nav-tab">📌 Allocations</a>
            <a href="coordinator_reports.php" class="nav-tab">📊 Reports</a>
        </div>
        
        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($coordinator->getSites()); ?></div>
                <div class="stat-label">🏥 Clinical Sites</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($coordinator->getStudents()); ?></div>
                <div class="stat-label">👩‍🎓 Nursing Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($coordinator->getAllocations()); ?></div>
                <div class="stat-label">📌 Active Allocations</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($coordinator->getPendingAssessments()); ?></div>
                <div class="stat-label">⚠️ Pending Assessments</div>
            </div>
        </div>
        
        <!-- Additional Info Row -->
        <div class="stats-row-2">
            <div class="info-card">
                <h4>📋 Total Assessments</h4>
                <?php
                $assessments = $coordinator->getAssessmentSummary();
                $totalAssessments = 0;
                foreach ($assessments as $a) {
                    $totalAssessments += $a['assessment_count'];
                }
                ?>
                <p class="stat-number" style="font-size: 1.5rem;"><?php echo $totalAssessments; ?></p>
                <p>Assessments Completed</p>
            </div>
            <div class="info-card">
                <h4>⭐ Average Performance</h4>
                <?php
                $totalAvg = 0;
                $count = 0;
                foreach ($assessments as $a) {
                    if ($a['overall_average']) {
                        $totalAvg += $a['overall_average'];
                        $count++;
                    }
                }
                $avgScore = $count > 0 ? round($totalAvg / $count, 1) : 0;
                ?>
                <p class="stat-number" style="font-size: 1.5rem;"><?php echo $avgScore; ?>/5</p>
                <p>Average Student Score</p>
            </div>
        </div>
    </div>
</body>
</html>