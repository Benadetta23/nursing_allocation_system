<?php
// Load PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Notification {
    private $conn;
    
    // Email configuration - UPDATED WITH YOUR CREDENTIALS
    private $email_enabled = true;
    private $smtp_host = 'smtp.gmail.com';
    private $smtp_port = 587;
    private $smtp_username = 'avannajb@gmail.com';  // YOUR GMAIL
    private $smtp_password = 'ozon ayjc zuma lcpk';  // YOUR APP PASSWORD
    private $from_email = 'avannajb@gmail.com';  // YOUR GMAIL
    private $from_name = 'Daeyang University Nursing Department';
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    // Send email using PHPMailer
    public function sendEmail($to, $subject, $message) {
        // Check if email is enabled
        if (!$this->email_enabled) {
            error_log("Email is disabled");
            return false;
        }
        
        // Require PHPMailer files
        require_once __DIR__ . '/phpmailer/src/Exception.php';
        require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/src/SMTP.php';
        
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host       = $this->smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtp_username;
            $mail->Password   = $this->smtp_password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->smtp_port;
            
            // Recipients
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $this->formatEmailMessage($message);
            $mail->AltBody = strip_tags($message);
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email failed: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    // Format email message with HTML template
    private function formatEmailMessage($message) {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; }
                .header { background: #4a2f1a; color: #c3a343; padding: 20px; text-align: center; }
                .content { padding: 30px; background: white; }
                .footer { background: #4a2f1a; color: white; padding: 15px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Daeyang University</h2>
                    <p>Nursing Department</p>
                </div>
                <div class="content">
                    ' . $message . '
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' Daeyang University. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ';
    }
    
    // Save in-app notification to database
    public function saveNotification($user_id, $user_type, $title, $message, $related_id = null) {
        $query = "INSERT INTO notifications (user_id, user_type, title, message, related_id, created_at, is_read) 
                  VALUES (:user_id, :user_type, :title, :message, :related_id, NOW(), 0)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':user_type', $user_type);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':related_id', $related_id);
        return $stmt->execute();
    }
    
    // Get unread notifications for a user
    public function getUnreadNotifications($user_id, $user_type) {
        $query = "SELECT * FROM notifications 
                  WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0 
                  ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':user_type', $user_type);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get all notifications for a user
    public function getAllNotifications($user_id, $user_type, $limit = 20) {
        $query = "SELECT * FROM notifications 
                  WHERE user_id = :user_id AND user_type = :user_type 
                  ORDER BY created_at DESC 
                  LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':user_type', $user_type);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Mark notification as read
    public function markAsRead($notification_id) {
        $query = "UPDATE notifications SET is_read = 1 WHERE notification_id = :notification_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':notification_id', $notification_id);
        return $stmt->execute();
    }
    
    // Mark all notifications as read
    public function markAllAsRead($user_id, $user_type) {
        $query = "UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND user_type = :user_type";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':user_type', $user_type);
        return $stmt->execute();
    }
    
    // Get notification count
    public function getUnreadCount($user_id, $user_type) {
        $query = "SELECT COUNT(*) as count FROM notifications 
                  WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':user_type', $user_type);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }
    
    // Send allocation notification to student
    public function sendAllocationNotification($student_id, $student_email, $student_name, $site_name, $start_date, $end_date, $role, $notify_by = 'both') {
        $subject = "Clinical Placement Allocation - Daeyang University";
        
        $message = "
            <h3>Dear {$student_name},</h3>
            <p>You have been allocated to a clinical placement:</p>
            <table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>
                <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Clinical Site:</strong></td>
                    <td style='padding: 8px;'>{$site_name}</td>
                </tr>
                <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Start Date:</strong></td>
                    <td style='padding: 8px;'>{$start_date}</td>
                </tr>
                <tr><td style='padding: 8px; background: #f0f0f0;'><strong>End Date:</strong></td>
                    <td style='padding: 8px;'>{$end_date}</td>
                </tr>
                <tr><td style='padding: 8px; background: #f0f0f0;'><strong>Role:</strong></td>
                    <td style='padding: 8px;'>{$role}</td>
                </tr>
            </table>
            <p>Please report to the clinical site on your start date. Bring your student ID and any required documents.</p>
            <p>Best regards,<br><strong>Nursing Department</strong><br>Daeyang University</p>
        ";
        
        $emailSent = false;
        
        // Send email if requested
        if ($notify_by == 'email' || $notify_by == 'both') {
            $emailSent = $this->sendEmail($student_email, $subject, $message);
        }
        
        // Save in-app notification (always)
        $inAppSent = $this->saveNotification(
            $student_id,
            'student',
            'New Clinical Placement',
            "You have been allocated to {$site_name} from {$start_date} to {$end_date} as {$role}.",
            null
        );
        
        return [
            'email_sent' => $emailSent,
            'in_app_sent' => $inAppSent,
            'email_enabled' => $this->email_enabled
        ];
    }
}
?>