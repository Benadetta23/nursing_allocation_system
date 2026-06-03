<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'classes/Database.php';
require_once 'classes/Notification.php';

echo "<h2>Email Configuration Test</h2>";

$db = new Database();
$conn = $db->getConnection();
$notification = new Notification($conn);

// !!! CHANGE THIS TO YOUR REAL EMAIL ADDRESS !!!
$to = "avannajb@gmail.com";  // REPLACE with your actual email

$subject = "Test Email from Nursing Allocation System";
$message = "
<html>
<body>
    <h2 style='color: #4a2f1a;'>Test Successful!</h2>
    <p>Your email system is working correctly.</p>
    <p>This test email was sent from Daeyang University Nursing Department.</p>
    <p>Time: " . date('Y-m-d H:i:s') . "</p>
    <script src="js/page-loader.js"></script>
</body>
</html>
";

echo "Sending test email to: <strong>{$to}</strong><br><br>";

if ($notification->sendEmail($to, $subject, $message)) {
    echo "<span style='color: green; font-weight: bold;'>✅ SUCCESS! Email sent. Check your inbox and spam folder.</span>";
} else {
    echo "<span style='color: red; font-weight: bold;'>❌ FAILED! Email could not be sent.</span>";
    echo "<p>Check your credentials in Notification.php:</p>";
    echo "<ul>";
    echo "<li>Make sure smtp_username is your full Gmail address</li>";
    echo "<li>Make sure smtp_password is your 16-character App Password</li>";
    echo "<li>Make sure email_enabled = true</li>";
    echo "</ul>";
}

// Check if PHPMailer exists
echo "<hr>";
echo "<h4>File Check:</h4>";
echo "PHPMailer.php exists: " . (file_exists('classes/phpmailer/src/PHPMailer.php') ? "✅ Yes" : "❌ No") . "<br>";
echo "SMTP.php exists: " . (file_exists('classes/phpmailer/src/SMTP.php') ? "✅ Yes" : "❌ No") . "<br>";
echo "Exception.php exists: " . (file_exists('classes/phpmailer/src/Exception.php') ? "✅ Yes" : "❌ No") . "<br>";
?>