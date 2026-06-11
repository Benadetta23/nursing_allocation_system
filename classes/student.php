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
        $query = "SELECT DISTINCT a.assess_id,
                         a.assessor_type,
                         CASE 
                             WHEN a.assessor_type = 'lecturer' THEN COALESCE(l.name, 'Lecturer')
                             WHEN a.assessor_type = 'matron' THEN COALESCE(m.name, 'Matron')
                             ELSE 'Unknown'
                         END as assessor_name,
                         c.name as site_name,
                         a.punctuality_score, a.dressing_score, a.communication_score, a.comments, a.assessment_date
                  FROM assessment a
                  LEFT JOIN lecturer l ON a.assessor_id = l.lecturer_id AND a.assessor_type = 'lecturer'
                  LEFT JOIN matron m ON a.assessor_id = m.matron_id AND a.assessor_type = 'matron'
                  LEFT JOIN clinical_site c ON a.site_id = c.site_id
                  WHERE a.student_id = :student_id
                  GROUP BY a.assess_id
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
    
    // Get assessment progress (which stages are complete)
    public function getAssessmentProgress() {
        $query = "SELECT 
                    COUNT(CASE WHEN assessor_type = 'matron' THEN 1 END) as matron_initial_done,
                    COUNT(CASE WHEN assessor_type = 'lecturer' THEN 1 END) as lecturer_final_done
                  FROM assessment
                  WHERE student_id = :student_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $matronDone = ($result['matron_initial_done'] > 0);
        $lecturerDone = ($result['lecturer_final_done'] > 0);
        
        $status = [];
        $status['matron_done'] = $matronDone;
        $status['lecturer_done'] = $lecturerDone;
        $status['both_done'] = ($matronDone && $lecturerDone);
        
        if (!$matronDone) {
            $status['message'] = 'Initial Assessment (Matron) Pending';
            $status['badge_class'] = 'badge-warning';
        } elseif ($matronDone && !$lecturerDone) {
            $status['message'] = 'Initial Complete - Final Assessment (Lecturer) Pending';
            $status['badge_class'] = 'badge-info';
        } else {
            $status['message'] = 'Both Assessments Complete - Final Grade Ready';
            $status['badge_class'] = 'badge-success';
        }
        
        return $status;
    }
    
    // ============ FINAL GRADE CALCULATION ============
    
    public function getFinalGrade() {
        // Check if lecturer assessment exists
        $checkQuery = "SELECT COUNT(CASE WHEN assessor_type = 'lecturer' THEN 1 END) as lecturer_count
                      FROM assessment
                      WHERE student_id = :student_id AND lecturer_final_submitted IS NOT NULL";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $this->student_id);
        $checkStmt->execute();
        $counts = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Only show final grade if lecturer has finalized
        if ($counts['lecturer_count'] == 0) {
            return null;
        }
        
        // Get the lecturer's final assessment data (same calculation used by Lecturer::saveAssessment)
        $query = "SELECT final_grade, pass_fail_status, daily_marks_aggregate
                  FROM assessment
                  WHERE student_id = :student_id AND assessor_type = 'lecturer' AND lecturer_final_submitted IS NOT NULL
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['final_grade'] !== null) {
            $final_grade = round($result['final_grade'], 1);
            $pass_fail = $result['pass_fail_status'] ?? 'Pending';
            $matron_agg = round($result['daily_marks_aggregate'] ?? 0, 1);
            
            // Determine letter grade from the percentage
            if ($final_grade >= 80) {
                $grade = 'A+';
                $grade_description = 'Excellent';
            } elseif ($final_grade >= 70) {
                $grade = 'A';
                $grade_description = 'Very Good';
            } elseif ($final_grade >= 65) {
                $grade = 'B+';
                $grade_description = 'Good';
            } elseif ($final_grade >= 60) {
                $grade = 'B';
                $grade_description = 'Satisfactory';
            } elseif ($final_grade >= 50) {
                $grade = 'C';
                $grade_description = 'Average';
            } elseif ($final_grade >= 40) {
                $grade = 'D';
                $grade_description = 'Below Average';
            } else {
                $grade = 'F';
                $grade_description = 'Fail';
            }
            
            return [
                'score' => $final_grade,
                'grade' => $grade,
                'grade_description' => $grade_description,
                'pass_fail_status' => $pass_fail,
                'matron_aggregate' => $matron_agg,
                'matron_done' => true,
                'lecturer_done' => true
            ];
        }
        return null;
    }
}
?>