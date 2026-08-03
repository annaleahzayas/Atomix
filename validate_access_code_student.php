<?php
header("Content-Type: application/json; charset=UTF-8");

$conn = new mysqli("localhost", "root", "", "atomix_db");
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}
$conn->set_charset("utf8mb4");

$access_code = trim($_POST["access_code"] ?? "");
$student_id  = (int)($_POST["student_id"] ?? 0);

if ($access_code === "") {
    echo json_encode(["status" => "error", "message" => "Please enter access code"]);
    exit;
}

if ($student_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Student ID is required"]);
    exit;
}

/**
 * 1) Validate student exists
 */
$st = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? LIMIT 1");
$st->bind_param("i", $student_id);
$st->execute();
$stRes = $st->get_result();
if (!$stRes->fetch_assoc()) {
    echo json_encode(["status" => "error", "message" => "Student not found"]);
    exit;
}

/**
 * 2) Validate access code is active and get quiz + teacher
 * Also fetch stage_id + chapter_id for navigation.
 */
$sql = "
SELECT
    gac.code_id,
    gac.teacher_id,
    gac.quiz_id,
    q.stage_id,
    s.chapter_id,
    q.class_id
FROM game_access_codes gac
JOIN quizzes q ON q.quiz_id = gac.quiz_id
LEFT JOIN stages s ON s.stage_id = q.stage_id
WHERE gac.access_code = ?
  AND gac.is_active = 1
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $access_code);
$stmt->execute();
$res = $stmt->get_result();

$row = $res->fetch_assoc();
if (!$row) {
    echo json_encode(["status" => "invalid", "message" => "Please enter correct code"]);
    exit;
}

$teacher_id = (int)$row["teacher_id"];
$quiz_id    = (int)$row["quiz_id"];
$stage_id   = isset($row["stage_id"]) ? (int)$row["stage_id"] : 0;
$chapter_id = isset($row["chapter_id"]) ? (int)$row["chapter_id"] : 0;
$class_id   = isset($row["class_id"]) ? (int)$row["class_id"] : 0;

/**
 * 3) Check attempt count and passing status (max 1 attempt, block if already passed)
 */
$attempts_used      = 0;
$attempts_remaining = 1;
$attemptCheck = $conn->prepare(               
    "SELECT COUNT(*) as attempt_count,
            MAX(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) as has_passed
     FROM student_quizzes
     WHERE student_id = ? AND quiz_id = ?"
);
$attemptCheck->bind_param("ii", $student_id, $quiz_id);
$attemptCheck->execute();
$attemptInfo    = $attemptCheck->get_result()->fetch_assoc();
$attemptCheck->close();
$attempts_used  = (int)($attemptInfo['attempt_count'] ?? 0);
$hasPassed      = (int)($attemptInfo['has_passed'] ?? 0);

if ($hasPassed) {
    echo json_encode(["status" => "error", "message" => "You have already passed this quest!"]);
    exit;
}
if ($attempts_used >= 1) {
    $score = 0;
    $scoreStmt = $conn->prepare(
        "SELECT score FROM student_quizzes WHERE student_id = ? AND quiz_id = ? ORDER BY student_quiz_id DESC LIMIT 1"
    );
    if ($scoreStmt) {
        $scoreStmt->bind_param("ii", $student_id, $quiz_id);
        $scoreStmt->execute();
        $scoreRes = $scoreStmt->get_result();
        if ($scoreRes && $scoreRes->num_rows > 0) {
            $score = (int)$scoreRes->fetch_assoc()['score'];
        }
        $scoreStmt->close();
    }
    echo json_encode(["status" => "error", "message" => "You already took this quiz and your score is " . $score . "."]);
    exit;
}
$attempts_remaining = 1 - $attempts_used;

/**
 * 4) SUCCESS response
 */
echo json_encode([
    "status"             => "success",
    "message"            => "Access code valid",
    "code_id"            => (int)$row["code_id"],
    "teacher_id"         => $teacher_id,
    "quiz_id"            => $quiz_id,
    "stage_id"           => $stage_id,
    "chapter_id"         => $chapter_id,
    "class_id"           => $class_id,
    "attempts_used"      => $attempts_used,
    "attempts_remaining" => $attempts_remaining
]);
