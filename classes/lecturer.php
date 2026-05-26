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
    
    // Get clinical sites assigned to this lecturer (NEW METHOD)
    public function getAssignedSites() {
        $query = "SELECT cs.* FROM clinical_site cs
                  JOIN lecturer_site ls ON cs.site_id = ls.site_id
                  WHERE ls.lecturer_id = :lecturer_id
                  ORDER BY cs.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get all clinical sites (for backward compatibility)
    public function getClinicalSites() {
        $query = "SELECT * FROM clinical_site ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStudentsBySite($site_id) {
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         a.alloc_id, a.start_date, a.end_date, a.role,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_id = :lecturer_id AND site_id = :site_id AND assessor_type = 'lecturer') as already_assessed,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_type = 'matron' AND site_id = :site_id) as matron_assessed
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id
                  WHERE a.site_id = :site_id AND a.status = 'active'
                  GROUP BY s.student_id
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getExistingAssessment($student_id, $site_id) {
        $query = "SELECT * FROM assessment 
                  WHERE student_id = :student_id AND assessor_id = :lecturer_id AND site_id = :site_id AND assessor_type = 'lecturer'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function saveAssessment($student_id, $site_id, $punctuality, $dressing, $communication, $comments) {
        // Check if final assessment already exists from this lecturer
        $checkQuery = "SELECT assess_id FROM assessment 
                       WHERE student_id = :student_id 
                       AND assessor_id = :lecturer_id 
                       AND site_id = :site_id 
                       AND assessor_type = 'lecturer'";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->bindParam(':lecturer_id', $this->lecturer_id);
        $checkStmt->bindParam(':site_id', $site_id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            // Update existing final assessment
            $query = "UPDATE assessment 
                      SET punctuality_score = :punctuality, 
                          dressing_score = :dressing, 
                          communication_score = :communication, 
                          comments = :comments, 
                          assessment_date = CURDATE()
                      WHERE student_id = :student_id 
                      AND assessor_id = :lecturer_id 
                      AND site_id = :site_id 
                      AND assessor_type = 'lecturer'";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':punctuality', $punctuality);
            $stmt->bindParam(':dressing', $dressing);
            $stmt->bindParam(':communication', $communication);
            $stmt->bindParam(':comments', $comments);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':lecturer_id', $this->lecturer_id);
            $stmt->bindParam(':site_id', $site_id);
        } else {
            // Insert new FINAL assessment (lecturer assessment)
            $query = "INSERT INTO assessment (student_id, assessor_id, site_id, assessment_date, 
                      punctuality_score, dressing_score, communication_score, comments, assessor_type) 
                      VALUES (:student_id, :lecturer_id, :site_id, CURDATE(), 
                      :punctuality, :dressing, :communication, :comments, 'lecturer')";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':lecturer_id', $this->lecturer_id);
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
                  WHERE a.assessor_id = :lecturer_id AND a.assessor_type = 'lecturer'
                  ORDER BY a.assessment_date DESC
                  LIMIT 50";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ============ DAILY MARKED STUDENTS (PRIORITY LIST) ============
    
    public function getStudentsWithDailyMarks($site_id) {
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         a.alloc_id, a.start_date, a.end_date, a.role,
                         COUNT(dm.daily_mark_id) as daily_mark_count,
                         AVG(dm.performance) as avg_performance,
                         MAX(dm.marking_date) as last_marked_date,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_id = :lecturer_id AND site_id = :site_id AND assessor_type = 'lecturer') as already_assessed,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_type = 'matron' AND site_id = :site_id) as matron_assessed
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id
                  LEFT JOIN daily_marking dm ON s.student_id = dm.student_id AND a.site_id = dm.site_id
                  WHERE a.site_id = :site_id AND a.status = 'active'
                  GROUP BY s.student_id
                  ORDER BY dm.marking_date DESC, s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getTodaysDailyMarkedStudents($site_id) {
        $marking_date = date('Y-m-d');
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         dm.attendance, dm.punctuality, dm.performance, dm.behavior, dm.comments as daily_comments,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_id = :lecturer_id AND site_id = :site_id AND assessor_type = 'lecturer') as already_assessed
                  FROM daily_marking dm
                  JOIN student s ON dm.student_id = s.student_id
                  WHERE dm.site_id = :site_id AND dm.marking_date = :marking_date
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':marking_date', $marking_date);
        $stmt->bindParam(':lecturer_id', $this->lecturer_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStudentDailyMarkHistory($student_id, $site_id, $num_days = 7) {
        $query = "SELECT dm.*, s.name as student_name
                  FROM daily_marking dm
                  JOIN student s ON dm.student_id = s.student_id
                  WHERE dm.student_id = :student_id 
                  AND dm.site_id = :site_id
                  AND dm.marking_date >= DATE_SUB(CURDATE(), INTERVAL :num_days DAY)
                  ORDER BY dm.marking_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':num_days', $num_days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Save daily marking for a student
    public function saveDailyMarking($student_id, $site_id, $attendance, $punctuality, $performance, $behavior, $comments) {
        $marking_date = date('Y-m-d');
        
        // Check if daily marking already exists for today
        $checkQuery = "SELECT daily_mark_id FROM daily_marking 
                       WHERE student_id = :student_id 
                       AND site_id = :site_id 
                       AND marking_date = :marking_date";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->bindParam(':site_id', $site_id);
        $checkStmt->bindParam(':marking_date', $marking_date);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            // Update existing daily marking
            $query = "UPDATE daily_marking 
                      SET attendance = :attendance,
                          punctuality = :punctuality,
                          performance = :performance,
                          behavior = :behavior,
                          comments = :comments
                      WHERE student_id = :student_id 
                      AND site_id = :site_id 
                      AND marking_date = :marking_date";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':attendance', $attendance);
            $stmt->bindParam(':punctuality', $punctuality);
            $stmt->bindParam(':performance', $performance);
            $stmt->bindParam(':behavior', $behavior);
            $stmt->bindParam(':comments', $comments);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':site_id', $site_id);
            $stmt->bindParam(':marking_date', $marking_date);
        } else {
            // Insert new daily marking
            $query = "INSERT INTO daily_marking (student_id, site_id, marking_date, 
                      attendance, punctuality, performance, behavior, comments) 
                      VALUES (:student_id, :site_id, :marking_date, 
                      :attendance, :punctuality, :performance, :behavior, :comments)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':site_id', $site_id);
            $stmt->bindParam(':marking_date', $marking_date);
            $stmt->bindParam(':attendance', $attendance);
            $stmt->bindParam(':punctuality', $punctuality);
            $stmt->bindParam(':performance', $performance);
            $stmt->bindParam(':behavior', $behavior);
            $stmt->bindParam(':comments', $comments);
        }
        
        return $stmt->execute();
    }
    
    // Get students who haven't been marked today
    public function getUnmarkedStudents($site_id) {
        $marking_date = date('Y-m-d');
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         a.alloc_id, a.start_date, a.end_date, a.role,
                         (SELECT COUNT(*) FROM daily_marking WHERE student_id = s.student_id AND site_id = :site_id AND marking_date = :marking_date) as marked_today
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id
                  WHERE a.site_id = :site_id AND a.status = 'active'
                  HAVING marked_today = 0
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':marking_date', $marking_date);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>