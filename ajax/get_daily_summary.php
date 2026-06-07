<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/Lecturer.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    exit('Unauthorized');
}

$student_id = $_GET['student_id'] ?? 0;
$site_id = $_GET['site_id'] ?? 0;

$db = new Database();
$conn = $db->getConnection();
$lecturer = new Lecturer($_SESSION['user_id']);

$summary = $lecturer->getStudentDailySummary($student_id, $site_id);
header('Content-Type: application/json');
echo json_encode($summary);
?>