<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
$coordinator = new Coordinator();

$report_type = $_GET['report'] ?? 'students_by_site';
$filter_site = $_GET['filter_site'] ?? '';
$filter_cohort = $_GET['filter_cohort'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_student = $_GET['filter_student'] ?? '';
$export_format = $_GET['export'] ?? '';

// Get all sites for filter dropdown
$sites = $coordinator->getSites();

// Get all cohorts for filter dropdown - Fixed to use method
$cohorts = [];
try {
    $cohorts = $coordinator->getCohorts();
} catch (Exception $e) {
    // If method doesn't exist, use hardcoded cohorts
    $cohorts = [['cohort' => '2021'], ['cohort' => '2022'], ['cohort' => '2023'], ['cohort' => '2024']];
}

// Get all students for filter dropdown
$students = $coordinator->getStudents();

// Helper function to get cohort list (fallback if method missing)
function getCohortList() {
    return ['2021', '2022', '2023', '2024'];
}

// Function to export to Excel
function exportToExcel($data, $filename, $headers) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th style="background:#1a2a6c; color:white;">' . $header . '</th>';
    }
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $key => $cell) {
            // Skip non-displayable fields
            if (in_array($key, ['site_id', 'student_id', 'alloc_id'])) continue;
            echo '<td>' . htmlspecialchars((string)$cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    exit();
}

// Handle export
if ($export_format == 'excel') {
    if ($report_type == 'students_by_site') {
        $data = $coordinator->getStudentsBySiteFiltered($filter_site);
        $headers = ['Site Name', 'Location', 'Total Students', 'Students List'];
        exportToExcel($data, 'students_by_site_report', $headers);
    } elseif ($report_type == 'pending_assessments') {
        $data = $coordinator->getPendingAssessmentsFiltered($filter_site, $filter_cohort);
        $headers = ['Student Name', 'Student ID', 'Clinical Site', 'Role', 'Start Date', 'End Date'];
        exportToExcel($data, 'pending_assessments_report', $headers);
    } elseif ($report_type == 'assessment_summary') {
        $data = $coordinator->getAssessmentSummaryFiltered($filter_cohort, $filter_student);
        $headers = ['Student Name', 'Student ID', 'Cohort', 'Punctuality', 'Dressing', 'Communication', 'Overall', 'Assessments'];
        exportToExcel($data, 'assessment_summary_report', $headers);
    } elseif ($report_type == 'site_summary') {
        $data = $coordinator->getSiteAssessmentSummaryFiltered($filter_site);
        $headers = ['Site Name', 'Students Assessed', 'Avg Punctuality', 'Avg Dressing', 'Avg Communication', 'Total Assessments'];
        exportToExcel($data, 'site_performance_report', $headers);
    }
}

// Get filtered data based on report type
if ($report_type == 'students_by_site') {
    $reportData = $coordinator->getStudentsBySiteFiltered($filter_site);
    $reportTitle = 'Students by Clinical Site';
} elseif ($report_type == 'pending_assessments') {
    $reportData = $coordinator->getPendingAssessmentsFiltered($filter_site, $filter_cohort);
    $reportTitle = 'Pending Assessments';
} elseif ($report_type == 'assessment_summary') {
    $reportData = $coordinator->getAssessmentSummaryFiltered($filter_cohort, $filter_student);
    $reportTitle = 'Assessment Summary';
} elseif ($report_type == 'site_summary') {
    $reportData = $coordinator->getSiteAssessmentSummaryFiltered($filter_site);
    $reportTitle = 'Site Performance Summary';
} else {
    $reportData = [];
    $reportTitle = 'Reports';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Coordinator Dashboard</title>
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
            flex-wrap: wrap;
            gap: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
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
            background: white;
            display: flex;
            gap: 5px;
            padding: 0 20px;
            border-bottom: 1px solid #e0e0e0;
            flex-wrap: wrap;
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #1a2a6c;
            font-size: 0.8rem;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .btn-filter {
            background: #1a2a6c;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .btn-filter:hover {
            background: #c3a343;
            color: #1a2a6c;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }
        
        .btn-export-excel {
            background: #28a745;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 0.8rem;
        }
        
        .btn-export-excel:hover {
            background: #218838;
        }
        
        .btn-export-pdf {
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .btn-export-pdf:hover {
            background: #c82333;
        }
        
        .btn-print {
            background: #17a2b8;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .btn-print:hover {
            background: #138496;
        }
        
        /* Report Card */
        .report-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .report-card h2 {
            color: #1a2a6c;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
            padding-left: 15px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .report-table th {
            background: #1a2a6c;
            color: white;
            font-weight: 600;
        }
        
        .report-table tr:hover {
            background: #f5f5f5;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 40px;
        }
        
        .report-summary {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
            text-align: right;
            font-weight: 600;
            color: #1a2a6c;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .export-buttons { justify-content: center; }
            .report-table th,
            .report-table td { padding: 8px; font-size: 0.8rem; }
        }
        
        @media print {
            .header, .nav-tabs, .filter-bar, .export-buttons, .btn-logout, .role-badge {
                display: none !important;
            }
            .report-card {
                background: white;
                box-shadow: none;
                padding: 0;
            }
            .report-table th {
                background: #f0f0f0;
                color: black;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Daeyang University - Reports</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="?report=students_by_site" class="nav-tab <?php echo $report_type == 'students_by_site' ? 'active' : ''; ?>">📋 Students by Site</a>
        <a href="?report=pending_assessments" class="nav-tab <?php echo $report_type == 'pending_assessments' ? 'active' : ''; ?>">⚠️ Pending Assessments</a>
        <a href="?report=assessment_summary" class="nav-tab <?php echo $report_type == 'assessment_summary' ? 'active' : ''; ?>">⭐ Assessment Summary</a>
        <a href="?report=site_summary" class="nav-tab <?php echo $report_type == 'site_summary' ? 'active' : ''; ?>">🏥 Site Performance</a>
    </div>
    
    <div class="container">
        <!-- Filter Bar -->
        <div class="filter-bar">
            <?php if ($report_type == 'students_by_site' || $report_type == 'pending_assessments'): ?>
            <div class="filter-group">
                <label>Filter by Clinical Site</label>
                <select name="filter_site" id="filter_site">
                    <option value="">All Sites</option>
                    <?php foreach ($sites as $site): ?>
                        <option value="<?php echo $site['site_id']; ?>" <?php echo $filter_site == $site['site_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($site['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if ($report_type == 'pending_assessments' || $report_type == 'assessment_summary'): ?>
            <div class="filter-group">
                <label>Filter by Cohort</label>
                <select name="filter_cohort" id="filter_cohort">
                    <option value="">All Cohorts</option>
                    <?php if (!empty($cohorts)): ?>
                        <?php foreach ($cohorts as $cohort): ?>
                            <option value="<?php echo $cohort['cohort']; ?>" <?php echo $filter_cohort == $cohort['cohort'] ? 'selected' : ''; ?>>
                                <?php echo $cohort['cohort']; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach (getCohortList() as $cohort): ?>
                            <option value="<?php echo $cohort; ?>" <?php echo $filter_cohort == $cohort ? 'selected' : ''; ?>>
                                <?php echo $cohort; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if ($report_type == 'assessment_summary'): ?>
            <div class="filter-group">
                <label>Filter by Student</label>
                <select name="filter_student" id="filter_student">
                    <option value="">All Students</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['student_id']; ?>" <?php echo $filter_student == $student['student_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['name']); ?> (<?php echo $student['student_number']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="filter-group">
                <button class="btn-filter" onclick="applyFilters()">🔍 Apply Filters</button>
                <a href="?report=<?php echo $report_type; ?>" class="btn-reset">Reset Filters</a>
            </div>
        </div>
        
        <!-- Export Buttons -->
        <div class="export-buttons">
            <button class="btn-export-excel" onclick="exportExcel()">📊 Export to Excel</button>
            <button class="btn-export-pdf" onclick="window.print()">📄 Export to PDF</button>
            <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>
        </div>
        
        <!-- Report Content -->
        <div class="report-card">
            <h2><?php echo $reportTitle; ?></h2>
            
            <?php if (count($reportData) > 0): ?>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <?php if ($report_type == 'students_by_site'): ?>
                                    <th>Site Name</th>
                                    <th>Location</th>
                                    <th>Total Students</th>
                                    <th>Students List</th>
                                <?php elseif ($report_type == 'pending_assessments'): ?>
                                    <th>Student Name</th>
                                    <th>Student ID</th>
                                    <th>Clinical Site</th>
                                    <th>Role</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                <?php elseif ($report_type == 'assessment_summary'): ?>
                                    <th>Student Name</th>
                                    <th>Student ID</th>
                                    <th>Cohort</th>
                                    <th>Punctuality</th>
                                    <th>Dressing</th>
                                    <th>Communication</th>
                                    <th>Overall</th>
                                    <th>Assessments</th>
                                <?php elseif ($report_type == 'site_summary'): ?>
                                    <th>Site Name</th>
                                    <th>Students Assessed</th>
                                    <th>Avg Punctuality</th>
                                    <th>Avg Dressing</th>
                                    <th>Avg Communication</th>
                                    <th>Total Assessments</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                                <tr>
                                    <?php if ($report_type == 'students_by_site'): ?>
                                        <td><?php echo htmlspecialchars($row['site_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['location'] ?? ''); ?></td>
                                        <td><?php echo $row['total_students'] ?? 0; ?></td>
                                        <td style="max-width: 300px; word-wrap: break-word;"><?php echo htmlspecialchars($row['students'] ?? 'No students allocated'); ?></td>
                                    <?php elseif ($report_type == 'pending_assessments'): ?>
                                        <td><?php echo htmlspecialchars($row['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['student_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['site_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['role'] ?? ''); ?></td>
                                        <td><?php echo isset($row['start_date']) ? date('M d, Y', strtotime($row['start_date'])) : '-'; ?></td>
                                        <td><?php echo isset($row['end_date']) ? date('M d, Y', strtotime($row['end_date'])) : '-'; ?></td>
                                    <?php elseif ($report_type == 'assessment_summary'): ?>
                                        <td><?php echo htmlspecialchars($row['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['student_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['cohort'] ?? ''); ?></td>
                                        <td><?php echo isset($row['avg_punctuality']) && $row['avg_punctuality'] ? $row['avg_punctuality'] . '/5' : '-'; ?></td>
                                        <td><?php echo isset($row['avg_dressing']) && $row['avg_dressing'] ? $row['avg_dressing'] . '/5' : '-'; ?></td>
                                        <td><?php echo isset($row['avg_communication']) && $row['avg_communication'] ? $row['avg_communication'] . '/5' : '-'; ?></td>
                                        <td><?php echo isset($row['overall_average']) && $row['overall_average'] ? $row['overall_average'] . '/5' : '-'; ?></td>
                                        <td><?php echo $row['assessment_count'] ?? 0; ?></td>
                                    <?php elseif ($report_type == 'site_summary'): ?>
                                        <td><?php echo htmlspecialchars($row['site_name'] ?? ''); ?></td>
                                        <td><?php echo $row['students_assessed'] ?? 0; ?></td>
                                        <td><?php echo isset($row['avg_punctuality']) && $row['avg_punctuality'] ? $row['avg_punctuality'] . '/5' : '-'; ?></td>
                                        <td><?php echo isset($row['avg_dressing']) && $row['avg_dressing'] ? $row['avg_dressing'] . '/5' : '-'; ?></td>
                                        <td><?php echo isset($row['avg_communication']) && $row['avg_communication'] ? $row['avg_communication'] . '/5' : '-'; ?></td>
                                        <td><?php echo $row['total_assessments'] ?? 0; ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="report-summary">
                    Total Records: <?php echo count($reportData); ?>
                </div>
            <?php else: ?>
                <p class="no-data">No data available for this report with the selected filters.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            const filterSite = document.getElementById('filter_site')?.value || '';
            const filterCohort = document.getElementById('filter_cohort')?.value || '';
            const filterStudent = document.getElementById('filter_student')?.value || '';
            
            let url = '?report=<?php echo $report_type; ?>';
            if (filterSite) url += '&filter_site=' + filterSite;
            if (filterCohort) url += '&filter_cohort=' + filterCohort;
            if (filterStudent) url += '&filter_student=' + filterStudent;
            
            window.location.href = url;
        }
        
        function exportExcel() {
            const filterSite = document.getElementById('filter_site')?.value || '';
            const filterCohort = document.getElementById('filter_cohort')?.value || '';
            const filterStudent = document.getElementById('filter_student')?.value || '';
            
            let url = '?report=<?php echo $report_type; ?>&export=excel';
            if (filterSite) url += '&filter_site=' + filterSite;
            if (filterCohort) url += '&filter_cohort=' + filterCohort;
            if (filterStudent) url += '&filter_student=' + filterStudent;
            
            window.location.href = url;
        }
    </script>
</body>
</html>