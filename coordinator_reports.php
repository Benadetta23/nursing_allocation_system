<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
$coordinator = new Coordinator();

$report_type = $_POST['report_type'] ?? $_GET['report_type'] ?? 'clinical_sites';
$filter_site = $_POST['filter_site'] ?? $_GET['filter_site'] ?? '';
$filter_status = $_POST['filter_status'] ?? $_GET['filter_status'] ?? '';
$export_format = $_POST['export_format'] ?? $_GET['export'] ?? '';
$generated = false;
$noDataAlert = '';

// Get data for filters
$sites = $coordinator->getSites();

// Debug: Check if filter_site is being received correctly
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($filter_site)) {
    error_log("Selected site ID: " . $filter_site);
}

// Get report data based on selections
$reportData = [];
$reportTitle = '';
$tableHeaders = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' || isset($_GET['generate'])) {
    $generated = true;
    
    if ($report_type == 'clinical_sites') {
        if (!empty($filter_site)) {
            // Get specific site report using the dedicated method
            $siteData = $coordinator->getStudentsBySpecificSite($filter_site);
            $reportData = $siteData;
            
            // Get site name for title
            $siteName = '';
            foreach ($sites as $s) {
                if ($s['site_id'] == $filter_site) {
                    $siteName = $s['name'];
                    break;
                }
            }
            $reportTitle = 'Clinical Site Report - ' . $siteName;
            
            // Check if site has no students
            if (empty($reportData) || (isset($reportData[0]['total_students']) && $reportData[0]['total_students'] == 0)) {
                $noDataAlert = "No students found allocated to " . $siteName;
                $reportData = [];
            }
        } else {
            // Get all sites report
            $reportData = $coordinator->getStudentsByClinicalSiteReport();
            $reportTitle = 'All Clinical Sites Report';
            
            if (empty($reportData)) {
                $noDataAlert = "No clinical sites found. Please add clinical sites first.";
            }
        }
        $tableHeaders = ['Site Name', 'Location', 'Contact Person', 'Contact Phone', 'Total Students', 'Students List'];
        
    } elseif ($report_type == 'assessment') {
        // Get assessment report with pass/fail filter
        $allAssessments = $coordinator->getAssessmentReport('', $filter_site);
        
        // Filter by status if selected
        foreach ($allAssessments as $assessment) {
            if ($filter_status == 'pass' && $assessment['status'] == 'PASS') {
                $reportData[] = $assessment;
            } elseif ($filter_status == 'fail' && $assessment['status'] == 'FAIL') {
                $reportData[] = $assessment;
            } elseif ($filter_status == 'pending' && $assessment['status'] == 'PENDING') {
                $reportData[] = $assessment;
            } elseif (empty($filter_status)) {
                $reportData[] = $assessment;
            }
        }
        
        $statusText = '';
        if ($filter_status == 'pass') $statusText = ' - Passed Students Only';
        if ($filter_status == 'fail') $statusText = ' - Failed Students Only';
        if ($filter_status == 'pending') $statusText = ' - Pending Assessments';
        
        $siteText = '';
        if (!empty($filter_site)) {
            foreach ($sites as $s) {
                if ($s['site_id'] == $filter_site) {
                    $siteText = ' - ' . $s['name'];
                    break;
                }
            }
        }
        
        $reportTitle = 'Assessment Report' . $siteText . $statusText;
        $tableHeaders = ['Student Number', 'Student Name', 'Cohort', 'Clinical Site', 'Matron Score', 'Final Score', 'Grade', 'Status'];
        
        // Set alert message for no data
        if (empty($reportData)) {
            if ($filter_status == 'pass') {
                $noDataAlert = 'No passed students found';
            } elseif ($filter_status == 'fail') {
                $noDataAlert = 'No failed students found. All students have passed!';
            } elseif ($filter_status == 'pending') {
                $noDataAlert = 'No pending assessments found';
            } elseif (!empty($filter_site)) {
                $siteName = '';
                foreach ($sites as $s) {
                    if ($s['site_id'] == $filter_site) {
                        $siteName = $s['name'];
                        break;
                    }
                }
                $noDataAlert = 'No assessment data found for ' . $siteName;
            } else {
                $noDataAlert = 'No assessment data found';
            }
        }
    }
    
    // Handle export
    if ($export_format && !empty($reportData)) {
        exportToFormat($reportData, str_replace(' ', '_', strtolower($reportTitle)), $export_format, $tableHeaders, $report_type);
        exit;
    }
}

function exportToFormat($data, $filename, $format, $headers, $report_type) {
    if ($format == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        if (!empty($headers)) {
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<th>' . $header . '</th>';
            }
            echo '</tr>';
        }
        
        if ($report_type == 'assessment') {
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['student_number'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($row['student_name'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($row['cohort'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($row['site_name'] ?? 'Not Allocated') . '</td>';
                echo '<td>' . ($row['matron_average'] ? $row['matron_average'] . '/5' : 'Pending') . '</td>';
                echo '<td>' . ($row['final_average'] ? $row['final_average'] . '/5' : 'Pending') . '</td>';
                echo '<td>' . ($row['final_grade'] ?? 'N/A') . '</td>';
                echo '<td>' . ($row['status'] ?? 'PENDING') . '</td>';
                echo '</tr>';
            }
        } elseif ($report_type == 'clinical_sites') {
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['site_name'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($row['location'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($row['contact_person'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($row['contact_phone'] ?? 'N/A') . '</td>';
                echo '<td>' . ($row['total_students'] ?? 0) . '</td>';
                if (!empty($row['students'])) {
                    echo '<td>' . htmlspecialchars(implode(', ', $row['students'])) . '</td>';
                } else {
                    echo '<td>No students allocated</td>';
                }
                echo '</tr>';
            }
        }
        echo '</table>';
        exit;
        
    } elseif ($format == 'pdf') {
        header('Content-Type: text/html');
        echo '<html><head><title>' . $filename . '</title>';
        echo '<style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #4a2f1a; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th { background: #4a2f1a; color: white; padding: 12px; text-align: left; }
            td { border: 1px solid #ddd; padding: 10px; }
            tr:nth-child(even) { background: #f9f9f9; }
            .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
            .pass { color: green; font-weight: bold; }
            .fail { color: red; font-weight: bold; }
            .pending { color: orange; font-weight: bold; }
        </style>';
        echo '</head><body>';
        echo '<h1>Daeyang University - ' . str_replace('_', ' ', $filename) . '</h1>';
        echo '<p>Generated on: ' . date('F d, Y H:i:s') . '</p>';
        
        if (!empty($headers) && !empty($data)) {
            echo '<table>';
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<th>' . $header . '</th>';
            }
            echo '</tr>';
            
            if ($report_type == 'assessment') {
                foreach ($data as $row) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($row['student_number'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($row['student_name'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($row['cohort'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($row['site_name'] ?? 'Not Allocated') . '</td>';
                    echo '<td>' . ($row['matron_average'] ? $row['matron_average'] . '/5' : 'Pending') . '</td>';
                    echo '<td>' . ($row['final_average'] ? $row['final_average'] . '/5' : 'Pending') . '</td>';
                    echo '<td>' . ($row['final_grade'] ?? 'N/A') . '</td>';
                    $statusClass = strtolower($row['status'] ?? 'pending');
                    echo '<td class="' . $statusClass . '">' . ($row['status'] ?? 'PENDING') . '</td>';
                    echo '</tr>';
                }
            } elseif ($report_type == 'clinical_sites') {
                foreach ($data as $row) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($row['site_name'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($row['location'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($row['contact_person'] ?? 'N/A') . '</td>';
                    echo '<td>' . htmlspecialchars($row['contact_phone'] ?? 'N/A') . '</td>';
                    echo '<td>' . ($row['total_students'] ?? 0) . '</td>';
                    if (!empty($row['students'])) {
                        echo '<td>' . htmlspecialchars(implode(', ', $row['students'])) . '</td>';
                    } else {
                        echo '<td>No students allocated</td>';
                    }
                    echo '</tr>';
                }
            }
            echo '</table>';
        }
        echo '<div class="footer">Daeyang University Nursing Department - Official Report</div>';
        echo '</body></html>';
        echo '<script>window.print();</script>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Reports - Coordinator Dashboard</title>
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .generator-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .generator-card h2 {
            color: #4a2f1a;
            margin-bottom: 25px;
            border-left: 4px solid #c3a343;
            padding-left: 15px;
            font-size: 1.3rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a2f1a;
            font-size: 0.85rem;
        }
        
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: 0.3s;
        }
        
        .form-group select:focus {
            outline: none;
            border-color: #c3a343;
        }
        
        .filter-group {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #c3a343;
        }
        
        .small-text {
            color: #666;
            display: block;
            margin-top: 5px;
            font-size: 0.7rem;
        }
        
        .export-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .btn-generate {
            background: #4a2f1a;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: 0.3s;
            flex: 1;
        }
        
        .btn-generate:hover {
            background: #654321;
        }
        
        .results-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
            display: none;
        }
        
        .results-card.visible {
            display: block;
        }
        
        .results-card h3 {
            color: #4a2f1a;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #c3a343;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .report-table th {
            background: #4a2f1a;
            color: white;
            font-weight: 600;
        }
        
        .report-table tr:hover {
            background: #f5f5f5;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-pass {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-fail {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .record-count {
            margin-top: 15px;
            text-align: right;
            font-size: 0.8rem;
            color: #666;
        }
        
        .alert-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            border-left: 5px solid #c3a343;
            z-index: 1000;
            text-align: center;
            min-width: 300px;
        }
        
        .alert-message .alert-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
        
        .alert-message .alert-text {
            color: #4a2f1a;
            font-size: 1rem;
            margin-bottom: 15px;
        }
        
        .alert-message .alert-close {
            background: #4a2f1a;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        
        .alert-message .alert-close:hover {
            background: #654321;
        }
        
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .export-buttons { flex-direction: column; }
            .generator-card { padding: 20px; }
            .report-table th,
            .report-table td { padding: 8px; font-size: 0.7rem; }
            .alert-message { width: 90%; min-width: auto; }
        }
        
        @media print {
            .header, .nav-tabs, .generator-card, .btn-logout, .role-badge, .alert-message, .overlay {
                display: none !important;
            }
            .results-card {
                display: block !important;
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Reports</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="coordinator_Dashboard.php?tab=sites" class="nav-tab">Dashboard</a>
        <a href="coordinator_reports.php" class="nav-tab active">Generate Report</a>
    </div>
    
    <div class="container">
        
        <div class="generator-card">
            <h2>Generate Report</h2>
            
            <form method="POST" id="reportForm">
                <!-- Report Type Selection -->
                <div class="form-group">
                    <label>Select Report Type</label>
                    <select name="report_type" id="report_type" required>
                        <option value="clinical_sites" <?php echo $report_type == 'clinical_sites' ? 'selected' : ''; ?>>Clinical Sites Report - Students by Site</option>
                        <option value="assessment" <?php echo $report_type == 'assessment' ? 'selected' : ''; ?>>Assessment Report - Pass/Fail Status</option>
                    </select>
                </div>
                
                <!-- Clinical Sites Filters -->
                <div id="clinical_sites_filters" class="filter-group" style="<?php echo $report_type == 'clinical_sites' ? 'display: block;' : 'display: none;'; ?>">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Filter by Clinical Site</label>
                        <select name="filter_site" id="clinical_site_select">
                            <option value="">-- All Clinical Sites --</option>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?php echo $site['site_id']; ?>" <?php echo $filter_site == $site['site_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['name']); ?> (<?php echo htmlspecialchars($site['location']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="small-text">Select a specific site to see students allocated to that site only</small>
                    </div>
                </div>
                
                <!-- Assessment Filters -->
                <div id="assessment_filters" class="filter-group" style="<?php echo $report_type == 'assessment' ? 'display: block;' : 'display: none;'; ?>">
                    <div class="form-group">
                        <label>Filter by Clinical Site</label>
                        <select name="filter_site">
                            <option value="">-- All Clinical Sites --</option>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?php echo $site['site_id']; ?>" <?php echo $filter_site == $site['site_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['name']); ?> (<?php echo htmlspecialchars($site['location']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Filter by Status</label>
                        <select name="filter_status">
                            <option value="">-- All Students --</option>
                            <option value="pass" <?php echo $filter_status == 'pass' ? 'selected' : ''; ?>>Passed Students Only</option>
                            <option value="fail" <?php echo $filter_status == 'fail' ? 'selected' : ''; ?>>Failed Students Only</option>
                            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending Assessments</option>
                        </select>
                    </div>
                </div>
                
                <!-- Export Format Selection -->
                <div class="form-group">
                    <label>Export Format</label>
                    <select name="export_format" id="export_format" required>
                        <option value="">-- Select Export Format --</option>
                        <option value="excel">Microsoft Excel (.xls)</option>
                        <option value="pdf">PDF Document (.pdf)</option>
                        <option value="print">Print / Web View</option>
                    </select>
                </div>
                
                <div class="export-buttons">
                    <button type="submit" name="generate" value="1" class="btn-generate">Generate Report</button>
                </div>
            </form>
        </div>
        
        <!-- Report Results -->
        <div id="resultsCard" class="results-card <?php echo $generated && !empty($reportData) ? 'visible' : ''; ?>">
            <?php if (!empty($reportData)): ?>
                <h3><?php echo $reportTitle; ?></h3>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <?php foreach ($tableHeaders as $header): ?>
                                    <th><?php echo $header; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($report_type == 'clinical_sites'): ?>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['site_name'] ?? ''); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['location'] ?? ''); ?>\) None
                                        <td><?php echo htmlspecialchars($row['contact_person'] ?? 'N/A'); ?>\) None
                                        <td><?php echo htmlspecialchars($row['contact_phone'] ?? 'N/A'); ?>\) None
                                        <td class="text-center"><?php echo $row['total_students'] ?? 0; ?>\) None
                                        <td style="max-width: 400px;">
                                            <?php if (!empty($row['students'])): ?>
                                                <ul style="margin: 0; padding-left: 20px;">
                                                    <?php foreach ($row['students'] as $student): ?>
                                                        <li><?php echo htmlspecialchars($student); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                No students allocated to this site
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                            <?php elseif ($report_type == 'assessment'): ?>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['student_number'] ?? ''); ?>\) None
                                        <td><?php echo htmlspecialchars($row['student_name'] ?? ''); ?>\) None
                                        <td><?php echo htmlspecialchars($row['cohort'] ?? ''); ?>\) None
                                        <td><?php echo htmlspecialchars($row['site_name'] ?? 'Not Allocated'); ?>\) None
                                        <td class="text-center"><?php echo ($row['matron_average']) ? $row['matron_average'] . '/5' : 'Pending'; ?>\) None
                                        <td class="text-center"><strong><?php echo ($row['final_average']) ? $row['final_average'] . '/5' : 'Pending'; ?></strong>\) None
                                        <td class="text-center"><strong><?php echo $row['final_grade'] ?? 'N/A'; ?></strong>\) None
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo strtolower($row['status'] ?? 'pending'); ?>">
                                                <?php echo $row['status'] ?? 'PENDING'; ?>
                                            </span>
                                         </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="record-count">
                    Total Records: <?php echo count($reportData); ?>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    
    <!-- Alert Modal for No Data -->
    <?php if ($noDataAlert): ?>
    <div class="overlay" id="alertOverlay"></div>
    <div class="alert-message" id="alertMessage">
        <div class="alert-icon">📋</div>
        <div class="alert-text"><?php echo $noDataAlert; ?></div>
        <button class="alert-close" onclick="closeAlert()">OK</button>
    </div>
    <script>
        function closeAlert() {
            document.getElementById('alertMessage').style.display = 'none';
            document.getElementById('alertOverlay').style.display = 'none';
        }
        
        document.getElementById('alertOverlay').addEventListener('click', closeAlert);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAlert();
            }
        });
    </script>
    <?php endif; ?>
    
    <script>
        const reportTypeSelect = document.getElementById('report_type');
        const clinicalFilters = document.getElementById('clinical_sites_filters');
        const assessmentFilters = document.getElementById('assessment_filters');
        const exportFormatSelect = document.getElementById('export_format');
        
        function updateFilters() {
            if (reportTypeSelect.value === 'clinical_sites') {
                clinicalFilters.style.display = 'block';
                assessmentFilters.style.display = 'none';
            } else {
                clinicalFilters.style.display = 'none';
                assessmentFilters.style.display = 'block';
            }
        }
        
        reportTypeSelect.addEventListener('change', updateFilters);
        updateFilters();
        
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const exportFormat = exportFormatSelect.value;
            if (!exportFormat) {
                e.preventDefault();
                alert('Please select an export format (Excel, PDF, or Print)');
                return false;
            }
            
            if (exportFormat === 'print') {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        });
    </script>
</body>
</html>