<?php
session_start();
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';
$step = 'request';

// Handle token verification when user clicks email link
if (isset($_GET['token']) && isset($_GET['email']) && isset($_GET['role'])) {
    $token = $_GET['token'];
    $email = $_GET['email'];
    $role = $_GET['role'];
    
    $verifyStmt = $conn->prepare("
        SELECT * FROM password_resets 
        WHERE token = :token AND email = :email AND role = :role 
        AND expires_at > NOW() AND used = 0
    ");
    $verifyStmt->bindParam(':token', $token);
    $verifyStmt->bindParam(':email', $email);
    $verifyStmt->bindParam(':role', $role);
    $verifyStmt->execute();
    $resetRecord = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($resetRecord) {
        $step = 'reset';
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_role'] = $role;
        $_SESSION['reset_token'] = $token;
    } else {
        $error = "Invalid or expired reset link. Please request a new password reset.";
        $step = 'request';
    }
}

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $email = $_SESSION['reset_email'] ?? '';
    $role = $_SESSION['reset_role'] ?? '';
    $token = $_SESSION['reset_token'] ?? '';
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $verifyStmt = $conn->prepare("
            SELECT * FROM password_resets 
            WHERE token = :token AND email = :email AND role = :role 
            AND expires_at > NOW() AND used = 0
        ");
        $verifyStmt->bindParam(':token', $token);
        $verifyStmt->bindParam(':email', $email);
        $verifyStmt->bindParam(':role', $role);
        $verifyStmt->execute();
        $resetRecord = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resetRecord) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $table = '';
            switch($role) {
                case 'student': $table = 'student'; break;
                case 'lecturer': $table = 'lecturer'; break;
                case 'matron': $table = 'matron'; break;
            }
            
            $updateStmt = $conn->prepare("UPDATE $table SET password_hash = :password WHERE email = :email");
            $updateStmt->bindParam(':password', $hashed_password);
            $updateStmt->bindParam(':email', $email);
            
            if ($updateStmt->execute()) {
                $useStmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = :token");
                $useStmt->bindParam(':token', $token);
                $useStmt->execute();
                
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_role']);
                unset($_SESSION['reset_token']);
                
                $message = "Password has been reset successfully! You can now login with your new password.";
                $step = 'complete';
            } else {
                $error = "Failed to reset password. Please try again.";
            }
        } else {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - Daeyang University</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #654321 0%, #4a2f1a 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .reset-container { max-width: 450px; width: 100%; background: white; border-radius: 16px; padding: 35px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .logo { text-align: center; margin-bottom: 25px; }
        .logo h1 { color: #4a2f1a; font-size: 1.5rem; }
        .logo p { color: #c3a343; font-size: 0.8rem; }
        h2 { color: #4a2f1a; margin-bottom: 10px; font-size: 1.5rem; }
        .subtitle { color: #666; font-size: 0.85rem; margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #4a2f1a; }
        input, select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; }
        input:focus, select:focus { outline: none; border-color: #c3a343; }
        .btn-primary { width: 100%; background: #4a2f1a; color: white; padding: 12px; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .btn-primary:hover { background: #654321; }
        .success-msg { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745; text-align: center; }
        .error-msg { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545; text-align: center; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #c3a343; text-decoration: none; }
        .password-requirements { font-size: 0.7rem; color: #999; margin-top: 5px; }
        @media (max-width: 480px) { .reset-container { padding: 25px; } }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="logo">
            <h1>Daeyang University</h1>
            <p>Nursing Clinical Allocation System</p>
        </div>
        
        <?php if ($step == 'request'): ?>
            <h2>Invalid or Expired Link</h2>
            <p class="subtitle">This password reset link is invalid or has expired.</p>
            
            <?php if ($error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="back-link">
                <a href="forgot_password.php">← Request a new reset link</a>
            </div>
            
        <?php elseif ($step == 'reset'): ?>
            <h2>Reset Password</h2>
            <p class="subtitle">Enter your new password below.</p>
            
            <?php if ($error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="success-msg"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                    <div class="password-requirements">Password must be at least 6 characters</div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" name="reset_password" class="btn-primary">Reset Password</button>
            </form>
            
        <?php elseif ($step == 'complete'): ?>
            <div class="success-msg">
                <strong>✓ Password Reset Successful!</strong><br>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <div class="back-link">
                <a href="login.php">Click here to login →</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>