<?php
require_once 'Database.php';

class Coordinator {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // ============ SITE OPERATIONS ============
    
    public function addSite($name, $location, $contact_person, $contact_phone, $capacity) {
        $query = "INSERT INTO clinical_site (name, location, contact_person, contact_phone, capacity) 
                  VALUES (:name, :location, :contact_person, :contact_phone, :capacity)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':contact_person', $contact_person);
        $stmt->bindParam(':contact_phone', $contact_phone);
        $stmt->bindParam(':capacity', $capacity);
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
    
    public function addStudent($student_number, $name, $email, $cohort, $program, $coordinator_id) {
        $password_hash = password_hash('pass', PASSWORD_DEFAULT);
        $query = "INSERT INTO student (student_number, name, email, password_hash, cohort, program, coordinator_id) 
                  VALUES (:student_number, :name, :email, :password_hash, :cohort, :program, :coordinator_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_number', $student_number);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':cohort', $cohort);
        $stmt->bindParam(':program', $program);
        $stmt->bindParam(':coordinator_id', $coordinator_id);
        return $stmt->execute();
    }
    
    public function getStudents() {
        $query = "SELECT * FROM student ORDER BY name";
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
    
    public function deleteAllocation($alloc_id) {
        $query = "DELETE FROM allocation WHERE alloc_id = :alloc_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':alloc_id', $alloc_id);
        return $stmt->execute();
    }
}
?>