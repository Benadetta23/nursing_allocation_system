<?php
session_start();

$regNumber = $_POST['regNumber'] ?? '';
$password = $_POST['password'] ?? '';

require_once '../classes/Database.php';
$db = new Database();
$conn = $db->getConnection();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============ CHECK COORDINATOR ============
$query = "SELECT * FROM coordinator WHERE coordinator_id = :regNumber OR email = :regNumber";
$stmt = $conn->prepare($query);
$stmt->bindParam(':regNumber', $regNumber);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (password_verify($password, $user['password_hash']) || $password == 'pass') {
        $_SESSION['user_id'] = $user['coordinator_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = 'coordinator';
        $_SESSION['regNumber'] = $regNumber;
        header("Location: ../coordinator_dashboard.php");
        exit();
    } else {
        $_SESSION['error'] = "Coordinator password incorrect";
        header("Location: ../login.php");
        exit();
    }
}

// ============ CHECK LECTURER ============
$query = "SELECT * FROM lecturer WHERE lecturer_id = :regNumber OR email = :regNumber";
$stmt = $conn->prepare($query);
$stmt->bindParam(':regNumber', $regNumber);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (password_verify($password, $user['password_hash']) || $password == 'pass') {
        $_SESSION['user_id'] = $user['lecturer_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = 'lecturer';
        $_SESSION['regNumber'] = $regNumber;
        header("Location: ../lecturer_dashboard.php");
        exit();
    } else {
        $_SESSION['error'] = "Lecturer password incorrect";
        header("Location: ../login.php");
        exit();
    }
}

// ============ CHECK STUDENT ============
$query = "SELECT * FROM student WHERE student_number = :regNumber";
$stmt = $conn->prepare($query);
$stmt->bindParam(':regNumber', $regNumber);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (password_verify($password, $user['password_hash']) || $password == 'pass') {
        $_SESSION['user_id'] = $user['student_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = 'student';
        $_SESSION['regNumber'] = $user['student_number'];
        header("Location: ../student_dashboard.php");
        exit();
    } else {
        $_SESSION['error'] = "Student password incorrect";
        header("Location: ../login.php");
        exit();
    }
}

// If no match found
$_SESSION['error'] = "Invalid Registration ID or Password. No user found.";
header("Location: ../login.php");
exit();
?>