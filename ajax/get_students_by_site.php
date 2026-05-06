<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'lecturer') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../classes/Lecturer.php';

// Get lecturer ID
$email = $_SESSION['email'] ?? 'lecturer@daeyang.edu';
$db = new Database();
$conn = $db->getConnection();
$query = "SELECT lecturer_id FROM lecturer WHERE email = :email";
$stmt = $conn->prepare($query);
$stmt->bindParam(':email', $email);
$stmt->execute();
$lecturerData = $stmt->fetch(PDO::FETCH_ASSOC);

$lecturer_id = $lecturerData ? $lecturerData['lecturer_id'] : 1;
$lecturer = new Lecturer($lecturer_id);

$site_id = $_GET['site_id'] ?? 0;

if ($site_id) {
    $students = $lecturer->getStudentsBySite($site_id);
    echo json_encode($students);
} else {
    echo json_encode([]);
}
?>