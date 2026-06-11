<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../classes/Database.php';
require_once '../classes/Notification.php';

header('Content-Type: application/json');

if (!isset($_SESSION['pending_emails']) || empty($_SESSION['pending_emails'])) {
    echo json_encode(['success' => false, 'message' => 'No pending emails']);
    exit;
}

// Remove time limit to prevent timeout during email sending
set_time_limit(0);
// Allow it to run even if the user closes the browser
ignore_user_abort(true);

$emails_to_send = $_SESSION['pending_emails'];
// Immediately clear from session so we don't accidentally send twice
unset($_SESSION['pending_emails']);

try {
    $database = new Database();
    if (method_exists($database, 'getConnection')) {
        $conn = $database->getConnection();
    } elseif (method_exists($database, 'connect')) {
        $conn = $database->connect();
    } else {
        $conn = new PDO("mysql:host=localhost;dbname=nursing_allocation", "root", "");
    }
    
    $notification = new Notification($conn);
    
    $sent_count = 0;
    
    foreach ($emails_to_send as $email_data) {
        try {
            $notification->sendAllocationNotification(
                $email_data['student_id'], 
                $email_data['email'], 
                $email_data['name'],
                $email_data['site_name'], 
                $email_data['start_date'], 
                $email_data['end_date'], 
                $email_data['role'], 
                'email_only'
            );
            $sent_count++;
        } catch (Exception $e) {
            // Ignore individual email errors
        }
    }
    
    echo json_encode(['success' => true, 'sent' => $sent_count]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
