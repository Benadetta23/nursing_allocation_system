<?php
require_once 'Database.php';

class Lecturer {
    private $conn;
    private $lecturer_id;
    
    public function __construct($lecturer_id = null) {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->lecturer_id = $lecturer_id;
    }
    
    public function setLecturerId($lecturer_id) {
        $this->lecturer_id = $lecturer_id;
    }
    
    public function getClinicalSites() {
        $query = "SELECT * FROM clinical_site ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStudentsBySite($site_id) {
        $query = "SELECT s.*, a.alloc_id, a.start_date, a.end_date, a.role,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND lecturer_id = :lecturer_id AND site_id = :site_id) as already_assessed
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id
                  WHERE a.site_id = :site_id AND a.status = 'active'
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getExistingAssessment($student_id, $site_id) {
        $query = "SELECT * FROM assessment 
                  WHERE student_id = :student_id AND lecturer_id = :lecturer_id AND site_id = :site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function saveAssessment($student_id, $site_id, $punctuality, $dressing, $communication, $comments) {
        // Check if assessment already exists
        $existing = $this->getExistingAssessment($student_id, $site_id);
        
        if ($existing) {
            // Update existing assessment
            $query = "UPDATE assessment 
                      SET punctuality_score = :punctuality, 
                          dressing_score = :dressing, 
                          communication_score = :communication, 
                          comments = :comments, 
                          assessment_date = CURDATE()
                      WHERE student_id = :student_id AND lecturer_id = :lecturer_id AND site_id = :site_id";
            $stmt = $this->conn->prepare($query);
        } else {
            // Insert new assessment
            $query = "INSERT INTO assessment (student_id, lecturer_id, site_id, assessment_date, punctuality_score, dressing_score, communication_score, comments) 
                      VALUES (:student_id, :lecturer_id, :site_id, CURDATE(), :punctuality, :dressing, :communication, :comments)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        }
        
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':punctuality', $punctuality);
        $stmt->bindParam(':dressing', $dressing);
        $stmt->bindParam(':communication', $communication);
        $stmt->bindParam(':comments', $comments);
        
        return $stmt->execute();
    }
    
    public function getAssessmentHistory() {
        $query = "SELECT a.*, s.name as student_name, s.student_number, c.name as site_name
                  FROM assessment a
                  JOIN student s ON a.student_id = s.student_id
                  JOIN clinical_site c ON a.site_id = c.site_id
                  WHERE a.lecturer_id = :lecturer_id
                  ORDER BY a.assessment_date DESC
                  LIMIT 50";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>