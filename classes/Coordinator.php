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
        // Check if site already exists with same name and location
        $checkQuery = "SELECT site_id FROM clinical_site WHERE name = :name AND location = :location";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':name', $name);
        $checkStmt->bindParam(':location', $location);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false; // Site already exists
        }
        
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
        // Check if site has allocations before deleting
        $checkQuery = "SELECT alloc_id FROM allocation WHERE site_id = :site_id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':site_id', $site_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false; // Cannot delete site with allocations
        }
        
        $query = "DELETE FROM clinical_site WHERE site_id = :site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        return $stmt->execute();
    }
    
    // ============ STUDENT OPERATIONS ============
    
    public function addStudent($student_number, $name, $email, $cohort, $mode_of_entry, $coordinator_id, $year_of_study = 1) {
        // Check if student already exists
        $checkQuery = "SELECT student_id FROM student WHERE student_number = :student_number OR email = :email";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_number', $student_number);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false; // Student already exists
        }
        
        $password_hash = password_hash('pass', PASSWORD_DEFAULT);
        $query = "INSERT INTO student (student_number, name, email, password_hash, cohort, year_of_study, mode_of_entry, coordinator_id) 
                  VALUES (:student_number, :name, :email, :password_hash, :cohort, :year_of_study, :mode_of_entry, :coordinator_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_number', $student_number);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':cohort', $cohort);
        $stmt->bindParam(':year_of_study', $year_of_study);
        $stmt->bindParam(':mode_of_entry', $mode_of_entry);
        $stmt->bindParam(':coordinator_id', $coordinator_id);
        return $stmt->execute();
    }
    
    public function getStudents() {
        $query = "SELECT student_id, student_number, name, email, cohort, year_of_study, mode_of_entry FROM student ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function deleteStudent($student_id) {
        // Check if student has allocations before deleting
        $checkQuery = "SELECT alloc_id FROM allocation WHERE student_id = :student_id AND status = 'active'";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false; // Cannot delete student with active allocation
        }
        
        $query = "DELETE FROM student WHERE student_id = :student_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        return $stmt->execute();
    }

    public function addStudentWithDefaultAllocation($student_number, $name, $email, $cohort, $mode_of_entry, $coordinator_id, $year_of_study = 1) {
        // Add the student first
        $password_hash = password_hash('pass', PASSWORD_DEFAULT);
        $query = "INSERT INTO student (student_number, name, email, password_hash, cohort, year_of_study, mode_of_entry, coordinator_id) 
                  VALUES (:student_number, :name, :email, :password_hash, :cohort, :year_of_study, :mode_of_entry, :coordinator_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_number', $student_number);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':cohort', $cohort);
        $stmt->bindParam(':year_of_study', $year_of_study);
        $stmt->bindParam(':mode_of_entry', $mode_of_entry);
        $stmt->bindParam(':coordinator_id', $coordinator_id);
        
        if ($stmt->execute()) {
            $student_id = $this->conn->lastInsertId();
            
            // If first year student, automatically assign General Nursing role
            if ($year_of_study == 1 || $year_of_study === '1') {
                // Get the first available clinical site
                $siteQuery = "SELECT site_id FROM clinical_site LIMIT 1";
                $siteStmt = $this->conn->prepare($siteQuery);
                $siteStmt->execute();
                $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($site) {
                    // Set allocation dates: start today, end in 3 months
                    $start_date = date('Y-m-d');
                    $end_date = date('Y-m-d', strtotime('+3 months'));
                    
                    // Create default allocation with General Nursing role
                    $allocQuery = "INSERT INTO allocation (student_id, site_id, start_date, end_date, role, status) 
                                   VALUES (:student_id, :site_id, :start_date, :end_date, 'General Nursing', 'active')";
                    $allocStmt = $this->conn->prepare($allocQuery);
                    $allocStmt->bindParam(':student_id', $student_id);
                    $allocStmt->bindParam(':site_id', $site['site_id']);
                    $allocStmt->bindParam(':start_date', $start_date);
                    $allocStmt->bindParam(':end_date', $end_date);
                    $allocStmt->execute();
                }
            }
            
            return true;
        }
        
        return false;
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
        // Check if student already has active allocation
        $checkQuery = "SELECT alloc_id FROM allocation WHERE student_id = :student_id AND status = 'active'";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false; // Student already allocated
        }
        
        $result = $this->allocateStudentWithNotification(
            $student_id,
            $site_id,
            $start_date,
            $end_date,
            $role,
            'both'
        );
        return $result['success'];
    }
    
    // ============ ALLOCATION WITH NOTIFICATION ============
    
    public function allocateStudentWithNotification($student_id, $site_id, $start_date, $end_date, $role, $notify_by = 'both') {
        try {
            $this->conn->beginTransaction();
            $this->removeDuplicateActiveAllocations($student_id);
            
            // First, check if student already has active allocation
            $checkQuery = "SELECT alloc_id FROM allocation 
                           WHERE student_id = :student_id AND status = 'active'";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':student_id', $student_id);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                $this->conn->rollBack();
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
                    <tr><td style='padding: 8px;'><strong>Clinical Site:</strong></td>
                        <td>{$site['name']}</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>Location:</strong></td>
                        <td>{$site['location']}</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>Start Date:</strong></td>
                        <td>$start_date</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>End Date:</strong></td>
                        <td>$end_date</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>Role:</strong></td>
                        <td>$role</td>
                    </tr>
                </table>
                <p>Please report to the clinical site on your start date.</p>
                <p>Best regards,<br>Nursing Department<br>Daeyang University</p>
            ";
            
            // Send notification
            $notification = new Notification($this->conn);
            
            // Save in-app notification (ALWAYS)
            $inAppSent = $notification->saveNotification(
                $student_id, 
                'student', 
                'New Clinical Placement', 
                "You have been allocated to {$site['name']} from $start_date to $end_date as $role.",
                $allocation_id
            );
            
            // Send email if requested
            $emailSent = false;
            if ($notify_by == 'email' || $notify_by == 'both') {
                $emailSent = $notification->sendEmail($student['email'], $subject, $message);
            }
            
            $this->conn->commit();
            
            return [
                'success' => true, 
                'message' => 'Student allocated successfully',
                'email_sent' => $emailSent,
                'in_app_sent' => $inAppSent,
                'allocation_id' => $allocation_id
            ];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============ UPDATE EXISTING ALLOCATION WITH NOTIFICATION ============
    
    public function updateAllocationWithNotification($alloc_id, $site_id, $start_date, $end_date, $role, $notify_by = 'both') {
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
                $this->conn->rollBack();
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
                    <tr><td style='padding: 8px;'><strong>Clinical Site:</strong></td>
                        <td>{$site['name']}</td>
                    </tr>
                    <td><td style='padding: 8px;'><strong>Location:</strong></td>
                        <td>{$site['location']}</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>Start Date:</strong></td>
                        <td>$start_date</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>End Date:</strong></td>
                        <td>$end_date</td>
                    </tr>
                    <tr><td style='padding: 8px;'><strong>Role:</strong></td>
                        <td>$role</td>
                    </tr>
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
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============ BULK ALLOCATION WITH NOTIFICATIONS ============
    
    public function bulkAllocateWithNotifications($allocations, $notify_by = 'both') {
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
    
    // ============ ALLOCATION METHODS ============

    public function removeDuplicateActiveAllocations($student_id = null) {
        $studentFilter = '';
        if ($student_id !== null && $student_id !== '') {
            $studentFilter = ' AND student_id = :student_id';
        }

        $query = "UPDATE allocation duplicate_alloc
                  JOIN (
                      SELECT student_id, MAX(alloc_id) as keep_alloc_id
                      FROM allocation
                      WHERE status = 'active'{$studentFilter}
                      GROUP BY student_id
                      HAVING COUNT(*) > 1
                  ) keepers ON duplicate_alloc.student_id = keepers.student_id
                  SET duplicate_alloc.status = 'completed'
                  WHERE duplicate_alloc.status = 'active'
                  AND duplicate_alloc.alloc_id <> keepers.keep_alloc_id";
        $stmt = $this->conn->prepare($query);
        if ($student_id !== null && $student_id !== '') {
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    public function getAllocations() {
        $query = "SELECT a.*, s.name as student_name, s.student_number, c.name as site_name 
                  FROM allocation a 
                  JOIN (
                      SELECT student_id, MAX(alloc_id) as alloc_id
                      FROM allocation
                      WHERE status = 'active'
                      GROUP BY student_id
                  ) latest ON a.alloc_id = latest.alloc_id
                  JOIN student s ON a.student_id = s.student_id 
                  JOIN clinical_site c ON a.site_id = c.site_id 
                  ORDER BY a.start_date DESC, a.alloc_id DESC";
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
                  JOIN (
                      SELECT student_id, MAX(alloc_id) as alloc_id
                      FROM allocation
                      WHERE status = 'active'
                      GROUP BY student_id
                  ) latest ON a.alloc_id = latest.alloc_id
                  JOIN student s ON a.student_id = s.student_id 
                  JOIN clinical_site c ON a.site_id = c.site_id 
                  ORDER BY a.start_date DESC, a.alloc_id DESC";
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
                  JOIN (
                      SELECT student_id, MAX(alloc_id) as alloc_id
                      FROM allocation
                      WHERE status = 'active'
                      GROUP BY student_id
                  ) latest ON a.alloc_id = latest.alloc_id
                  JOIN student s ON a.student_id = s.student_id
                  JOIN clinical_site c ON a.site_id = c.site_id
                  WHERE NOT EXISTS (
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
                         COUNT(DISTINCT a.student_id) as total_students,
                         GROUP_CONCAT(DISTINCT CONCAT(s.name, ' (', s.student_number, ')') ORDER BY s.name SEPARATOR ', ') as students
                  FROM clinical_site c
                  LEFT JOIN allocation a ON c.site_id = a.site_id AND a.status = 'active'
                  LEFT JOIN student s ON a.student_id = s.student_id
                  GROUP BY c.site_id
                  ORDER BY c.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ NEW ADVANCED REPORTING METHODS ============
    
    // Get all students grouped by clinical site (detailed)
    public function getStudentsByClinicalSiteReport() {
        $query = "SELECT 
                    c.site_id,
                    c.name as site_name,
                    c.location,
                    c.contact_person,
                    c.contact_phone,
                    c.max_students,
                    COUNT(DISTINCT a.student_id) as total_students,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(s.name, ' (', s.student_number, ')') 
                        ORDER BY s.name 
                        SEPARATOR '||'
                    ) as students_list
                  FROM clinical_site c
                  LEFT JOIN allocation a ON c.site_id = a.site_id AND a.status = 'active'
                  LEFT JOIN student s ON a.student_id = s.student_id
                  GROUP BY c.site_id
                  ORDER BY c.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert students_list from || separated to array
        foreach ($results as &$row) {
            if ($row['students_list']) {
                $row['students'] = explode('||', $row['students_list']);
            } else {
                $row['students'] = [];
            }
        }
        return $results;
    }
    
    // Get students for a specific clinical site
    public function getStudentsBySpecificSite($site_id) {
        $query = "SELECT 
                    c.site_id,
                    c.name as site_name,
                    c.location,
                    c.contact_person,
                    c.contact_phone,
                    c.max_students,
                    COUNT(DISTINCT a.student_id) as total_students,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(s.name, ' (', s.student_number, ')') 
                        ORDER BY s.name 
                        SEPARATOR '||'
                    ) as students_list
                  FROM clinical_site c
                  LEFT JOIN allocation a ON c.site_id = a.site_id AND a.status = 'active'
                  LEFT JOIN student s ON a.student_id = s.student_id
                  WHERE c.site_id = :site_id
                  GROUP BY c.site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['students_list']) {
            $result['students'] = explode('||', $result['students_list']);
        } else if ($result) {
            $result['students'] = [];
        }
        
        return $result ? [$result] : [];
    }
    
    // Debug method to get site by ID
    public function getSiteById($site_id) {
        $query = "SELECT * FROM clinical_site WHERE site_id = :site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get assessment report with pass/fail status
    public function getAssessmentReport($cohort = null, $site_id = null) {
        $sql = "SELECT 
                    s.student_id,
                    s.student_number,
                    s.name as student_name,
                    s.cohort,
                    s.mode_of_entry,
                    c.site_id,
                    c.name as site_name,
                    -- Matron Assessment (Initial)
                    MAX(CASE WHEN a.assessor_type = 'matron' THEN a.punctuality_score END) as matron_punctuality,
                    MAX(CASE WHEN a.assessor_type = 'matron' THEN a.dressing_score END) as matron_dressing,
                    MAX(CASE WHEN a.assessor_type = 'matron' THEN a.communication_score END) as matron_communication,
                    MAX(CASE WHEN a.assessor_type = 'matron' THEN a.assessment_date END) as matron_date,
                    -- Lecturer Assessment (Final)
                    MAX(CASE WHEN a.assessor_type = 'lecturer' THEN a.punctuality_score END) as lecturer_punctuality,
                    MAX(CASE WHEN a.assessor_type = 'lecturer' THEN a.dressing_score END) as lecturer_dressing,
                    MAX(CASE WHEN a.assessor_type = 'lecturer' THEN a.communication_score END) as lecturer_communication,
                    MAX(CASE WHEN a.assessor_type = 'lecturer' THEN a.assessment_date END) as lecturer_date,
                    -- Comments
                    MAX(CASE WHEN a.assessor_type = 'matron' THEN a.comments END) as matron_comments,
                    MAX(CASE WHEN a.assessor_type = 'lecturer' THEN a.comments END) as lecturer_comments,
                    -- Allocation
                    al.start_date,
                    al.end_date,
                    al.role
                FROM student s
                LEFT JOIN (
                    SELECT a1.*
                    FROM allocation a1
                    JOIN (
                        SELECT student_id, MAX(alloc_id) as alloc_id
                        FROM allocation
                        WHERE status = 'active'
                        GROUP BY student_id
                    ) latest ON a1.alloc_id = latest.alloc_id
                ) al ON s.student_id = al.student_id
                LEFT JOIN clinical_site c ON al.site_id = c.site_id
                LEFT JOIN assessment a ON s.student_id = a.student_id
                WHERE 1=1";
        
        if ($cohort) {
            $sql .= " AND s.cohort = :cohort";
        }
        if ($site_id) {
            $sql .= " AND c.site_id = :site_id";
        }
        
        $sql .= " GROUP BY s.student_id, s.student_number, s.name
                  ORDER BY s.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($cohort) {
            $stmt->bindParam(':cohort', $cohort);
        }
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate final scores and pass/fail status
        foreach ($results as &$row) {
            // Calculate matron average
            if ($row['matron_punctuality'] && $row['matron_dressing'] && $row['matron_communication']) {
                $row['matron_average'] = round(
                    ($row['matron_punctuality'] + $row['matron_dressing'] + $row['matron_communication']) / 3, 
                    1
                );
            } else {
                $row['matron_average'] = null;
            }
            
            // Calculate lecturer average (final grade)
            if ($row['lecturer_punctuality'] && $row['lecturer_dressing'] && $row['lecturer_communication']) {
                $row['final_average'] = round(
                    ($row['lecturer_punctuality'] + $row['lecturer_dressing'] + $row['lecturer_communication']) / 3, 
                    1
                );
                
                // Determine grade and pass/fail
                if ($row['final_average'] >= 3.0) {
                    $row['final_grade'] = $this->getLetterGrade($row['final_average']);
                    $row['status'] = 'PASS';
                    $row['status_class'] = 'pass';
                } else {
                    $row['final_grade'] = $this->getLetterGrade($row['final_average']);
                    $row['status'] = 'FAIL';
                    $row['status_class'] = 'fail';
                }
            } else {
                $row['final_average'] = null;
                $row['final_grade'] = 'N/A';
                $row['status'] = 'PENDING';
                $row['status_class'] = 'pending';
            }
        }
        
        return $results;
    }
    
    // Get letter grade based on score
    private function getLetterGrade($score) {
        if ($score >= 4.5) return 'A+';
        if ($score >= 4.0) return 'A';
        if ($score >= 3.5) return 'B+';
        if ($score >= 3.0) return 'B';
        if ($score >= 2.5) return 'C';
        if ($score >= 2.0) return 'D';
        return 'F';
    }
    
    // Get all cohorts for filter
    public function getAllCohorts() {
        $query = "SELECT DISTINCT cohort FROM student WHERE cohort IS NOT NULL AND cohort != '' ORDER BY cohort DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get summary statistics for dashboard
    public function getReportSummary() {
        $query = "SELECT 
                    (SELECT COUNT(*) FROM student) as total_students,
                    (SELECT COUNT(*) FROM clinical_site) as total_sites,
                    (SELECT COUNT(DISTINCT student_id) FROM allocation WHERE status = 'active') as active_placements,
                    (SELECT COUNT(*) FROM assessment WHERE assessor_type = 'lecturer') as completed_assessments,
                    (SELECT COUNT(*) FROM assessment WHERE assessor_type = 'lecturer' AND 
                        ((punctuality_score + dressing_score + communication_score) / 3) >= 3.0) as passed_students,
                    (SELECT COUNT(*) FROM assessment WHERE assessor_type = 'lecturer' AND 
                        ((punctuality_score + dressing_score + communication_score) / 3) < 3.0) as failed_students";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // ============ FILTERED REPORT OPERATIONS ============
    
    public function getStudentsBySiteFiltered($site_id = null) {
        $sql = "SELECT c.site_id, c.name as site_name, c.location,
                       COUNT(DISTINCT a.student_id) as total_students,
                       GROUP_CONCAT(DISTINCT CONCAT(s.name, ' (', s.student_number, ')') ORDER BY s.name SEPARATOR ', ') as students
                FROM clinical_site c
                LEFT JOIN allocation a ON c.site_id = a.site_id AND a.status = 'active'
                LEFT JOIN student s ON a.student_id = s.student_id";
        
        if ($site_id) {
            $sql .= " WHERE c.site_id = :site_id";
        }
        
        $sql .= " GROUP BY c.site_id ORDER BY c.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getPendingAssessmentsFiltered($site_id = null, $cohort = null) {
        $sql = "SELECT s.student_id, s.name as student_name, s.student_number, s.cohort,
                       c.site_id, c.name as site_name, a.start_date, a.end_date, a.role
                FROM allocation a
                JOIN (
                    SELECT student_id, MAX(alloc_id) as alloc_id
                    FROM allocation
                    WHERE status = 'active'
                    GROUP BY student_id
                ) latest ON a.alloc_id = latest.alloc_id
                JOIN student s ON a.student_id = s.student_id
                JOIN clinical_site c ON a.site_id = c.site_id
                WHERE NOT EXISTS (SELECT 1 FROM assessment ass WHERE ass.student_id = s.student_id)";
        
        if ($site_id) {
            $sql .= " AND c.site_id = :site_id";
        }
        if ($cohort) {
            $sql .= " AND s.cohort = :cohort";
        }
        
        $sql .= " ORDER BY c.name, s.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
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
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
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
                LEFT JOIN (
                    SELECT a1.*
                    FROM allocation a1
                    JOIN (
                        SELECT student_id, MAX(alloc_id) as alloc_id
                        FROM allocation
                        WHERE status = 'active'
                        GROUP BY student_id
                    ) latest ON a1.alloc_id = latest.alloc_id
                ) a ON c.site_id = a.site_id
                LEFT JOIN assessment ass ON a.student_id = ass.student_id AND a.site_id = ass.site_id";
        
        if ($site_id) {
            $sql .= " WHERE c.site_id = :site_id";
        }
        
        $sql .= " GROUP BY c.site_id ORDER BY c.name";
        
        $stmt = $this->conn->prepare($sql);
        if ($site_id) {
            $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ LECTURER OPERATIONS ============
    
    public function getLecturers() {
        $query = "SELECT lecturer_id, name, email FROM lecturer ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getLecturersWithSites() {
        $query = "SELECT l.lecturer_id, l.name, l.email,
                         GROUP_CONCAT(DISTINCT cs.name ORDER BY cs.name SEPARATOR ', ') as assigned_sites,
                         GROUP_CONCAT(DISTINCT cs.site_id ORDER BY cs.name SEPARATOR ',') as site_ids
                  FROM lecturer l
                  LEFT JOIN lecturer_site ls ON l.lecturer_id = ls.lecturer_id
                  LEFT JOIN clinical_site cs ON ls.site_id = cs.site_id
                  GROUP BY l.lecturer_id
                  ORDER BY l.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStudentAllocationDetails($student_id) {
        $query = "SELECT a.*, cs.name as site_name, cs.location,
                         DATEDIFF(a.end_date, CURDATE()) as days_remaining,
                         CASE 
                             WHEN a.status = 'completed' THEN 'Completed'
                             WHEN a.end_date < CURDATE() THEN 'Overdue'
                             WHEN DATEDIFF(a.end_date, CURDATE()) <= 7 THEN 'Expiring Soon'
                             WHEN DATEDIFF(a.end_date, CURDATE()) > 7 THEN 'Active'
                             ELSE 'Active'
                         END as placement_status
                  FROM allocation a
                  JOIN clinical_site cs ON a.site_id = cs.site_id
                  WHERE a.student_id = :student_id AND a.status = 'active'
                  ORDER BY a.start_date DESC
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function addLecturer($lecturer_id, $name, $email) {
        // Check if lecturer already exists
        $checkQuery = "SELECT lecturer_id FROM lecturer WHERE lecturer_id = :lecturer_id OR email = :email";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':lecturer_id', $lecturer_id);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false;
        }
        
        $password_hash = password_hash('pass', PASSWORD_DEFAULT);
        $query = "INSERT INTO lecturer (lecturer_id, name, email, password_hash) 
                  VALUES (:lecturer_id, :name, :email, :password_hash)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':lecturer_id', $lecturer_id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        return $stmt->execute();
    }
    
    public function deleteLecturer($lecturer_id) {
        $query = "DELETE FROM lecturer WHERE lecturer_id = :lecturer_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':lecturer_id', $lecturer_id);
        return $stmt->execute();
    }
    
    // ============ MATRON OPERATIONS ============
    
    public function getMatrons() {
        $query = "SELECT m.matron_id, m.name, m.email, m.site_id, 
                         COALESCE(cs.name, 'Not Assigned') as site_name
                  FROM matron m 
                  LEFT JOIN clinical_site cs ON m.site_id = cs.site_id 
                  ORDER BY m.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function addMatron($matron_id, $name, $email, $site_id = null) {
        // Check if matron already exists
        $checkQuery = "SELECT matron_id FROM matron WHERE matron_id = :matron_id OR email = :email";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':matron_id', $matron_id);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            return false;
        }
        
        $password_hash = password_hash('pass', PASSWORD_DEFAULT);
        $query = "INSERT INTO matron (matron_id, name, email, password_hash, site_id) 
                  VALUES (:matron_id, :name, :email, :password_hash, :site_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':matron_id', $matron_id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':site_id', $site_id);
        return $stmt->execute();
    }
    
    public function deleteMatron($matron_id) {
        $query = "DELETE FROM matron WHERE matron_id = :matron_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':matron_id', $matron_id);
        return $stmt->execute();
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