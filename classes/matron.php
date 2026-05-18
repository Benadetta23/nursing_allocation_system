<?php
require_once 'Database.php';

class Matron {
    private $conn;
    private $matron_id;
    
    public function __construct($matron_id = null) {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->matron_id = $matron_id;
    }
    
    public function setMatronId($matron_id) {
        $this->matron_id = $matron_id;
    }
    
    public function getAssignedSite() {
        $query = "SELECT cs.* FROM clinical_site cs 
                  JOIN matron m ON cs.site_id = m.site_id 
                  WHERE m.matron_id = :matron_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getAllClinicalSites() {
        $query = "SELECT * FROM clinical_site ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStudentsAtSite($site_id) {
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         a.alloc_id, a.start_date, a.end_date, a.role, a.site_id,
                         CASE 
                             WHEN EXISTS (SELECT 1 FROM assessment ass 
                                          WHERE ass.student_id = s.student_id 
                                          AND ass.assessor_id = :matron_id 
                                          AND ass.assessor_type = 'matron' 
                                          AND ass.site_id = :site_id) 
                             THEN 1 
                             ELSE 0 
                         END as already_assessed
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id
                  WHERE a.site_id = :site_id AND a.status = 'active'
                  GROUP BY s.student_id
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function saveAssessment($student_id, $site_id, $punctuality, $dressing, $communication, $comments) {
        // Check if initial assessment already exists from this matron
        $checkQuery = "SELECT assess_id FROM assessment 
                       WHERE student_id = :student_id 
                       AND assessor_id = :matron_id 
                       AND assessor_type = 'matron' 
                       AND site_id = :site_id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->bindParam(':matron_id', $this->matron_id);
        $checkStmt->bindParam(':site_id', $site_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            // Update existing initial assessment
            $query = "UPDATE assessment 
                      SET punctuality_score = :punctuality, 
                          dressing_score = :dressing, 
                          communication_score = :communication, 
                          comments = :comments, 
                          assessment_date = CURDATE()
                      WHERE student_id = :student_id 
                      AND assessor_id = :matron_id 
                      AND assessor_type = 'matron' 
                      AND site_id = :site_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':punctuality', $punctuality);
            $stmt->bindParam(':dressing', $dressing);
            $stmt->bindParam(':communication', $communication);
            $stmt->bindParam(':comments', $comments);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':matron_id', $this->matron_id);
            $stmt->bindParam(':site_id', $site_id);
        } else {
            // Insert new INITIAL assessment
            $query = "INSERT INTO assessment (student_id, assessor_id, assessor_type, site_id, assessment_date, 
                      punctuality_score, dressing_score, communication_score, comments, assessment_type) 
                      VALUES (:student_id, :matron_id, 'matron', :site_id, CURDATE(), 
                      :punctuality, :dressing, :communication, :comments, 'initial')";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':matron_id', $this->matron_id);
            $stmt->bindParam(':site_id', $site_id);
            $stmt->bindParam(':punctuality', $punctuality);
            $stmt->bindParam(':dressing', $dressing);
            $stmt->bindParam(':communication', $communication);
            $stmt->bindParam(':comments', $comments);
        }
        
        return $stmt->execute();
    }
    
    public function getAssessmentHistory() {
        $query = "SELECT a.*, s.name as student_name, s.student_number, c.name as site_name
                  FROM assessment a
                  JOIN student s ON a.student_id = s.student_id
                  JOIN clinical_site c ON a.site_id = c.site_id
                  WHERE a.assessor_id = :matron_id AND a.assessor_type = 'matron'
                  ORDER BY a.assessment_date DESC
                  LIMIT 50";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>