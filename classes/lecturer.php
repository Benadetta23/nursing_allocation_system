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
    
    // Get clinical sites assigned to this lecturer
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
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_type = 'matron' AND site_id = :site_id) as matron_assessed,
                         (SELECT matron_final_submitted FROM assessment WHERE student_id = s.student_id AND assessor_type = 'matron' LIMIT 1) as matron_finalized
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
    
    // Check if matron has finalized assessment for a student
    public function isMatronFinalized($student_id, $site_id) {
        $query = "SELECT matron_final_submitted, daily_marks_aggregate FROM assessment 
                  WHERE student_id = :student_id 
                  AND assessor_type = 'matron' 
                  AND site_id = :site_id 
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'finalized' => !is_null($result['matron_final_submitted'] ?? null),
            'aggregate' => $result['daily_marks_aggregate'] ?? 0
        ];
    }
    
    // Get student daily marking history with finalization status
    public function getStudentDailySummary($student_id, $site_id) {
        $query = "SELECT 
                    COUNT(*) as total_days,
                    ROUND(AVG(punctuality), 1) as avg_punctuality,
                    ROUND(AVG(performance), 1) as avg_performance,
                    ROUND(AVG(behavior), 1) as avg_behavior,
                    ROUND((AVG(punctuality) + AVG(performance) + AVG(behavior)) / 3, 1) as overall_average,
                    SUM(CASE WHEN attendance = 'Present' THEN 1 ELSE 0 END) as days_present,
                    SUM(CASE WHEN attendance = 'Absent' THEN 1 ELSE 0 END) as days_absent,
                    SUM(CASE WHEN attendance = 'Late' THEN 1 ELSE 0 END) as days_late,
                    SUM(CASE WHEN is_finalized = 1 THEN 1 ELSE 0 END) as finalized_days
                  FROM daily_marking 
                  WHERE student_id = :student_id AND site_id = :site_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result['total_days']) {
            $result['total_days'] = 0;
            $result['avg_punctuality'] = 0;
            $result['avg_performance'] = 0;
            $result['avg_behavior'] = 0;
            $result['overall_average'] = 0;
            $result['days_present'] = 0;
            $result['days_absent'] = 0;
            $result['days_late'] = 0;
            $result['finalized_days'] = 0;
        }
        
        return $result;
    }
    
    // Get all daily marks for a student with dates
    public function getStudentDailyMarksList($student_id, $site_id) {
        $query = "SELECT * FROM daily_marking 
                  WHERE student_id = :student_id AND site_id = :site_id 
                  ORDER BY marking_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':site_id', $site_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function saveAssessment($student_id, $site_id, $punctuality, $dressing, $communication, $comments) {
        // Check if matron has finalized assessment
        $matronStatus = $this->isMatronFinalized($student_id, $site_id);
        
        if (!$matronStatus['finalized']) {
            return ['success' => false, 'error' => 'Matron assessment must be completed before lecturer can assess.'];
        }
        
        // Get matron aggregate score
        $matron_aggregate = $matronStatus['aggregate'];
        
        // Get daily averages for weighting
        $dailyAvgQuery = "SELECT 
                            AVG(punctuality) as avg_punctuality, 
                            AVG(performance) as avg_performance, 
                            AVG(behavior) as avg_behavior 
                          FROM daily_marking 
                          WHERE student_id = :student_id AND site_id = :site_id AND is_finalized = 1";
        $dailyAvgStmt = $this->conn->prepare($dailyAvgQuery);
        $dailyAvgStmt->bindParam(':student_id', $student_id);
        $dailyAvgStmt->bindParam(':site_id', $site_id);
        $dailyAvgStmt->execute();
        $dailyAvg = $dailyAvgStmt->fetch(PDO::FETCH_ASSOC);
        
        // Convert 1-5 scale to percentage for daily marks
        $daily_score_percent = 0;
        if ($dailyAvg && $dailyAvg['avg_punctuality'] > 0) {
            $daily_score_raw = ($dailyAvg['avg_punctuality'] + $dailyAvg['avg_performance'] + $dailyAvg['avg_behavior']) / 3;
            $daily_score_percent = round(($daily_score_raw / 5) * 100, 1);
        }
        
        // Lecturer score is already 0-100 scale? If 1-5 scale, convert
        $lecturer_score_raw = ($punctuality + $dressing + $communication) / 3;
        // If values are 1-5, convert to percentage
        if ($punctuality <= 5 && $dressing <= 5 && $communication <= 5) {
            $lecturer_score_percent = round(($lecturer_score_raw / 5) * 100, 1);
        } else {
            $lecturer_score_percent = $lecturer_score_raw;
        }
        
        // Weighted final grade (60% matron aggregate, 40% lecturer score)
        $final_grade = round(($matron_aggregate * 0.6) + ($lecturer_score_percent * 0.4), 1);
        
        // Determine pass/fail based on grade thresholds
        $thresholdQuery = "SELECT status FROM grade_thresholds 
                           WHERE :final_grade BETWEEN min_score AND max_score 
                           LIMIT 1";
        $thresholdStmt = $this->conn->prepare($thresholdQuery);
        $thresholdStmt->bindParam(':final_grade', $final_grade);
        $thresholdStmt->execute();
        $thresholdResult = $thresholdStmt->fetch(PDO::FETCH_ASSOC);
        $pass_fail_status = $thresholdResult['status'] ?? 'Fail';
        
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
                          assessment_date = CURDATE(),
                          daily_marks_aggregate = :daily_marks_aggregate,
                          final_grade = :final_grade,
                          pass_fail_status = :pass_fail_status,
                          lecturer_final_submitted = NOW()
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
            $stmt->bindParam(':daily_marks_aggregate', $matron_aggregate);
            $stmt->bindParam(':final_grade', $final_grade);
            $stmt->bindParam(':pass_fail_status', $pass_fail_status);
        } else {
            // Insert new FINAL assessment (lecturer assessment)
            $query = "INSERT INTO assessment (student_id, assessor_id, site_id, assessment_date, 
                      punctuality_score, dressing_score, communication_score, comments, assessor_type,
                      daily_marks_aggregate, final_grade, pass_fail_status, lecturer_final_submitted) 
                      VALUES (:student_id, :lecturer_id, :site_id, CURDATE(), 
                      :punctuality, :dressing, :communication, :comments, 'lecturer',
                      :daily_marks_aggregate, :final_grade, :pass_fail_status, NOW())";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':lecturer_id', $this->lecturer_id);
            $stmt->bindParam(':site_id', $site_id);
            $stmt->bindParam(':punctuality', $punctuality);
            $stmt->bindParam(':dressing', $dressing);
            $stmt->bindParam(':communication', $communication);
            $stmt->bindParam(':comments', $comments);
            $stmt->bindParam(':daily_marks_aggregate', $matron_aggregate);
            $stmt->bindParam(':final_grade', $final_grade);
            $stmt->bindParam(':pass_fail_status', $pass_fail_status);
        }
        
        $result = $stmt->execute();
        
        if ($result) {
            return [
                'success' => true, 
                'final_grade' => $final_grade, 
                'status' => $pass_fail_status,
                'matron_score' => $matron_aggregate,
                'lecturer_score' => $lecturer_score_percent
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to save assessment.'];
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
    
    // Get students that are ready for assessment (matron finalized, lecturer not done)
    public function getStudentsReadyForAssessment($site_id) {
        $query = "SELECT DISTINCT s.student_id, s.name, s.student_number, s.cohort, s.program,
                         a.alloc_id, a.start_date, a.end_date, a.role,
                         ass.daily_marks_aggregate as matron_aggregate,
                         ass.matron_final_submitted,
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_id = :lecturer_id AND site_id = :site_id AND assessor_type = 'lecturer') as already_assessed
                  FROM allocation a 
                  JOIN student s ON a.student_id = s.student_id
                  JOIN assessment ass ON s.student_id = ass.student_id
                  WHERE a.site_id = :site_id 
                  AND a.status = 'active'
                  AND ass.matron_final_submitted IS NOT NULL
                  AND ass.lecturer_final_submitted IS NULL
                  AND ass.assessor_type = 'matron'
                  GROUP BY s.student_id
                  ORDER BY s.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':site_id', $site_id);
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
                         (SELECT matron_final_submitted FROM assessment WHERE student_id = s.student_id AND assessor_type = 'matron' AND site_id = :site_id LIMIT 1) as matron_finalized
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
                         (SELECT COUNT(*) FROM assessment WHERE student_id = s.student_id AND assessor_id = :lecturer_id AND site_id = :site_id AND assessor_type = 'lecturer') as already_assessed,
                         (SELECT matron_final_submitted FROM assessment WHERE student_id = s.student_id AND assessor_type = 'matron' AND site_id = :site_id LIMIT 1) as matron_finalized
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