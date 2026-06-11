<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    header("Location: login.php");
    exit();
}

require_once 'classes/Coordinator.php';
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$coordinator = new Coordinator();
$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'sites';

// Helper function to clean display text
function cleanDisplay($text) {
    if ($text === null) return '';
    $text = str_replace(['(', ')', '\\'], '', $text);
    return trim($text);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_site'])) {
        if ($coordinator->addSite($_POST['name'], $_POST['location'], $_POST['contact_person'], $_POST['contact_phone'], $_POST['capacity'])) {
            $message = "Clinical site added successfully!";
        } else {
            $error = "Failed to add clinical site.";
        }
    }
    
    if (isset($_POST['delete_site'])) {
        if ($coordinator->deleteSite($_POST['site_id'])) {
            $message = "Site deleted successfully!";
        } else {
            $error = "Failed to delete site.";
        }
    }
    
    if (isset($_POST['delete_student'])) {
        if ($coordinator->deleteStudent($_POST['student_id'])) {
            $message = "Student deleted successfully!";
        } else {
            $error = "Failed to delete student.";
        }
    }
    
    // Archive student
    if (isset($_POST['archive_student'])) {
        $student_id = $_POST['student_id'];
        
        $checkAlloc = "SELECT alloc_id FROM allocation WHERE student_id = :student_id AND status = 'active'";
        $checkStmt = $conn->prepare($checkAlloc);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            $error = "Cannot archive student with active allocation.";
        } else {
            $getStudent = "SELECT * FROM student WHERE student_id = :student_id";
            $getStmt = $conn->prepare($getStudent);
            $getStmt->bindParam(':student_id', $student_id);
            $getStmt->execute();
            $student = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                $archiveQuery = "INSERT INTO student_archive (student_id, student_number, name, email, cohort, year_of_study, mode_of_entry, archived_date) 
                                 VALUES (:student_id, :student_number, :name, :email, :cohort, :year_of_study, :mode_of_entry, NOW())";
                $archiveStmt = $conn->prepare($archiveQuery);
                $archiveStmt->bindParam(':student_id', $student['student_id']);
                $archiveStmt->bindParam(':student_number', $student['student_number']);
                $archiveStmt->bindParam(':name', $student['name']);
                $archiveStmt->bindParam(':email', $student['email']);
                $archiveStmt->bindParam(':cohort', $student['cohort']);
                $archiveStmt->bindParam(':year_of_study', $student['year_of_study']);
                $archiveStmt->bindParam(':mode_of_entry', $student['mode_of_entry']);
                
                if ($archiveStmt->execute()) {
                    $deleteQuery = "DELETE FROM student WHERE student_id = :student_id";
                    $deleteStmt = $conn->prepare($deleteQuery);
                    $deleteStmt->bindParam(':student_id', $student_id);
                    $deleteStmt->execute();
                    $message = "Student archived successfully!";
                } else {
                    $error = "Failed to archive student.";
                }
            }
        }
    }
    
    // Handle matron assignment with notification
    if (isset($_POST['assign_matron'])) {
        $matron_id = $_POST['matron_id'];
        $site_id = $_POST['site_id'];
        
        // Get matron details
        $matronQuery = "SELECT name, email FROM matron WHERE matron_id = :matron_id";
        $matronStmt = $conn->prepare($matronQuery);
        $matronStmt->bindParam(':matron_id', $matron_id);
        $matronStmt->execute();
        $matron = $matronStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get site details
        $siteQuery = "SELECT name, location FROM clinical_site WHERE site_id = :site_id";
        $siteStmt = $conn->prepare($siteQuery);
        $siteStmt->bindParam(':site_id', $site_id);
        $siteStmt->execute();
        $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
        
        // Update matron assignment
        $updateQuery = "UPDATE matron SET site_id = :site_id WHERE matron_id = :matron_id";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bindParam(':site_id', $site_id);
        $updateStmt->bindParam(':matron_id', $matron_id);
        
        if ($updateStmt->execute()) {
            // Send notification
            $notification = new Notification($conn);
            
            $subject = "Clinical Site Assignment - Daeyang University";
            $emailMessage = "
                <h3>Dear {$matron['name']},</h3>
                <p>You have been assigned to a clinical site:</p>
                <table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>
                    <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Clinical Site:</strong></td>
                        <td style='padding: 8px;'>{$site['name']}</td>
                    </tr>
                    <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Location:</strong></td>
                        <td style='padding: 8px;'>{$site['location']}</td>
                    </tr>
                </table>
                <p>Please log in to your dashboard to view and assess students at this site.</p>
                <p>Best regards,<br><strong>Nursing Department</strong><br>Daeyang University</p>
            ";
            
            $emailSent = $notification->sendEmail($matron['email'], $subject, $emailMessage);
            $notification->saveNotification(
                $matron_id,
                'matron',
                'Clinical Site Assignment',
                "You have been assigned to {$site['name']} as your clinical site.",
                $site_id
            );
            
            $message = "Matron assigned to clinical site successfully! Notification " . ($emailSent ? "sent." : "pending (in-app sent).");
        } else {
            $error = "Failed to assign matron.";
        }
    }
    
    // Handle remove matron from site
    if (isset($_POST['remove_matron_site'])) {
        $matron_id = $_POST['matron_id'];
        
        $matronQuery = "SELECT m.name, m.email, cs.name as site_name FROM matron m 
                        LEFT JOIN clinical_site cs ON m.site_id = cs.site_id 
                        WHERE m.matron_id = :matron_id";
        $matronStmt = $conn->prepare($matronQuery);
        $matronStmt->bindParam(':matron_id', $matron_id);
        $matronStmt->execute();
        $matron = $matronStmt->fetch(PDO::FETCH_ASSOC);
        
        $updateQuery = "UPDATE matron SET site_id = NULL WHERE matron_id = :matron_id";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bindParam(':matron_id', $matron_id);
        
        if ($updateStmt->execute()) {
            if ($matron && $matron['site_name']) {
                $notification = new Notification($conn);
                $notification->saveNotification(
                    $matron_id,
                    'matron',
                    'Clinical Site Assignment Removed',
                    "You have been removed from {$matron['site_name']}. Please contact the coordinator if you have questions.",
                    null
                );
            }
            $message = "Matron removed from site successfully!";
        } else {
            $error = "Failed to remove matron.";
        }
    }
    
    // Handle lecturer assignment with notification
    if (isset($_POST['assign_lecturer'])) {
        $lecturer_id = $_POST['lecturer_id'];
        $site_id = $_POST['site_id'];
        
        $checkQuery = "SELECT id FROM lecturer_site WHERE lecturer_id = :lecturer_id AND site_id = :site_id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':lecturer_id', $lecturer_id);
        $checkStmt->bindParam(':site_id', $site_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            $error = "This lecturer is already assigned to this clinical site.";
        } else {
            $lecturerQuery = "SELECT name, email FROM lecturer WHERE lecturer_id = :lecturer_id";
            $lecturerStmt = $conn->prepare($lecturerQuery);
            $lecturerStmt->bindParam(':lecturer_id', $lecturer_id);
            $lecturerStmt->execute();
            $lecturer = $lecturerStmt->fetch(PDO::FETCH_ASSOC);
            
            $siteQuery = "SELECT name, location FROM clinical_site WHERE site_id = :site_id";
            $siteStmt = $conn->prepare($siteQuery);
            $siteStmt->bindParam(':site_id', $site_id);
            $siteStmt->execute();
            $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
            
            $insertQuery = "INSERT INTO lecturer_site (lecturer_id, site_id, assigned_date) VALUES (:lecturer_id, :site_id, CURDATE())";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bindParam(':lecturer_id', $lecturer_id);
            $insertStmt->bindParam(':site_id', $site_id);
            
            if ($insertStmt->execute()) {
                $notification = new Notification($conn);
                
                $subject = "Clinical Site Assignment - Daeyang University";
                $emailMessage = "
                    <h3>Dear {$lecturer['name']},</h3>
                    <p>You have been assigned to a clinical site for final assessments:</p>
                    <table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>
                        <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Clinical Site:</strong></td>
                            <td style='padding: 8px;'>{$site['name']}</td>
                        </tr>
                        <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Location:</strong></td>
                            <td style='padding: 8px;'>{$site['location']}</td>
                        </tr>
                    </table>
                    <p>Please log in to your dashboard to conduct final assessments for students at this site.</p>
                    <p>Best regards,<br><strong>Nursing Department</strong><br>Daeyang University</p>
                ";
                
                $emailSent = $notification->sendEmail($lecturer['email'], $subject, $emailMessage);
                $notification->saveNotification(
                    $lecturer_id,
                    'lecturer',
                    'Clinical Site Assignment',
                    "You have been assigned to {$site['name']} for final assessments.",
                    $site_id
                );
                
                $message = "Lecturer assigned to clinical site successfully! Notification " . ($emailSent ? "sent." : "pending (in-app sent).");
            } else {
                $error = "Failed to assign lecturer.";
            }
        }
    }
    
    // Handle remove lecturer from site
    if (isset($_POST['remove_lecturer_site'])) {
        $assignment_id = $_POST['assignment_id'];
        
        $assignmentQuery = "SELECT ls.lecturer_id, l.name, l.email, cs.name as site_name 
                           FROM lecturer_site ls
                           JOIN lecturer l ON ls.lecturer_id = l.lecturer_id
                           JOIN clinical_site cs ON ls.site_id = cs.site_id
                           WHERE ls.id = :assignment_id";
        $assignmentStmt = $conn->prepare($assignmentQuery);
        $assignmentStmt->bindParam(':assignment_id', $assignment_id);
        $assignmentStmt->execute();
        $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
        
        $deleteQuery = "DELETE FROM lecturer_site WHERE id = :assignment_id";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bindParam(':assignment_id', $assignment_id);
        
        if ($deleteStmt->execute()) {
            if ($assignment) {
                $notification = new Notification($conn);
                $notification->saveNotification(
                    $assignment['lecturer_id'],
                    'lecturer',
                    'Clinical Site Assignment Removed',
                    "You have been removed from {$assignment['site_name']}. Please contact the coordinator if you have questions.",
                    null
                );
            }
            $message = "Lecturer removed from site successfully!";
        } else {
            $error = "Failed to remove lecturer.";
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
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
            margin-top: 20px;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
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
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.3s;
            font-weight: 500;
            font-size: 1rem;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #654321;
        }
        
        .btn-secondary {
            background: #c3a343;
            color: #4a2f1a;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        
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
        
        .data-table td {
            vertical-align: middle;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
        }
        
        .btn-delete:hover {
            background: #c82333;
        }
        
        .btn-archive-student {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            margin-right: 5px;
        }
        
        .btn-archive-student:hover {
            background: #e0a800;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
        }
        
        .data-table-container {
            display: none;
            margin-top: 20px;
        }
        
        .data-table-container.visible {
            display: block;
        }
        
        .info-box {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #c3a343;
        }
        
        .info-box p {
            margin: 5px 0;
            font-size: 0.85rem;
        }
        
        .badge-assigned {
            background: #d4edda;
            color: #155724;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
        }
        
        .badge-unassigned {
            background: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
        }
        
        /* ===== SITES SUMMARY STATS ===== */
        .sites-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .summary-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .summary-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #4a2f1a;
        }
        .summary-label {
            font-size: 0.75rem;
            color: #888;
            margin-top: 2px;
        }

        /* ===== SITES GRID ===== */
        .sites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .site-card-modern {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 0;
            overflow: hidden;
            border: 1px solid #e8e8e8;
            transition: 0.3s;
        }
        .site-card-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        .site-card-header {
            background: white;
            padding: 16px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
        }
        .site-card-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #4a2f1a;
            font-size: 0.95rem;
        }
        .util-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-empty { background: #e9ecef; color: #6c757d; }

        .site-card-body {
            padding: 16px 18px;
        }
        .site-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: #555;
        }
        .capacity-section {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
        }
        .capacity-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .capacity-text {
            font-size: 0.8rem;
            color: #666;
        }
        .capacity-bar-modern {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        .capacity-fill-modern {
            height: 100%;
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        .util-good { background: #28a745; }
        .util-warning { background: #ffc107; }
        .util-danger { background: #dc3545; }
        .util-empty { background: #ccc; }

        .site-card-footer {
            padding: 12px 18px;
            background: white;
            border-top: 1px solid #eee;
            display: flex;
            gap: 8px;
        }
        .view-students-btn {
            flex: 1;
            background: #4a2f1a;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .view-students-btn:hover {
            background: #654321;
        }
        .delete-site-btn {
            background: #f8d7da;
            color: #dc3545;
            border: none;
            width: 40px;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
            font-size: 0.9rem;
        }
        .delete-site-btn:hover {
            background: #dc3545;
            color: white;
        }

        /* ===== SITE STUDENTS LIST ===== */
        .site-students-list {
            display: none;
            border-top: 1px solid #eee;
            background: white;
        }
        .site-students-list.visible {
            display: block;
        }
        .students-list-header {
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            background: #f8f9fa;
        }
        .students-list-title {
            font-weight: 600;
            color: #4a2f1a;
            font-size: 0.85rem;
        }
        .students-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        .students-table th {
            background: #f0f0f0;
            color: #4a2f1a;
            padding: 10px;
            text-align: left;
            font-weight: 600;
        }
        .students-table td {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        .students-table tr:last-child td {
            border-bottom: none;
        }
        .students-table tr:hover {
            background: #f5f5f5;
        }
        .role-tag {
            background: #c3a343;
            color: #4a2f1a;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
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
            .sites-grid { grid-template-columns: 1fr; }
            .sites-summary { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <div class="header">
        <h1>Daeyang University - Nursing Allocation System</h1>
        <div class="user-info">
            <span>Welcome, <?php echo cleanDisplay(htmlspecialchars($_SESSION['name'])); ?></span>
            <span class="role-badge">Coordinator</span>
            <a href="actions/logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <a href="?tab=sites" class="nav-tab <?php echo $active_tab == 'sites' ? 'active' : ''; ?>">Clinical Sites</a>
        <a href="coordinator_students.php" class="nav-tab">Manage Users</a>
        <a href="upload_students.php" class="nav-tab">Bulk Upload</a>
        <a href="auto_allocate.php" class="nav-tab">Auto Allocate</a>
        <a href="?tab=assign" class="nav-tab <?php echo $active_tab == 'assign' ? 'active' : ''; ?>">Assign Staff</a>
        <a href="coordinator_reports.php" class="nav-tab">Reports</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success-msg"><?php echo cleanDisplay(htmlspecialchars($message)); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo cleanDisplay(htmlspecialchars($error)); ?></div>
        <?php endif; ?>
        
        <!-- Clinical Sites Section -->
        <div id="sitesSection" class="content-section <?php echo $active_tab == 'sites' ? 'active' : ''; ?>">
            <!-- Summary Stats -->
            <?php
            $allSites = $coordinator->getSites();
            $allAllocs = $coordinator->getAllocationsWithDaysRemaining();
            $totalCapacity = array_sum(array_column($allSites, 'max_students'));
            $totalAssigned = count($allAllocs);
            $sitesWithStudents = [];
            foreach ($allAllocs as $alloc) {
                if (!isset($sitesWithStudents[$alloc['site_id']])) {
                    $sitesWithStudents[$alloc['site_id']] = 0;
                }
                $sitesWithStudents[$alloc['site_id']]++;
            }
            ?>
            <div class="sites-summary">
                <div class="summary-card">
                    <div class="summary-icon" style="background:#e8f5e9;">
                        <i class="fas fa-hospital" style="color:#28a745;font-size:1.5rem;" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="summary-number"><?php echo count($allSites); ?></div>
                        <div class="summary-label">Clinical Sites</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="background:#e3f2fd;">
                        <i class="fas fa-users" style="color:#1565c0;font-size:1.5rem;" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="summary-number"><?php echo $totalAssigned; ?> / <?php echo $totalCapacity; ?></div>
                        <div class="summary-label">Students Placed</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="background:#fff3cd;">
                        <i class="fas fa-chair" style="color:#856404;font-size:1.5rem;" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="summary-number"><?php echo $totalCapacity - $totalAssigned; ?></div>
                        <div class="summary-label">Available Slots</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="background:#fce4ec;">
                        <i class="fas fa-chart-pie" style="color:#d32f2f;font-size:1.5rem;" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="summary-number"><?php echo $totalCapacity > 0 ? round(($totalAssigned / $totalCapacity) * 100) : 0; ?>%</div>
                        <div class="summary-label">Utilization Rate</div>
                    </div>
                </div>
            </div>

            <!-- Add New Site Form -->
            <div class="card">
                <h2 style="display:flex;align-items:center;gap:10px;">
                    <span style="background:#4a2f1a;color:white;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;">+</span>
                    Add New Clinical Site
                </h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Site Name</label>
                            <input type="text" name="name" placeholder="e.g. Lilongwe Central Hospital" required>
                        </div>
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" placeholder="e.g. Area 33, Lilongwe" required>
                        </div>
                        <div class="form-group">
                            <label>Contact Person</label>
                            <input type="text" name="contact_person" placeholder="Full name of contact">
                        </div>
                        <div class="form-group">
                            <label>Contact Phone</label>
                            <input type="text" name="contact_phone" placeholder="+265 XXX XXX XXX">
                        </div>
                        <div class="form-group">
                            <label>Max Capacity</label>
                            <input type="number" name="capacity" placeholder="Max students" value="10" min="1">
                        </div>
                    </div>
                    <button type="submit" name="add_site" class="btn-primary">Add Clinical Site</button>
                </form>
            </div>

            <!-- Sites Dashboard Grid -->
            <div class="card">
                <h2 style="display:flex;align-items:center;gap:10px;">
                    <span style="background:#4a2f1a;color:white;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;">&#9776;</span>
                    Clinical Sites Overview
                </h2>
                
                <?php if (count($allSites) > 0): ?>
                <div class="sites-grid">
                    <?php foreach ($allSites as $site):
                        $siteId = $site['site_id'];
                        $current = $sitesWithStudents[$siteId] ?? 0;
                        $capacity = (int)$site['max_students'];
                        $utilPercent = $capacity > 0 ? round(($current / $capacity) * 100) : 0;
                        
                        if ($utilPercent >= 90) {
                            $barClass = 'util-danger';
                            $badgeClass = 'badge-danger';
                            $utilText = 'Nearly Full';
                        } elseif ($utilPercent >= 70) {
                            $barClass = 'util-warning';
                            $badgeClass = 'badge-warning';
                            $utilText = 'Almost Full';
                        } elseif ($utilPercent > 0) {
                            $barClass = 'util-good';
                            $badgeClass = 'badge-success';
                            $utilText = 'Available';
                        } else {
                            $barClass = 'util-empty';
                            $badgeClass = 'badge-empty';
                            $utilText = 'No Students';
                        }
                    ?>
                    <div class="site-card-modern">
                        <div class="site-card-header">
                            <div class="site-card-name">
                                <i class="fas fa-hospital" aria-hidden="true" style="color:#4a2f1a;"></i>
                                <span><?php echo cleanDisplay(htmlspecialchars($site['name'])); ?></span>
                            </div>
                            <span class="util-badge <?php echo $badgeClass; ?>"><?php echo $utilText; ?></span>
                        </div>
                        
                        <div class="site-card-body">
                            <div class="site-detail">
                                <i class="fas fa-map-marker-alt" aria-hidden="true" style="color:#888;width:18px;"></i>
                                <span><?php echo cleanDisplay(htmlspecialchars($site['location'] ?: 'Location not set')); ?></span>
                            </div>
                            <div class="site-detail">
                                <i class="fas fa-user" aria-hidden="true" style="color:#888;width:18px;"></i>
                                <span><?php echo cleanDisplay(htmlspecialchars($site['contact_person'] ?: 'No contact')); ?></span>
                            </div>
                            <div class="site-detail">
                                <i class="fas fa-phone" aria-hidden="true" style="color:#888;width:18px;"></i>
                                <span><?php echo cleanDisplay(htmlspecialchars($site['contact_phone'] ?: 'No phone')); ?></span>
                            </div>
                            
                            <div class="capacity-section">
                                <div class="capacity-info">
                                    <span class="capacity-text">
                                        <strong><?php echo $current; ?></strong> / <?php echo $capacity; ?> students
                                    </span>
                                    <span class="capacity-text"><?php echo $utilPercent; ?>%</span>
                                </div>
                                <div class="capacity-bar-modern">
                                    <div class="capacity-fill-modern <?php echo $barClass; ?>" style="width:<?php echo $utilPercent; ?>%;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="site-card-footer">
                            <button class="view-students-btn" onclick="toggleSiteStudents(<?php echo $siteId; ?>)">
                                <i class="fas fa-eye" aria-hidden="true"></i> View Students (<?php echo $current; ?>)
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="site_id" value="<?php echo $siteId; ?>">
                                <button type="submit" name="delete_site" class="delete-site-btn" onclick="return confirm('Delete this site? This will also remove all associated data.')">
                                    <i class="fas fa-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                        
                        <!-- Students List (hidden by default) -->
                        <div id="site-students-<?php echo $siteId; ?>" class="site-students-list">
                            <div class="students-list-header">
                                <span class="students-list-title">Students at this Site</span>
                                <button onclick="toggleSiteStudents(<?php echo $siteId; ?>)" style="background:none;border:none;cursor:pointer;font-size:1rem;color:#999;">&times;</button>
                            </div>
                            <?php
                            $siteStudents = array_filter($allAllocs, function($a) use ($siteId) {
                                return $a['site_id'] == $siteId;
                            });
                            ?>
                            <?php if (count($siteStudents) > 0): ?>
                                <table class="students-table">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Number</th>
                                            <th>Role</th>
                                            <th>Period</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($siteStudents as $stu): ?>
                                        <tr>
                                            <td><?php echo cleanDisplay(htmlspecialchars($stu['student_name'] ?? '')); ?></td>
                                            <td><?php echo cleanDisplay(htmlspecialchars($stu['student_number'] ?? '')); ?></td>
                                            <td><span class="role-tag"><?php echo cleanDisplay(htmlspecialchars($stu['role'])); ?></span></td>
                                            <td><?php echo date('M d', strtotime($stu['start_date'])); ?> - <?php echo date('M d', strtotime($stu['end_date'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div style="text-align:center;padding:20px;color:#999;font-size:0.85rem;">
                                    No students currently placed at this site.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div style="text-align:center;padding:40px;color:#999;">
                        <i class="fas fa-hospital" style="font-size:2rem;margin-bottom:10px;display:block;" aria-hidden="true"></i>
                        No clinical sites yet. Add your first site above.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Manage Students Section -->
        <div id="studentsSection" class="content-section <?php echo $active_tab == 'students' ? 'active' : ''; ?>">
            <div class="info-box">
                <p><strong>How to add students:</strong> Use the <strong>Bulk Upload</strong> tab to add multiple students via CSV file.</p>
                <p><strong>Student Management:</strong> View each student, their clinical placement, and archive or delete records from the list below.</p>
            </div>
            
            <div class="action-bar">
                <div class="action-buttons">
                    <button class="btn-view" onclick="toggleView('studentsTable')">View Students</button>
                </div>
                <span>Click View to see all students and their assigned clinical sites</span>
            </div>

            <?php
            $students = $coordinator->getStudents();
            $activeAllocations = $coordinator->getAllocationsWithDaysRemaining();
            $allocationByStudent = [];
            $studentFilterSites = [];
            $studentFilterYears = [];
            $studentFilterCohorts = [];

            foreach ($activeAllocations as $allocation) {
                $allocationByStudent[$allocation['student_id']] = $allocation;
                if (!empty($allocation['site_name'])) {
                    $studentFilterSites[$allocation['site_name']] = $allocation['site_name'];
                }
            }

            foreach ($students as $student) {
                if (!empty($student['year_of_study'])) {
                    $studentFilterYears[$student['year_of_study']] = $student['year_of_study'];
                }
                if (!empty($student['cohort'])) {
                    $studentFilterCohorts[$student['cohort']] = $student['cohort'];
                }
            }

            natcasesort($studentFilterSites);
            ksort($studentFilterYears);
            rsort($studentFilterCohorts);
            ?>
            
            <div id="studentsTable" class="data-table-container">
                <div class="filter-panel">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="studentSearch">Search Student</label>
                            <input type="text" id="studentSearch" placeholder="Name, number, email, site">
                        </div>
                        <div class="form-group">
                            <label for="studentSiteFilter">Clinical Site</label>
                            <select id="studentSiteFilter">
                                <option value="">All sites</option>
                                <?php foreach ($studentFilterSites as $siteName): ?>
                                    <option value="<?php echo cleanDisplay(htmlspecialchars($siteName)); ?>"><?php echo cleanDisplay(htmlspecialchars($siteName)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="studentAssignmentFilter">Assignment</label>
                            <select id="studentAssignmentFilter">
                                <option value="">All students</option>
                                <option value="assigned">Assigned</option>
                                <option value="unassigned">Not assigned</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="studentYearFilter">Year</label>
                            <select id="studentYearFilter">
                                <option value="">All years</option>
                                <?php foreach ($studentFilterYears as $year): ?>
                                    <option value="<?php echo cleanDisplay(htmlspecialchars($year)); ?>">Year <?php echo cleanDisplay(htmlspecialchars($year)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="studentCohortFilter">Cohort</label>
                            <select id="studentCohortFilter">
                                <option value="">All cohorts</option>
                                <?php foreach ($studentFilterCohorts as $cohort): ?>
                                    <option value="<?php echo cleanDisplay(htmlspecialchars($cohort)); ?>"><?php echo cleanDisplay(htmlspecialchars($cohort)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <span id="studentFilterCount"><?php echo count($students); ?> students shown</span>
                        <button type="button" class="btn-secondary" onclick="resetStudentFilters()">Reset Filters</button>
                    </div>
                </div>
                <div class="card" style="padding: 0; overflow: hidden; overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Number</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Cohort</th>
                                <th>Year</th>
                                <th>Mode of Entry</th>
                                <th>Assigned Site</th>
                                <th>Role</th>
                                <th>Placement Period</th>
                                <th>Placement Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($students as $student): 
                                $studentAllocation = $allocationByStudent[$student['student_id']] ?? null;
                                $placementSite = $studentAllocation['site_name'] ?? 'Not assigned';
                                $placementRole = $studentAllocation['role'] ?? '-';
                                $placementStatus = $studentAllocation['placement_status'] ?? ($studentAllocation['status'] ?? 'Active');
                                $studentAssignmentState = $studentAllocation ? 'assigned' : 'unassigned';
                                $studentSearchText = strtolower(trim(
                                    ($student['student_number'] ?? '') . ' ' .
                                    ($student['name'] ?? '') . ' ' .
                                    ($student['email'] ?? '') . ' ' .
                                    ($student['cohort'] ?? '') . ' ' .
                                    ($student['year_of_study'] ?? '') . ' ' .
                                    ($student['mode_of_entry'] ?? '') . ' ' .
                                    $placementSite . ' ' .
                                    $placementRole . ' ' .
                                    $placementStatus
                                ));
                            ?>
                            <tr class="student-row"
                                data-search="<?php echo htmlspecialchars($studentSearchText, ENT_QUOTES); ?>"
                                data-site="<?php echo htmlspecialchars(cleanDisplay($placementSite), ENT_QUOTES); ?>"
                                data-assignment="<?php echo $studentAssignmentState; ?>"
                                data-year="<?php echo htmlspecialchars(cleanDisplay($student['year_of_study'] ?? ''), ENT_QUOTES); ?>"
                                data-cohort="<?php echo htmlspecialchars(cleanDisplay($student['cohort'] ?? ''), ENT_QUOTES); ?>">
                                <td><?php echo cleanDisplay(htmlspecialchars($student['student_number'])); ?></td>
                                <td><?php echo cleanDisplay(htmlspecialchars($student['name'])); ?></td>
                                <td><?php echo cleanDisplay(htmlspecialchars($student['email'])); ?></td>
                                <td><?php echo cleanDisplay(htmlspecialchars($student['cohort'])); ?></td>
                                <td><?php echo cleanDisplay(htmlspecialchars($student['year_of_study'] ?? 'N/A')); ?></td>
                                <td><?php echo cleanDisplay(htmlspecialchars($student['mode_of_entry'] ?? 'Generic')); ?></td>
                                <td>
                                    <?php echo cleanDisplay(htmlspecialchars($placementSite)); ?>
                                </td>
                                <td>
                                    <?php echo cleanDisplay(htmlspecialchars($placementRole)); ?>
                                </td>
                                <td>
                                    <?php if ($studentAllocation): ?>
                                        <?php echo date('M d, Y', strtotime($studentAllocation['start_date'])); ?> - <?php echo date('M d, Y', strtotime($studentAllocation['end_date'])); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($studentAllocation): ?>
                                        <span class="badge-assigned"><?php echo cleanDisplay(htmlspecialchars($placementStatus)); ?></span>
                                    <?php else: ?>
                                        <span class="badge-unassigned">Not Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline-block; margin-right: 5px;">
                                        <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                        <button type="submit" name="archive_student" class="btn-archive-student" onclick="return confirm('Archive this student?')">Archive</button>
                                    </form>
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                        <button type="submit" name="delete_student" class="btn-delete" onclick="return confirm('Delete this student permanently?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if (!empty($students)): ?>
                            <tr id="studentsFilterEmpty" style="display: none;">
                                <td colspan="11" class="no-data">No students match the selected filters.</td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="11" class="no-data">No students found. Use Bulk Upload to add students.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Assign Staff Section -->
        <div id="assignSection" class="content-section <?php echo $active_tab == 'assign' ? 'active' : ''; ?>">
            
            <!-- Assign Matron to Site -->
            <div class="card">
                <h2>Assign Matron to Clinical Site</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Select Matron</label>
                            <select name="matron_id" required>
                                <option value="">-- Select Matron --</option>
                                <?php
                                $matronsQuery = "SELECT matron_id, name, email, site_id FROM matron ORDER BY name";
                                $matronsStmt = $conn->prepare($matronsQuery);
                                $matronsStmt->execute();
                                $matrons = $matronsStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($matrons as $matron):
                                    $status = $matron['site_id'] ? ' (Assigned)' : ' (Unassigned)';
                                ?>
                                    <option value="<?php echo $matron['matron_id']; ?>">
                                        <?php echo cleanDisplay(htmlspecialchars($matron['name'])); ?> (<?php echo cleanDisplay(htmlspecialchars($matron['email'])); ?>)<?php echo $status; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Select Clinical Site</label>
                            <select name="site_id" required>
                                <option value="">-- Select Site --</option>
                                <?php foreach ($coordinator->getSites() as $site): ?>
                                    <option value="<?php echo $site['site_id']; ?>">
                                        <?php echo cleanDisplay(htmlspecialchars($site['name'])); ?> (<?php echo cleanDisplay(htmlspecialchars($site['location'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="assign_matron" class="btn-primary">Assign Matron to Site</button>
                </form>
            </div>
            
            <!-- Assign Lecturer to Site -->
            <div class="card">
                <h2>Assign Lecturer to Clinical Site</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Select Lecturer</label>
                            <select name="lecturer_id" required>
                                <option value="">-- Select Lecturer --</option>
                                <?php
                                $lecturersQuery = "SELECT lecturer_id, name, email FROM lecturer ORDER BY name";
                                $lecturersStmt = $conn->prepare($lecturersQuery);
                                $lecturersStmt->execute();
                                $lecturers = $lecturersStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($lecturers as $lecturer):
                                ?>
                                    <option value="<?php echo $lecturer['lecturer_id']; ?>">
                                        <?php echo cleanDisplay(htmlspecialchars($lecturer['name'])); ?> (<?php echo cleanDisplay(htmlspecialchars($lecturer['email'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Select Clinical Site</label>
                            <select name="site_id" required>
                                <option value="">-- Select Site --</option>
                                <?php foreach ($coordinator->getSites() as $site): ?>
                                    <option value="<?php echo $site['site_id']; ?>">
                                        <?php echo cleanDisplay(htmlspecialchars($site['name'])); ?> (<?php echo cleanDisplay(htmlspecialchars($site['location'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="assign_lecturer" class="btn-primary">Assign Lecturer to Site</button>
                </form>
            </div>
            
        </div>
    </div>
    
    <script>
        function toggleView(tableId) {
            var table = document.getElementById(tableId);
            if (table.classList.contains('visible')) {
                table.classList.remove('visible');
            } else {
                table.classList.add('visible');
            }
        }

        function filterStudents() {
            var searchInput = document.getElementById('studentSearch');
            var siteFilter = document.getElementById('studentSiteFilter');
            var assignmentFilter = document.getElementById('studentAssignmentFilter');
            var yearFilter = document.getElementById('studentYearFilter');
            var cohortFilter = document.getElementById('studentCohortFilter');
            var countLabel = document.getElementById('studentFilterCount');
            var emptyRow = document.getElementById('studentsFilterEmpty');
            var rows = document.querySelectorAll('#studentsTable .student-row');

            if (!searchInput || !siteFilter || !assignmentFilter || !yearFilter || !cohortFilter) {
                return;
            }

            var searchValue = searchInput.value.trim().toLowerCase();
            var siteValue = siteFilter.value;
            var assignmentValue = assignmentFilter.value;
            var yearValue = yearFilter.value;
            var cohortValue = cohortFilter.value;
            var visibleCount = 0;

            rows.forEach(function(row) {
                var matchesSearch = !searchValue || row.dataset.search.indexOf(searchValue) !== -1;
                var matchesSite = !siteValue || row.dataset.site === siteValue;
                var matchesAssignment = !assignmentValue || row.dataset.assignment === assignmentValue;
                var matchesYear = !yearValue || row.dataset.year === yearValue;
                var matchesCohort = !cohortValue || row.dataset.cohort === cohortValue;
                var isVisible = matchesSearch && matchesSite && matchesAssignment && matchesYear && matchesCohort;

                row.style.display = isVisible ? '' : 'none';
                if (isVisible) {
                    visibleCount++;
                }
            });

            if (countLabel) {
                countLabel.textContent = visibleCount + (visibleCount === 1 ? ' student shown' : ' students shown');
            }
            if (emptyRow) {
                emptyRow.style.display = visibleCount === 0 ? '' : 'none';
            }
        }

        function resetStudentFilters() {
            var filterIds = ['studentSearch', 'studentSiteFilter', 'studentAssignmentFilter', 'studentYearFilter', 'studentCohortFilter'];
            filterIds.forEach(function(id) {
                var field = document.getElementById(id);
                if (field) {
                    field.value = '';
                }
            });
            filterStudents();
        }
        
        function toggleSiteStudents(siteId) {
            var el = document.getElementById('site-students-' + siteId);
            if (el.classList.contains('visible')) {
                el.classList.remove('visible');
            } else {
                el.classList.add('visible');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var tables = document.querySelectorAll('.data-table-container');
            tables.forEach(function(table) {
                table.classList.remove('visible');
            });

            ['studentSearch', 'studentSiteFilter', 'studentAssignmentFilter', 'studentYearFilter', 'studentCohortFilter'].forEach(function(id) {
                var field = document.getElementById(id);
                if (field) {
                    field.addEventListener('input', filterStudents);
                    field.addEventListener('change', filterStudents);
                }
            });

            filterStudents();
        });
    </script>
    </body>
</html>
