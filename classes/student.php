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
        // Check if both assessments exist
        $checkQuery = "SELECT 
                        COUNT(CASE WHEN assessor_type = 'matron' THEN 1 END) as matron_count,
                        COUNT(CASE WHEN assessor_type = 'lecturer' THEN 1 END) as lecturer_count
                      FROM assessment
                      WHERE student_id = :student_id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $this->student_id);
        $checkStmt->execute();
        $counts = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Only calculate final grade if BOTH assessments exist
        if ($counts['matron_count'] == 0 || $counts['lecturer_count'] == 0) {
            return null; // Not ready for final grade
        }
        
        // Calculate final grade from both assessments
        $query = "SELECT 
                    AVG((punctuality_score + dressing_score + communication_score) / 3) as final_score,
                    COUNT(assess_id) as total_assessments
                  FROM assessment
                  WHERE student_id = :student_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['final_score']) {
            $score = round($result['final_score'], 1);
            
            // Determine grade based on score
            if ($score >= 4.5) {
                $grade = 'A+';
                $grade_description = 'Excellent';
            } elseif ($score >= 4.0) {
                $grade = 'A';
                $grade_description = 'Very Good';
            } elseif ($score >= 3.5) {
                $grade = 'B+';
                $grade_description = 'Good';
            } elseif ($score >= 3.0) {
                $grade = 'B';
                $grade_description = 'Satisfactory';
            } elseif ($score >= 2.5) {
                $grade = 'C';
                $grade_description = 'Average';
            } elseif ($score >= 2.0) {
                $grade = 'D';
                $grade_description = 'Below Average';
            } else {
                $grade = 'F';
                $grade_description = 'Fail';
            }
            
            return [
                'score' => $score,
                'grade' => $grade,
                'grade_description' => $grade_description,
                'total_assessments' => $result['total_assessments'],
                'matron_done' => ($counts['matron_count'] > 0),
                'lecturer_done' => ($counts['lecturer_count'] > 0)
            ];
        }
        return null;
    }
}
?>