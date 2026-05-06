<?php
session_start();

// Get login data
$regNumber = $_POST['regNumber'] ?? '';
$password = $_POST['password'] ?? '';

// Role mapping based on Registration ID
$roleMapping = [
    'COORD001' => [
        'role' => 'coordinator', 
        'dashboard' => '/nursing_allocation_system/coordinator_Dashboard.php', 
        'name' => 'Admin Coordinator'
    ],
    'LECT001' => [
        'role' => 'lecturer', 
        'dashboard' => '/nursing_allocation_system/lecturer_Dashboard.php', 
        'name' => 'Dr. Banda'
    ],
    'STU001' => [
        'role' => 'student', 
        'dashboard' => '/nursing_allocation_system/student_Dashboard.php', 
        'name' => 'Grace Banda'
    ]
];

// Check if registration ID exists
if (isset($roleMapping[$regNumber])) {
    $roleData = $roleMapping[$regNumber];
    
    // Check password (demo: 'pass' works)
    if ($password == 'pass') {
        $_SESSION['user_id'] = $regNumber;
        $_SESSION['regNumber'] = $regNumber;
        $_SESSION['name'] = $roleData['name'];
        $_SESSION['role'] = $roleData['role'];
        
        // Redirect to correct dashboard
        header("Location: " . $roleData['dashboard']);
        exit();
    } else {
        $_SESSION['error'] = "Invalid password. Use 'pass' for demo.";
        header("Location: /nursing_allocation_system/login.php");
        exit();
    }
} else {
    $_SESSION['error'] = "Invalid Registration ID. Use COORD001, LECT001, or STU001.";
    header("Location: /nursing_allocation_system/login.php");
    exit();
}
?>