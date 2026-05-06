<?php
require_once 'Database.php';

class Student {
    private $conn;
    private $student_id;
    
    public function __construct($student_id = null) {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->student_id = $student_id;
    }
    
    public function setStudentId($student_id) {
        $this->student_id = $student_id;
    }
    
    public function getPlacement() {
        $query = "SELECT a.*, c.name as site_name, c.location, c.contact_person, c.contact_phone,
                         s.cohort, s.program, s.student_number
                  FROM allocation a 
                  JOIN clinical_site c ON a.site_id = c.site_id 
                  JOIN student s ON a.student_id = s.student_id
                  WHERE a.student_id = :student_id AND a.status = 'active'
                  ORDER BY a.start_date DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getResults() {
        $query = "SELECT a.*, l.name as lecturer_name, c.name as site_name,
                         a.punctuality_score, a.dressing_score, a.communication_score, a.comments, a.assessment_date
                  FROM assessment a
                  JOIN lecturer l ON a.lecturer_id = l.lecturer_id
                  JOIN clinical_site c ON a.site_id = c.site_id
                  WHERE a.student_id = :student_id
                  ORDER BY a.assessment_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStudentInfo() {
        $query = "SELECT * FROM student WHERE student_id = :student_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>