<?php
session_start();
require_once 'classes/Database.php';
require_once 'classes/Notification.php';

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';
$step = 'request';

// Handle password reset request - SEND EMAIL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_reset'])) {
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    
    $table = '';
    $nameField = 'name';
    switch($role) {
        case 'student':
            $table = 'student';
            break;
        case 'lecturer':
            $table = 'lecturer';
            break;
        case 'matron':
            $table = 'matron';
            break;
        default:
            $error = "Invalid user type.";
    }
    
    if ($table && !$error) {
        $checkStmt = $conn->prepare("SELECT * FROM $table WHERE email = :email");
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Generate unique token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Delete old tokens
            $deleteStmt = $conn->prepare("DELETE FROM password_resets WHERE email = :email AND role = :role");
            $deleteStmt->bindParam(':email', $email);
            $deleteStmt->bindParam(':role', $role);
            $deleteStmt->execute();
            
            // Insert new token
            $insertStmt = $conn->prepare("
                INSERT INTO password_resets (email, token, role, expires_at) 
                VALUES (:email, :token, :role, :expires)
            ");
            $insertStmt->bindParam(':email', $email);
            $insertStmt->bindParam(':token', $token);
            $insertStmt->bindParam(':role', $role);
            $insertStmt->bindParam(':expires', $expires);
            $insertStmt->execute();
            
            // Build full reset link
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $uri = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetLink = $protocol . '://' . $host . $uri . '/reset_password.php?token=' . $token . '&email=' . urlencode($email) . '&role=' . $role;
            
            // Send email using your existing Notification class
            $notification = new Notification($conn);
            
            $subject = "Password Reset Request - Daeyang University";
            $body = "
            <html>
            <head>
                <title>Password Reset</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #4a2f1a; color: white; padding: 15px; text-align: center; }
                    .content { padding: 20px; background: #f9f9f9; }
                    .button { display: inline-block; background: #c3a343; color: #4a2f1a; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 15px 0; }
                    .footer { font-size: 12px; color: #666; text-align: center; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Daeyang University</h2>
                        <p>Nursing Clinical Allocation System</p>
                    </div>
                    <div class='content'>
                        <p>Hello, <strong>{$user['name']}</strong>,</p>
                        <p>We received a request to reset your password for your {$role} account.</p>
                        <p>Click the button below to reset your password:</p>
                        <p style='text-align: center;'>
                            <a href='{$resetLink}' class='button'>Reset Password</a>
                        </p>
                        <p>Or copy and paste this link into your browser:</p>
                        <p style='background: #eee; padding: 10px; word-break: break-all; font-size: 12px;'>{$resetLink}</p>
                        <p>This link will expire in <strong>1 hour</strong>.</p>
                        <p>If you did not request this, please ignore this email.</p>
                    </div>
                    <div class='footer'>
                        <p>Daeyang University - Nursing Department</p>
                        <p>&copy; 2024 Daeyang University</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Use your notification class to send email
            // Try different possible method names based on your Notification class
            $emailSent = false;
            
            // Method 1: If your Notification class has sendEmail method
            if (method_exists($notification, 'sendEmail')) {
                $emailSent = $notification->sendEmail($email, $subject, $body);
            }
            // Method 2: If it has sendAllocationNotification style
            elseif (method_exists($notification, 'sendAllocationNotification')) {
                // Reuse the allocation notification but modify for password reset
                $emailSent = $notification->sendAllocationNotification(
                    $user['student_id'] ?? $user['lecturer_id'] ?? $user['matron_id'],
                    $email,
                    $user['name'],
                    'Password Reset',
                    date('Y-m-d'),
                    date('Y-m-d', strtotime('+1 hour')),
                    'password_reset',
                    'email'
                );
            }
            // Method 3: Direct mail function as fallback
            else {
                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: nursing@daeyang.edu" . "\r\n";
                $emailSent = mail($email, $subject, $body, $headers);
            }
            
            if ($emailSent) {
                $message = "A password reset link has been sent to your email address. Please check your inbox.";
                $step = 'sent';
            } else {
                $error = "Failed to send email. Please try again later.";
            }
        } else {
            $error = "No account found with this email address for selected role.";
        }
    }
}

// Show reset form when user clicks link from email
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
        body { font-family: 'Inter', sans-serif; background: #ffffff; min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
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
            <h2>Forgot Password?</h2>
            <p class="subtitle">Enter your email address and select your role.</p>
            
            <?php if ($message): ?>
                <div class="success-msg"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-msg"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="your.email@daeyang.edu">
                </div>
                <div class="form-group">
                    <label>I am a</label>
                    <select name="role" required>
                        <option value="">Select your role</option>
                        <option value="student">Student</option>
                        <option value="lecturer">Lecturer</option>
                        <option value="matron">Matron</option>
                    </select>
                </div>
                <button type="submit" name="request_reset" class="btn-primary">Send Reset Link</button>
            </form>
            
            <div class="back-link">
                <a href="login.php">← Back to Login</a>
            </div>
            
        <?php elseif ($step == 'reset'): ?>
            <h2>Reset Password</h2>
            <p class="subtitle">Enter your new password below.</p>
            
            <?php if ($error): ?>
                <div class="error-msg"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required minlength="6">
                    <div class="password-requirements">Password must be at least 6 characters</div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" name="reset_password" class="btn-primary">Reset Password</button>
            </form>
            
        <?php elseif ($step == 'sent'): ?>
            <div class="success-msg">
                <strong>✓ Check Your Email</strong><br>
                <?php echo $message; ?>
            </div>
            <div class="back-link">
                <a href="login.php">← Back to Login</a>
            </div>
            
        <?php elseif ($step == 'complete'): ?>
            <div class="success-msg">
                <strong>✓ Password Reset Successful!</strong><br>
                <?php echo $message; ?>
            </div>
            <div class="back-link">
                <a href="login.php">Click here to login →</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>