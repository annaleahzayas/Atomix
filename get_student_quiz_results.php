<?php
require_once "db.php";

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) {
    echo json_encode(["status" => "error", "message" => "student_id required"]);
    exit;
}


// Try to use 'name' as quiz title, fallback to quiz_id if not present
$sql = "
SELECT 
    sq.student_quiz_id,
    sq.quiz_id,
    q.name AS quiz_title,
    sq.score,
    sq.status,
    sq.started_at,
    sq.taken_at
FROM student_quizzes sq
JOIN quizzes q ON q.quiz_id = sq.quiz_id
WHERE sq.student_id = ?
ORDER BY sq.taken_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();

$results = [];
while ($r = $res->fetch_assoc()) {
    $results[] = [
        "student_quiz_id" => (int)$r["student_quiz_id"],
        "quiz_id" => (int)$r["quiz_id"],
        "quiz_title" => ($r["quiz_title"] ?? "Quiz " . $r["quiz_id"]),
        "score" => (int)$r["score"],
        "status" => $r["status"],
        "started_at" => $r["started_at"],
        "taken_at" => $r["taken_at"]
    ];
}

echo json_encode(["status" => "success", "results" => $results]);
?>