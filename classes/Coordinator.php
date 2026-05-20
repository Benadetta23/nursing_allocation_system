<?php
require_once 'Database.php';
require_once 'Notification.php';

class Coordinator {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // ============ SITE OPERATIONS ============
    
    public function addSite($name, $location, $contact_person, $contact_phone, $max_students) {
        $query = "INSERT INTO clinical_site (name, location, contact_person, contact_phone, max_students) 
                  VALUES (:name, :location, :contact_person, :contact_phone, :max_students)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':contact_person', $contact_person);
        $stmt->bindParam(':contact_phone', $contact_phone);
        $stmt->bindParam(':max_students', $max_students);
        return $stmt->execute();
    }
    
    public function getSites() {
        $query = "SELECT * FROM clinical_site ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function deleteSite($site_id) {
        $query = "DELETE FROM clinical_site WHERE site_id = :site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        return $stmt->execute();
    }
    
    // ============ STUDENT OPERATIONS ============
    
    public function addStudent($student_number, $name, $email, $cohort, $mode_of_entry, $coordinator_id) {
        $password_hash = password_hash('pass', PASSWORD_DEFAULT);
        $query = "INSERT INTO student (student_number, name, email, password_hash, cohort, mode_of_entry, coordinator_id) 
                  VALUES (:student_number, :name, :email, :password_hash, :cohort, :mode_of_entry, :coordinator_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_number', $student_number);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':cohort', $cohort);
        $stmt->bindParam(':mode_of_entry', $mode_of_entry);
        $stmt->bindParam(':coordinator_id', $coordinator_id);
        return $stmt->execute();
    }
    
    public function getStudents() {
        $query = "SELECT student_id, student_number, name, email, cohort, mode_of_entry FROM student ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function deleteStudent($student_id) {
        $query = "DELETE FROM student WHERE student_id = :student_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        return $stmt->execute();
    }
    
    // ============ COHORT OPERATIONS ============
    
    public function getCohorts() {
        $query = "SELECT DISTINCT cohort FROM student WHERE cohort IS NOT NULL AND cohort != '' ORDER BY cohort DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ ALLOCATION OPERATIONS ============
    
    public function createAllocation($student_id, $site_id, $start_date, $end_date, $role) {
        $result = $this->allocateStudentWithNotification(
            $student_id,
            $site_id,
            $start_date,
            $end_date,
            $role,
            'email'
        );
        return $result['success'];
    }
    
    // ============ NEW: ALLOCATION WITH NOTIFICATION ============
    
    public function allocateStudentWithNotification($student_id, $site_id, $start_date, $end_date, $role, $notify_by = 'email') {
        try {
            $this->conn->beginTransaction();
            
            // First, check if student already has active allocation
            $checkQuery = "SELECT alloc_id FROM allocation 
                           WHERE student_id = :student_id AND status = 'active'";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':student_id', $student_id);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'Student already has an active allocation'];
            }
            
            // Insert allocation
            $query = "INSERT INTO allocation (student_id, site_id, start_date, end_date, role, status) 
                      VALUES (:student_id, :site_id, :start_date, :end_date, :role, 'active')";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':site_id', $site_id);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->bindParam(':role', $role);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to allocate student");
            }
            
            $allocation_id = $this->conn->lastInsertId();
            
            // Get student details for notification
            $studentQuery = "SELECT s.* FROM student s WHERE s.student_id = :student_id";
            $studentStmt = $this->conn->prepare($studentQuery);
            $studentStmt->bindParam(':student_id', $student_id);
            $studentStmt->execute();
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get clinical site details
            $siteQuery = "SELECT * FROM clinical_site WHERE site_id = :site_id";
            $siteStmt = $this->conn->prepare($siteQuery);
            $siteStmt->bindParam(':site_id', $site_id);
            $siteStmt->execute();
            $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
            
            // Prepare notification message
            $subject = "Clinical Placement Allocation - Daeyang University";
            $message = "
                <h3>Dear {$student['name']},</h3>
                <p>You have been allocated to a clinical placement:</p>
                <table style='border-collapse: collapse; width: 100%;'>
                    <tr><td style='padding: 8px;'><strong>Clinical Site:</strong></td><td>{$site['name']}</td></tr>
                    <tr><td style='padding: 8px;'><strong>Location:</strong></td><td>{$site['location']}</td></tr>
                    <tr><td style='padding: 8px;'><strong>Start Date:</strong></td><td>$start_date</td></tr>
                    <tr><td style='padding: 8px;'><strong>End Date:</strong></td><td>$end_date</td></tr>
                    <tr><td style='padding: 8px;'><strong>Role:</strong></td><td>$role</td></tr>
                </table>
                <p>Please report to the clinical site on your start date.</p>
                <p>Best regards,<br>Nursing Department<br>Daeyang University</p>
            ";
            
            $smsMessage = "Daeyang Uni: You've been allocated to {$site['name']} from $start_date to $end_date as $role. Report on start date.";
            
            // Send notification
            $notification = new Notification($this->conn);
            
            // Save in-app notification
            $notification->saveNotification(
                $student_id, 
                'student', 
                'New Clinical Placement', 
                "You have been allocated to {$site['name']} from $start_date to $end_date as $role.",
                $allocation_id
            );
            
            // Send email if requested
            $emailSent = false;
            $smsSent = false;
            
            if ($notify_by == 'email' || $notify_by == 'both') {
                $emailSent = $notification->sendEmail($student['email'], $subject, $message);
            }
            
            // Note: SMS requires a third-party service like Twilio, Africa's Talking, etc.
            // For now, we'll just log it. You can integrate SMS service later.
            if ($notify_by == 'sms' || $notify_by == 'both') {
                // This would integrate with SMS API
                // $smsSent = $this->sendSMS($student['phone'], $smsMessage);
                $smsSent = false; // Placeholder until SMS service is integrated
            }
            
            $this->conn->commit();
            
            return [
                'success' => true, 
                'message' => 'Student allocated successfully',
                'email_sent' => $emailSent,
                'sms_sent' => $smsSent,
                'allocation_id' => $allocation_id
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============ UPDATE EXISTING ALLOCATION WITH NOTIFICATION ============
    
    public function updateAllocationWithNotification($alloc_id, $site_id, $start_date, $end_date, $role, $notify_by = 'email') {
        try {
            $this->conn->beginTransaction();
            
            // Get current allocation and student details
            $getQuery = "SELECT a.*, s.name as student_name, s.email, s.student_id 
                        FROM allocation a 
                        JOIN student s ON a.student_id = s.student_id 
                        WHERE a.alloc_id = :alloc_id";
            $getStmt = $this->conn->prepare($getQuery);
            $getStmt->bindParam(':alloc_id', $alloc_id);
            $getStmt->execute();
            $allocation = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$allocation) {
                return ['success' => false, 'message' => 'Allocation not found'];
            }
            
            // Update allocation
            $query = "UPDATE allocation 
                      SET site_id = :site_id, start_date = :start_date, end_date = :end_date, role = :role 
                      WHERE alloc_id = :alloc_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':site_id', $site_id);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':alloc_id', $alloc_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update allocation");
            }
            
            // Get clinical site details
            $siteQuery = "SELECT * FROM clinical_site WHERE site_id = :site_id";
            $siteStmt = $this->conn->prepare($siteQuery);
            $siteStmt->bindParam(':site_id', $site_id);
            $siteStmt->execute();
            $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
            
            // Prepare notification message
            $subject = "Clinical Placement Update - Daeyang University";
            $message = "
                <h3>Dear {$allocation['student_name']},</h3>
                <p>Your clinical placement has been updated:</p>
                <table style='border-collapse: collapse; width: 100%;'>
                    <tr><td style='padding: 8px;'><strong>Clinical Site:</strong></td><td>{$site['name']}</td></tr>
                    <tr><td style='padding: 8px;'><strong>Location:</strong></td><td>{$site['location']}</td></tr>
                    <tr><td style='padding: 8px;'><strong>Start Date:</strong></td><td>$start_date</td></tr>
                    <tr><td style='padding: 8px;'><strong>End Date:</strong></td><td>$end_date</td></tr>
                    <tr><td style='padding: 8px;'><strong>Role:</strong></td><td>$role</td></tr>
                </table>
                <p>Please report to the clinical site on your start date.</p>
                <p>Best regards,<br>Nursing Department<br>Daeyang University</p>
            ";
            
            // Send notification
            $notification = new Notification($this->conn);
            
            // Save in-app notification
            $notification->saveNotification(
                $allocation['student_id'], 
                'student', 
                'Clinical Placement Updated', 
                "Your clinical placement has been updated: {$site['name']} from $start_date to $end_date as $role.",
                $alloc_id
            );
            
            // Send email if requested
            $emailSent = false;
            if ($notify_by == 'email' || $notify_by == 'both') {
                $emailSent = $notification->sendEmail($allocation['email'], $subject, $message);
            }
            
            $this->conn->commit();
            
            return [
                'success' => true, 
                'message' => 'Allocation updated successfully',
                'email_sent' => $emailSent
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============ BULK ALLOCATION WITH NOTIFICATIONS ============
    
    public function bulkAllocateWithNotifications($allocations, $notify_by = 'email') {
        $success_count = 0;
        $failed_count = 0;
        $results = [];
        
        foreach ($allocations as $allocation) {
            $result = $this->allocateStudentWithNotification(
                $allocation['student_id'],
                $allocation['site_id'],
                $allocation['start_date'],
                $allocation['end_date'],
                $allocation['role'],
                $notify_by
            );
            
            if ($result['success']) {
                $success_count++;
            } else {
                $failed_count++;
            }
            $results[] = $result;
        }
        
        return [
            'success' => $success_count > 0,
            'success_count' => $success_count,
            'failed_count' => $failed_count,
            'results' => $results,
            'message' => "Allocated $success_count students successfully, $failed_count failed."
        ];
    }
    
    // ============ EXISTING ALLOCATION METHODS (UNCHANGED) ============
    
    public function getAllocations() {
        $query = "SELECT a.*, s.name as student_name, s.student_number, c.name as site_name 
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id 
                  JOIN clinical_site c ON a.site_id = c.site_id 
                  ORDER BY a.start_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getAllocationsWithDaysRemaining() {
        $query = "SELECT a.*, s.name as student_name, s.student_number, c.name as site_name,
                         DATEDIFF(a.end_date, CURDATE()) as days_remaining,
                         CASE 
                             WHEN a.status = 'completed' THEN 'Completed'
                             WHEN a.end_date < CURDATE() THEN 'Overdue'
                             WHEN DATEDIFF(a.end_date, CURDATE()) <= 7 THEN 'Expiring Soon'
                             WHEN DATEDIFF(a.end_date, CURDATE()) > 7 THEN 'Active'
                             ELSE 'Active'
                         END as placement_status
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id 
                  JOIN clinical_site c ON a.site_id = c.site_id 
                  ORDER BY a.start_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function deleteAllocation($alloc_id) {
        $query = "DELETE FROM allocation WHERE alloc_id = :alloc_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':alloc_id', $alloc_id);
        return $stmt->execute();
    }
    
    // ============ PENDING ASSESSMENTS ============
    
    public function getPendingAssessments() {
        $query = "SELECT s.student_id, s.name as student_name, s.student_number, 
                         c.site_id, c.name as site_name, a.start_date, a.end_date,
                         a.role
                  FROM allocation a
                  JOIN student s ON a.student_id = s.student_id
                  JOIN clinical_site c ON a.site_id = c.site_id
                  WHERE a.status = 'active'
                  AND NOT EXISTS (
                      SELECT 1 FROM assessment ass 
                      WHERE ass.student_id = s.student_id
                  )
                  ORDER BY c.name, s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ REPORT OPERATIONS ============
    
    public function getStudentsBySite() {
        $query = "SELECT c.site_id, c.name as site_name, c.location,
                         COUNT(a.student_id) as total_students,
                         GROUP_CONCAT(CONCAT(s.name, ' (', s.student_number, ')') SEPARATOR ', ') as students
                  FROM clinical_site c
                  LEFT JOIN allocation a ON c.site_id = a.site_id AND a.status = 'active'
                  LEFT JOIN student s ON a.student_id = s.student_id
                  GROUP BY c.site_id
                  ORDER BY c.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getAssessmentSummary() {
        $query = "SELECT s.student_id, s.name as student_name, s.student_number,
                         ROUND(AVG(a.punctuality_score), 1) as avg_punctuality,
                         ROUND(AVG(a.dressing_score), 1) as avg_dressing,
                         ROUND(AVG(a.communication_score), 1) as avg_communication,
                         ROUND(AVG((a.punctuality_score + a.dressing_score + a.communication_score) / 3), 1) as overall_average,
                         COUNT(a.assess_id) as assessment_count
                  FROM student s
                  LEFT JOIN assessment a ON s.student_id = a.student_id
                  GROUP BY s.student_id
                  ORDER BY overall_average DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getSiteAssessmentSummary() {
        $query = "SELECT c.site_id, c.name as site_name,
                         COUNT(DISTINCT a.student_id) as students_assessed,
                         ROUND(AVG(ass.punctuality_score), 1) as avg_punctuality,
                         ROUND(AVG(ass.dressing_score), 1) as avg_dressing,
                         ROUND(AVG(ass.communication_score), 1) as avg_communication,
                         COUNT(ass.assess_id) as total_assessments
                  FROM clinical_site c
                  LEFT JOIN allocation a ON c.site_id = a.site_id
                  LEFT JOIN assessment ass ON a.student_id = ass.student_id AND a.site_id = ass.site_id
                  GROUP BY c.site_id
                  ORDER BY c.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ FILTERED REPORT OPERATIONS ============
    
    public function getStudentsBySiteFiltered($site_id = null) {
        $sql = "SELECT c.site_id, c.name as site_name, c.location,
                       COUNT(a.student_id) as total_students,
                       GROUP_CONCAT(CONCAT(s.name, ' (', s.student_number, ')') SEPARATOR ', ') as students
                FROM clinical_site c
                LEFT JOIN allocation a ON c.site_id = a.site_id AND a.status = 'active'
                LEFT JOIN student s ON a.student_id = s.student_id";
        
        if ($site_id) {
            $sql .= " WHERE c.site_id = :site_id";
        }
        
        $sql .= " GROUP BY c.site_id ORDER BY c.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getPendingAssessmentsFiltered($site_id = null, $cohort = null) {
        $sql = "SELECT s.student_id, s.name as student_name, s.student_number, s.cohort,
                       c.site_id, c.name as site_name, a.start_date, a.end_date, a.role
                FROM allocation a
                JOIN student s ON a.student_id = s.student_id
                JOIN clinical_site c ON a.site_id = c.site_id
                WHERE a.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM assessment ass WHERE ass.student_id = s.student_id)";
        
        if ($site_id) {
            $sql .= " AND c.site_id = :site_id";
        }
        if ($cohort) {
            $sql .= " AND s.cohort = :cohort";
        }
        
        $sql .= " ORDER BY c.name, s.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id);
        }
        if ($cohort) {
            $stmt->bindParam(':cohort', $cohort);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getAssessmentSummaryFiltered($cohort = null, $student_id = null) {
        $sql = "SELECT s.student_id, s.name as student_name, s.student_number, s.cohort,
                       ROUND(AVG(a.punctuality_score), 1) as avg_punctuality,
                       ROUND(AVG(a.dressing_score), 1) as avg_dressing,
                       ROUND(AVG(a.communication_score), 1) as avg_communication,
                       ROUND(AVG((a.punctuality_score + a.dressing_score + a.communication_score) / 3), 1) as overall_average,
                       COUNT(a.assess_id) as assessment_count
                FROM student s
                LEFT JOIN assessment a ON s.student_id = a.student_id
                WHERE 1=1";
        
        if ($cohort) {
            $sql .= " AND s.cohort = :cohort";
        }
        if ($student_id) {
            $sql .= " AND s.student_id = :student_id";
        }
        
        $sql .= " GROUP BY s.student_id ORDER BY overall_average DESC";
        
        $stmt = $this->conn->prepare($sql);
        if ($cohort) {
            $stmt->bindParam(':cohort', $cohort);
        }
        if ($student_id) {
            $stmt->bindParam(':student_id', $student_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getSiteAssessmentSummaryFiltered($site_id = null) {
        $sql = "SELECT c.site_id, c.name as site_name,
                       COUNT(DISTINCT a.student_id) as students_assessed,
                       ROUND(AVG(ass.punctuality_score), 1) as avg_punctuality,
                       ROUND(AVG(ass.dressing_score), 1) as avg_dressing,
                       ROUND(AVG(ass.communication_score), 1) as avg_communication,
                       COUNT(ass.assess_id) as total_assessments
                FROM clinical_site c
                LEFT JOIN allocation a ON c.site_id = a.site_id
                LEFT JOIN assessment ass ON a.student_id = ass.student_id AND a.site_id = ass.site_id";
        
        if ($site_id) {
            $sql .= " WHERE c.site_id = :site_id";
        }
        
        $sql .= " GROUP BY c.site_id ORDER BY c.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ DATE RANGE REPORT ============
    
    public function getReportByDateRange($date_from, $date_to) {
        $query = "SELECT a.*, s.name as student_name, s.student_number, c.name as site_name, l.name as lecturer_name
                  FROM assessment a
                  JOIN student s ON a.student_id = s.student_id
                  JOIN clinical_site c ON a.site_id = c.site_id
                  JOIN lecturer l ON a.lecturer_id = l.lecturer_id
                  WHERE a.assessment_date BETWEEN :date_from AND :date_to
                  ORDER BY a.assessment_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':date_from', $date_from);
        $stmt->bindParam(':date_to', $date_to);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>