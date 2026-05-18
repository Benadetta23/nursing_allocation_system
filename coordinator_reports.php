<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
$coordinator = new Coordinator();

$active_tab = $_GET['tab'] ?? 'summary';
$report_type = $_GET['report'] ?? 'students_by_site';
$filter_site = $_GET['filter_site'] ?? '';
$filter_cohort = $_GET['filter_cohort'] ?? '';
$filter_student = $_GET['filter_student'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get all sites for filter dropdown
$sites = $coordinator->getSites();
$students = $coordinator->getStudents();
$cohortList = ['2021', '2022', '2023', '2024'];

// Get filtered data based on report type
if ($report_type == 'students_by_site') {
    $reportData = $coordinator->getStudentsBySiteFiltered($filter_site);
    $reportTitle = 'Students by Clinical Site';
    $tableHeaders = ['Site Name', 'Location', 'Total Students', 'Students List'];
} elseif ($report_type == 'pending_assessments') {
    $reportData = $coordinator->getPendingAssessmentsFiltered($filter_site, $filter_cohort);
    $reportTitle = 'Pending Assessments';
    $tableHeaders = ['Student Name', 'Student ID', 'Clinical Site', 'Role', 'Start Date', 'End Date'];
} elseif ($report_type == 'assessment_summary') {
    $reportData = $coordinator->getAssessmentSummaryFiltered($filter_cohort, $filter_student);
    $reportTitle = 'Assessment Summary';
    $tableHeaders = ['Student Name', 'Student ID', 'Cohort', 'Punctuality', 'Dressing', 'Communication', 'Overall', 'Assessments'];
} elseif ($report_type == 'site_summary') {
    $reportData = $coordinator->getSiteAssessmentSummaryFiltered($filter_site);
    $reportTitle = 'Site Performance Summary';
    $tableHeaders = ['Site Name', 'Students Assessed', 'Average Punctuality', 'Average Dressing', 'Average Communication', 'Total Assessments'];
} else {
    $reportData = [];
    $reportTitle = 'Reports';
    $tableHeaders = [];
}

// Generate report with date range
$generatedReportData = [];
if ($date_from && $date_to) {
    $generatedReportData = $coordinator->getReportByDateRange($date_from, $date_to);
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
        
        /* Action Bar */
        .action-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
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
        
        .btn-view-summary {
            background: #4a2f1a;
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.3s;
        }
        
        .btn-view-summary:hover {
            background: #654321;
        }
        
        .btn-archive {
            background: #6c757d;
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.3s;
        }
        
        .btn-archive:hover {
            background: #5a6268;
        }
        
        .filter-info {
            color: #4a2f1a;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4a2f1a;
            font-size: 0.8rem;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .btn-filter {
            background: #4a2f1a;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .btn-filter:hover {
            background: #654321;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        /* Generate Report Section */
        .generate-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .generate-card h2 {
            color: #4a2f1a;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
            padding-left: 15px;
        }
        
        .date-range {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 20px;
        }
        
        .date-group {
            flex: 1;
            min-width: 200px;
        }
        
        .date-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4a2f1a;
            font-size: 0.8rem;
        }
        
        .date-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .btn-generate {
            background: #4a2f1a;
            color: white;
            padding: 10px 30px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: 0.3s;
        }
        
        .btn-generate:hover {
            background: #654321;
        }
        
        /* Report Card */
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .report-card h2 {
            color: #4a2f1a;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
            padding-left: 15px;
        }
        
        /* Data Table Container - Hidden by default */
        .data-table-container {
            display: none;
            margin-top: 20px;
        }
        
        .data-table-container.visible {
            display: block;
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
            background: #4a2f1a;
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
        
        /* Badge Styles */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
        }
        
        .badge-high {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-low {
            background: #f8d7da;
            color: #721c24;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .header { flex-direction: column; text-align: center; }
            .nav-tabs { justify-content: center; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .action-bar { flex-direction: column; text-align: center; }
            .action-buttons { justify-content: center; }
            .date-range { flex-direction: column; }
            .date-group { width: 100%; }
            .report-table th,
            .report-table td { padding: 8px; font-size: 0.8rem; }
        }
        
        @media print {
            .header, .nav-tabs, .filter-bar, .action-bar, .generate-card, .btn-logout, .role-badge {
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
        <h1>Daeyang University - Reports</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="?tab=summary&report=students_by_site" class="nav-tab <?php echo $active_tab == 'summary' ? 'active' : ''; ?>">Report Summary</a>
        <a href="?tab=generate" class="nav-tab <?php echo $active_tab == 'generate' ? 'active' : ''; ?>">Generate Report</a>
    </div>
    
    <div class="container">
        
        <!-- REPORT SUMMARY TAB -->
        <?php if ($active_tab == 'summary'): ?>
        
        <!-- Action Bar -->
        <div class="action-bar">
            <div class="action-buttons">
                <button class="btn-view-summary" onclick="viewSummary()">View Summary</button>
                <button class="btn-archive" onclick="archiveReport()">Archive</button>
            </div>
            <div class="filter-info">
                Report: <?php echo $reportTitle; ?> | Total Records: <?php echo count($reportData); ?>
            </div>
        </div>
        
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
                    <?php foreach ($cohortList as $cohort): ?>
                        <option value="<?php echo $cohort; ?>" <?php echo $filter_cohort == $cohort ? 'selected' : ''; ?>>
                            <?php echo $cohort; ?>
                        </option>
                    <?php endforeach; ?>
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
                <button class="btn-filter" onclick="applyFilters()">Apply Filters</button>
                <a href="?tab=summary&report=<?php echo $report_type; ?>" class="btn-reset">Reset Filters</a>
            </div>
        </div>
        
        <!-- Report Content - Hidden until View Summary button clicked -->
        <div class="report-card">
            <h2><?php echo $reportTitle; ?></h2>
            
            <div id="reportDataContainer" class="data-table-container">
                <?php if (count($reportData) > 0): ?>
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
                                            <td>
                                                <?php if (isset($row['avg_punctuality']) && $row['avg_punctuality']): ?>
                                                    <span class="badge <?php echo $row['avg_punctuality'] >= 4 ? 'badge-high' : ($row['avg_punctuality'] >= 3 ? 'badge-medium' : 'badge-low'); ?>">
                                                        <?php echo $row['avg_punctuality']; ?>/5
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($row['avg_dressing']) && $row['avg_dressing']): ?>
                                                    <span class="badge <?php echo $row['avg_dressing'] >= 4 ? 'badge-high' : ($row['avg_dressing'] >= 3 ? 'badge-medium' : 'badge-low'); ?>">
                                                        <?php echo $row['avg_dressing']; ?>/5
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($row['avg_communication']) && $row['avg_communication']): ?>
                                                    <span class="badge <?php echo $row['avg_communication'] >= 4 ? 'badge-high' : ($row['avg_communication'] >= 3 ? 'badge-medium' : 'badge-low'); ?>">
                                                        <?php echo $row['avg_communication']; ?>/5
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($row['overall_average']) && $row['overall_average']): ?>
                                                    <span class="badge <?php echo $row['overall_average'] >= 4 ? 'badge-high' : ($row['overall_average'] >= 3 ? 'badge-medium' : 'badge-low'); ?>">
                                                        <?php echo $row['overall_average']; ?>/5
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
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
                <?php else: ?>
                    <p class="no-data">No data available for this report with the selected filters.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- GENERATE REPORT TAB -->
        <?php if ($active_tab == 'generate'): ?>
        
        <div class="generate-card">
            <h2>Generate Report by Date Range</h2>
            <form method="GET" action="">
                <input type="hidden" name="tab" value="generate">
                <div class="date-range">
                    <div class="date-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>" required>
                    </div>
                    <div class="date-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>" required>
                    </div>
                    <div class="date-group">
                        <button type="submit" class="btn-generate">Generate Report</button>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if ($date_from && $date_to && !empty($generatedReportData)): ?>
        <div class="report-card">
            <h2>Report from <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></h2>
            
            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Clinical Site</th>
                            <th>Assessment Date</th>
                            <th>Punctuality</th>
                            <th>Dressing</th>
                            <th>Communication</th>
                            <th>Lecturer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($generatedReportData as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['student_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['student_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['site_name'] ?? ''); ?></td>
                            <td><?php echo isset($row['assessment_date']) ? date('M d, Y', strtotime($row['assessment_date'])) : '-'; ?></td>
                            <td class="text-center"><?php echo $row['punctuality_score'] ?? '-'; ?>/5</td>
                            <td class="text-center"><?php echo $row['dressing_score'] ?? '-'; ?>/5</td>
                            <td class="text-center"><?php echo $row['communication_score'] ?? '-'; ?>/5</td>
                            <td><?php echo htmlspecialchars($row['lecturer_name'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="filter-info" style="margin-top: 15px; text-align: right;">
                Total Records: <?php echo count($generatedReportData); ?>
            </div>
        </div>
        <?php elseif ($date_from && $date_to): ?>
        <div class="report-card">
            <p class="no-data">No data found for the selected date range.</p>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
        
    </div>
    
    <script>
        function applyFilters() {
            const filterSite = document.getElementById('filter_site')?.value || '';
            const filterCohort = document.getElementById('filter_cohort')?.value || '';
            const filterStudent = document.getElementById('filter_student')?.value || '';
            
            let url = '?tab=summary&report=<?php echo $report_type; ?>';
            if (filterSite) url += '&filter_site=' + filterSite;
            if (filterCohort) url += '&filter_cohort=' + filterCohort;
            if (filterStudent) url += '&filter_student=' + filterStudent;
            
            window.location.href = url;
        }
        
        function viewSummary() {
            var container = document.getElementById('reportDataContainer');
            if (container.classList.contains('visible')) {
                container.classList.remove('visible');
            } else {
                container.classList.add('visible');
            }
        }
        
        function archiveReport() {
            if (confirm('Archive this report? This will save a snapshot of the current data.')) {
                alert('Report archived successfully!');
            }
        }
        
        // Initialize - report data is hidden by default
        document.addEventListener('DOMContentLoaded', function() {
            var container = document.getElementById('reportDataContainer');
            if (container) {
                container.classList.remove('visible');
            }
        });
    </script>
</body>
</html>