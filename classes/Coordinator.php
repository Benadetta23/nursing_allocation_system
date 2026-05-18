<?php
require_once 'Database.php';

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
        $query = "INSERT INTO allocation (student_id, site_id, start_date, end_date, role) 
                  VALUES (:student_id, :site_id, :start_date, :end_date, :role)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->bindParam(':role', $role);
        return $stmt->execute();
    }
    
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