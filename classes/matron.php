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
    
    // Get the clinical site assigned to this matron
    public function getAssignedSite() {
        $query = "SELECT cs.* FROM clinical_site cs 
                  JOIN matron m ON cs.site_id = m.site_id 
                  WHERE m.matron_id = :matron_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get all clinical sites (for coordinator use only)
    public function getAllClinicalSites() {
        $query = "SELECT * FROM clinical_site ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get students at a specific site
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
    
    // Save initial assessment
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
                      punctuality_score, dressing_score, communication_score, comments) 
                      VALUES (:student_id, :matron_id, 'matron', :site_id, CURDATE(), 
                      :punctuality, :dressing, :communication, :comments)";
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
    
    // Get assessment history for this matron
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
    
    // ============ DAILY MARKING OPERATIONS ============
    
    // Save or update daily mark for a student
    public function saveOrUpdateDailyMark($student_id, $site_id, $attendance, $punctuality, $performance, $behavior, $comments) {
        $marking_date = date('Y-m-d');
        
        // Check if daily mark for this date already exists
        $checkQuery = "SELECT daily_mark_id FROM daily_marking 
                       WHERE student_id = :student_id 
                       AND matron_id = :matron_id 
                       AND site_id = :site_id 
                       AND marking_date = :marking_date";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->bindParam(':matron_id', $this->matron_id);
        $checkStmt->bindParam(':site_id', $site_id);
        $checkStmt->bindParam(':marking_date', $marking_date);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            // Update existing daily mark
            $query = "UPDATE daily_marking 
                      SET attendance = :attendance, 
                          punctuality = :punctuality, 
                          performance = :performance, 
                          behavior = :behavior, 
                          comments = :comments
                      WHERE student_id = :student_id 
                      AND matron_id = :matron_id 
                      AND site_id = :site_id 
                      AND marking_date = :marking_date";
        } else {
            // Insert new daily mark
            $query = "INSERT INTO daily_marking (student_id, matron_id, site_id, marking_date, attendance, punctuality, performance, behavior, comments) 
                      VALUES (:student_id, :matron_id, :site_id, :marking_date, :attendance, :punctuality, :performance, :behavior, :comments)";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':marking_date', $marking_date);
        $stmt->bindParam(':attendance', $attendance);
        $stmt->bindParam(':punctuality', $punctuality);
        $stmt->bindParam(':performance', $performance);
        $stmt->bindParam(':behavior', $behavior);
        $stmt->bindParam(':comments', $comments);
        
        return $stmt->execute();
    }
    
    // Get daily marking history for a site
    public function getDailyMarkingHistory($site_id, $num_days = 7) {
        $query = "SELECT dm.*, s.name as student_name, s.student_number, 
                         AVG(dm.performance) as avg_performance,
                         COUNT(*) as total_marks
                  FROM daily_marking dm
                  JOIN student s ON dm.student_id = s.student_id
                  WHERE dm.site_id = :site_id 
                  AND dm.marking_date >= DATE_SUB(CURDATE(), INTERVAL :num_days DAY)
                  GROUP BY dm.student_id
                  ORDER BY dm.marking_date DESC, s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':num_days', $num_days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get today's daily marks for a site
    public function getTodaysDailyMarks($site_id) {
        $marking_date = date('Y-m-d');
        $query = "SELECT dm.*, s.name as student_name, s.student_number
                  FROM daily_marking dm
                  JOIN student s ON dm.student_id = s.student_id
                  WHERE dm.site_id = :site_id AND dm.marking_date = :marking_date
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->bindParam(':marking_date', $marking_date);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get average daily performance for a student
    public function getStudentDailyAverage($student_id, $site_id) {
        $query = "SELECT 
                    AVG(CASE WHEN punctuality IS NOT NULL THEN punctuality ELSE 0 END) as avg_punctuality,
                    AVG(CASE WHEN performance IS NOT NULL THEN performance ELSE 0 END) as avg_performance,
                    AVG(CASE WHEN behavior IS NOT NULL THEN behavior ELSE 0 END) as avg_behavior,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN attendance = 'Present' THEN 1 ELSE 0 END) as days_present,
                    SUM(CASE WHEN attendance = 'Absent' THEN 1 ELSE 0 END) as days_absent,
                    SUM(CASE WHEN attendance = 'Late' THEN 1 ELSE 0 END) as days_late
                  FROM daily_marking 
                  WHERE student_id = :student_id AND site_id = :site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['total_days'] > 0) {
            $result['overall_average'] = round(
                ($result['avg_punctuality'] + $result['avg_performance'] + $result['avg_behavior']) / 3, 
                1
            );
        } else {
            $result['overall_average'] = null;
        }
        
        return $result;
    }
    
    // Calculate aggregate of all daily marks for a student
    public function getDailyMarksAggregate($student_id) {
        $query = "SELECT AVG(
                    (COALESCE(punctuality, 0) + COALESCE(performance, 0) + COALESCE(behavior, 0)) / 3
                  ) as aggregate
                  FROM daily_marking 
                  WHERE student_id = :student_id AND is_finalized = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return round($result['aggregate'] ?? 0, 2);
    }
    
    // Submit final matron assessment which locks daily marks
    public function submitFinalMatronAssessment($student_id) {
        $aggregate = $this->getDailyMarksAggregate($student_id);
        
        $query = "UPDATE assessment 
                  SET daily_marks_aggregate = :aggregate, 
                      matron_final_submitted = NOW() 
                  WHERE student_id = :student_id 
                  AND assessor_id = :matron_id 
                  AND assessor_type = 'matron'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':aggregate', $aggregate);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $result = $stmt->execute();
        
        $query2 = "UPDATE daily_marking 
                   SET is_finalized = 1, finalized_at = NOW() 
                   WHERE student_id = :student_id";
        $stmt2 = $this->conn->prepare($query2);
        $stmt2->bindParam(':student_id', $student_id);
        $stmt2->execute();
        
        return $result;
    }
    
    // Check if matron has already submitted final assessment
    public function isMatronAssessmentFinalized($student_id) {
        $query = "SELECT matron_final_submitted FROM assessment 
                  WHERE student_id = :student_id 
                  AND assessor_id = :matron_id 
                  AND assessor_type = 'matron'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':matron_id', $this->matron_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return !is_null($result['matron_final_submitted'] ?? null);
    }
    
    // Get students ready for lecturer assessment (matron finalized)
    public function getStudentsReadyForLecturer($site_id) {
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         a.daily_marks_aggregate, a.matron_final_submitted,
                         al.role, al.start_date, al.end_date
                  FROM allocation al 
                  JOIN student s ON al.student_id = s.student_id
                  JOIN assessment a ON s.student_id = a.student_id
                  WHERE al.site_id = :site_id 
                  AND al.status = 'active'
                  AND a.matron_final_submitted IS NOT NULL
                  AND a.lecturer_final_submitted IS NULL
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>