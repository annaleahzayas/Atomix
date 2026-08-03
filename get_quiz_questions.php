<?php
require_once "db.php";

$quiz_id = (int)($_GET["quiz_id"] ?? 0);
if ($quiz_id <= 0) {
    echo json_encode(["status" => "error", "message" => "quiz_id required"]);
    exit;
}

$sql = "
SELECT 
  qm.question_id,
  qm.question_text,
  qm.question_type,
  qc.choice_id,
  qc.choice_text
FROM quiz_questions qq
JOIN questions_master qm ON qm.question_id = qq.question_id
LEFT JOIN quiz_choices qc ON qc.question_id = qm.question_id
WHERE qq.quiz_id = ?
ORDER BY qm.question_id, qc.choice_id
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$res = $stmt->get_result();

$map = [];

while ($r = $res->fetch_assoc()) {
    $qid = (int)$r["question_id"];
    if (!isset($map[$qid])) {
        $map[$qid] = [
            "question_id" => $qid,
            "question_text" => $r["question_text"],
            "question_type" => $r["question_type"],
            "choices" => []
        ];
    }
    if (!empty($r["choice_id"])) {
        $map[$qid]["choices"][] = [
            "choice_id" => (int)$r["choice_id"],
            "choice_text" => $r["choice_text"]
        ];
    }
}

// Randomize question order
$questions = array_values($map);
shuffle($questions);

// Randomize choices order for each question
foreach ($questions as &$q) {
    if (!empty($q["choices"])) {
        shuffle($q["choices"]);
    }
}

echo json_encode(["status" => "success", "questions" => $questions]);
