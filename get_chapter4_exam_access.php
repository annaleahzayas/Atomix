<?php
header("Content-Type: application/json; charset=UTF-8");

$conn = new mysqli("localhost", "root", "", "atomix_db");
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}
$conn->set_charset("utf8mb4");

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$class_id_override = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$exam_key = isset($_GET['exam_key']) ? trim($_GET['exam_key']) : 'post_chapter_4_exam';
$chapter_order = 4;

if ($student_id <= 0) {
    echo json_encode(["status" => "error", "message" => "student_id is required"]);
    exit;
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS class_exam_access (
        class_id INT NOT NULL,
        exam_key VARCHAR(64) NOT NULL,
        is_locked TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (class_id, exam_key),
        KEY idx_class_exam_access_key (exam_key),
        CONSTRAINT fk_class_exam_access_class FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$class_id = $class_id_override > 0 ? $class_id_override : 0;
$classSql = "SELECT cs.class_id
             FROM class_students cs
             JOIN classes c ON c.class_id = cs.class_id
             JOIN school_year sy ON sy.sy_id = c.sy_id
             WHERE cs.student_id = ? AND sy.is_active = 1
             ORDER BY cs.joined_at DESC, cs.class_stud_id DESC
             LIMIT 1";
$classStmt = $conn->prepare($classSql);
if ($classStmt && $class_id <= 0) {
    $classStmt->bind_param("i", $student_id);
    $classStmt->execute();
    $classRes = $classStmt->get_result();
    if ($classRes && $classRes->num_rows > 0) {
        $classRow = $classRes->fetch_assoc();
        $class_id = (int)($classRow['class_id'] ?? 0);
    }
}
if ($classStmt) {
    $classStmt->close();
}

$is_locked = 1;
if ($class_id > 0) {
    $lockStmt = $conn->prepare("SELECT is_locked FROM class_exam_access WHERE class_id = ? AND exam_key = ? LIMIT 1");
    if ($lockStmt) {
        $lockStmt->bind_param("is", $class_id, $exam_key);
        $lockStmt->execute();
        $lockRes = $lockStmt->get_result();
        if ($lockRes && $lockRes->num_rows > 0) {
            $lockRow = $lockRes->fetch_assoc();
            $is_locked = (int)($lockRow['is_locked'] ?? 1);
        }
        $lockStmt->close();
    }
}

$chapter4_id = 0;
$total_stages = 0;
$completed_stages = 0;
$chapter4_completed = 0;

$chStmt = $conn->prepare("SELECT chapter_id FROM chapters WHERE chapter_order = ? LIMIT 1");
if ($chStmt) {
    $chStmt->bind_param("i", $chapter_order);
    $chStmt->execute();
    $chRes = $chStmt->get_result();
    if ($chRes && $chRes->num_rows > 0) {
        $chRow = $chRes->fetch_assoc();
        $chapter4_id = (int)($chRow['chapter_id'] ?? 0);
    }
    $chStmt->close();
}

if ($chapter4_id > 0) {
    $totalStmt = $conn->prepare("SELECT COUNT(*) AS total_stages FROM stages WHERE chapter_id = ?");
    if ($totalStmt) {
        $totalStmt->bind_param("i", $chapter4_id);
        $totalStmt->execute();
        $totalRow = $totalStmt->get_result()->fetch_assoc();
        $total_stages = (int)($totalRow['total_stages'] ?? 0);
        $totalStmt->close();
    }

    if ($total_stages > 0) {
        $doneStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT gp.stage_id) AS completed_stages
             FROM game_progress gp
             JOIN stages s ON s.stage_id = gp.stage_id
             WHERE gp.student_id = ? AND s.chapter_id = ? AND gp.status = 'completed'"
        );
        if ($doneStmt) {
            $doneStmt->bind_param("ii", $student_id, $chapter4_id);
            $doneStmt->execute();
            $doneRow = $doneStmt->get_result()->fetch_assoc();
            $completed_stages = (int)($doneRow['completed_stages'] ?? 0);
            $doneStmt->close();
        }

        if ($completed_stages >= $total_stages) {
            $chapter4_completed = 1;
        }
    } else {
        // If Chapter 4 has no stages configured yet, don't block exam by progression.
        $chapter4_completed = 1;
    }
} else {
    // If Chapter order 4 is not yet configured, don't block exam by progression.
    $chapter4_completed = 1;
}

$can_take_exam = ($is_locked === 0 && $chapter4_completed === 1) ? 1 : 0;

$message = "";
if ($is_locked === 1) {
    $message = "Exam is locked by teacher schedule.";
} elseif ($chapter4_completed !== 1) {
    $message = "Complete all Chapter 4 stages first.";
}

echo json_encode([
    "status" => "success",
    "class_id" => $class_id,
    "exam_key" => $exam_key,
    "is_locked" => $is_locked,
    "chapter4_completed" => $chapter4_completed,
    "total_stages" => $total_stages,
    "completed_stages" => $completed_stages,
    "can_take_exam" => $can_take_exam,
    "message" => $message
]);
