<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json; charset=UTF-8");

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Fatal error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']
        ]);
    }
});

require_once dirname(__FILE__) . "/db.php";

$student_quiz_id = (int)($_POST["student_quiz_id"] ?? 0);
if ($student_quiz_id <= 0) {
    echo json_encode(["status" => "error", "message" => "student_quiz_id required"]);
    exit;
}

// Determine if the student_answers table has an answer_text column
$cols = [];
$colRes = $conn->query("SHOW COLUMNS FROM student_answers");
if ($colRes) {
    while ($c = $colRes->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
}

$has_answer_text = in_array('answer_text', $cols);

if ($has_answer_text) {
    // Use text-matching against quiz_choices.choice_text for short-answer grading
    $sql = "
    SELECT
      SUM(
        CASE
          WHEN sa.selected_choice_id IS NOT NULL THEN
            CASE WHEN qc.is_correct = 1 THEN 1 ELSE 0 END
          WHEN sa.answer_text IS NOT NULL THEN
            CASE WHEN qc_text.is_correct = 1 THEN 1 ELSE 0 END
          ELSE 0
        END
      ) AS correct_count,
      COUNT(sa.answer_id) AS answered_count
    FROM student_answers sa
    LEFT JOIN quiz_choices qc ON qc.choice_id = sa.selected_choice_id
    LEFT JOIN quiz_choices qc_text ON qc_text.question_id = sa.question_id AND LOWER(TRIM(qc_text.choice_text)) = LOWER(TRIM(sa.answer_text))
    WHERE sa.student_quiz_id = ?
    ";
} else {
    // Fallback: table doesn't have answer_text column — only count correct multiple-choice answers
    $sql = "
    SELECT
      SUM(CASE WHEN sa.selected_choice_id IS NOT NULL AND qc.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count,
      COUNT(sa.answer_id) AS answered_count
    FROM student_answers sa
    LEFT JOIN quiz_choices qc ON qc.choice_id = sa.selected_choice_id
    WHERE sa.student_quiz_id = ?
    ";
}

// Prepare and execute safely, returning JSON on error
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "DB prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("i", $student_quiz_id);
if (!$stmt->execute()) {
    echo json_encode(["status" => "error", "message" => "DB execute failed: " . $stmt->error]);
    exit;
}

$res = $stmt->get_result();
if (!$res) {
    echo json_encode(["status" => "error", "message" => "DB get_result failed: " . $stmt->error]);
    exit;
}

$row = $res->fetch_assoc();

$correct  = (int)($row["correct_count"] ?? 0);
$answered = (int)($row["answered_count"] ?? 0);

// Determine passing: fetch total_score from quizzes (75% threshold)
$totalScore = 0;
$tsRow = $conn->query(
    "SELECT q.total_score FROM student_quizzes sq
     JOIN quizzes q ON sq.quiz_id = q.quiz_id
     WHERE sq.student_quiz_id = " . (int)$student_quiz_id . " LIMIT 1"
);
if ($tsRow && $tsRow->num_rows > 0) {
    $totalScore = (int)($tsRow->fetch_assoc()['total_score'] ?? 0);
}
$isPassed    = ($totalScore > 0 && ($correct / $totalScore) >= 0.75);
$finalStatus = $isPassed ? 'passed' : 'completed';

$upd = $conn->prepare("UPDATE student_quizzes
                       SET score=?, status=?, submitted_at=NOW()
                       WHERE student_quiz_id=?");
if (!$upd) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB prepare failed: " . $conn->error]);
    exit;
}
$upd->bind_param("isi", $correct, $finalStatus, $student_quiz_id);
if (!$upd->execute()) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB execute failed: " . $upd->error]);
    exit;
}

// Retrieve submitted_at value we just set
$submitted_at = null;
$started_at = null;
$sel = $conn->prepare("SELECT taken_at, submitted_at FROM student_quizzes WHERE student_quiz_id=? LIMIT 1");
if (!$sel) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB prepare failed: " . $conn->error]);
    exit;
}
if ($sel) {
    $sel->bind_param("i", $student_quiz_id);
    $sel->execute();
    $sres = $sel->get_result();
    if ($sres && $sres->num_rows > 0) {
        $srow = $sres->fetch_assoc();
        $submitted_at = $srow['submitted_at'];
        $started_at = $srow['taken_at'];
    }
    $sel->close();
}

// compute time taken in seconds (fallback to 0 if missing)
$time_taken_seconds = 0;
$time_taken_display = "N/A";
if (!empty($submitted_at)) {
    if (!empty($started_at)) {
        $ts = strtotime($submitted_at);
        $t0 = strtotime($started_at);
        if ($ts !== false && $t0 !== false) {
            $time_taken_seconds = max(0, $ts - $t0);
            // format as H:MM:SS or M:SS
            $h = floor($time_taken_seconds / 3600);
            $m = floor(($time_taken_seconds % 3600) / 60);
            $s = $time_taken_seconds % 60;
            if ($h > 0) $time_taken_display = sprintf("%d:%02d:%02d", $h, $m, $s);
            else $time_taken_display = sprintf("%02d:%02d", $m, $s);
        }
    } else {
        // If started_at is missing, assume 5 minutes for display
        $time_taken_seconds = 300;
        $time_taken_display = "05:00";
    }
}

// Auto-migration: add attempt_number column if it doesn't exist yet
$colCheck = $conn->query("SHOW COLUMNS FROM `student_quizzes` LIKE 'attempt_number'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE `student_quizzes` ADD COLUMN `attempt_number` INT NOT NULL DEFAULT 1");
}

// Get attempt number and remaining attempts for this student_quiz
$attemptNumber     = 1;
$attemptsRemaining = 0;
$aqr = $conn->query(
    "SELECT attempt_number, student_id, quiz_id
     FROM student_quizzes WHERE student_quiz_id = " . (int)$student_quiz_id . " LIMIT 1"
);
if ($aqr && $aqr->num_rows > 0) {
    $aqrow         = $aqr->fetch_assoc();
    $storedAttempt = (int)($aqrow['attempt_number'] ?? 0);

    if ($storedAttempt > 0) {
        $attemptNumber = $storedAttempt;
    } else {
        // Derive attempt number by counting all rows for this student+quiz up to this one
        $ordQ = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM student_quizzes
             WHERE student_id = ? AND quiz_id = ? AND student_quiz_id <= ?"
        );
        $ordQ->bind_param("iii", (int)$aqrow['student_id'], (int)$aqrow['quiz_id'], (int)$student_quiz_id);
        $ordQ->execute();
        $ordResult     = $ordQ->get_result()->fetch_assoc();
        $attemptNumber = max(1, (int)($ordResult['cnt'] ?? 1));
        $ordQ->close();
        // Persist the derived value back to the row
        $updAttempt = $conn->prepare("UPDATE student_quizzes SET attempt_number = ? WHERE student_quiz_id = ?");
        $updAttempt->bind_param("ii", $attemptNumber, (int)$student_quiz_id);
        $updAttempt->execute();
        $updAttempt->close();
    }

    if (!$isPassed) {
        $cntQ = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM student_quizzes
             WHERE student_id = ? AND quiz_id = ?
             AND status IN ('completed', 'passed', 'submitted')"
        );
        $cntQ->bind_param("ii", (int)$aqrow['student_id'], (int)$aqrow['quiz_id']);
        $cntQ->execute();
        $cntResult         = $cntQ->get_result()->fetch_assoc();
        $attemptsRemaining = max(0, 1 - (int)($cntResult['cnt'] ?? $attemptNumber));
        $cntQ->close();
    }
}

echo json_encode([
  "status"             => "success",
  "correct_count"      => $correct,
  "answered_count"     => $answered,
  "is_passed"          => $isPassed ? 1 : 0,
  "attempt_number"     => $attemptNumber,
  "attempts_remaining" => $attemptsRemaining,
  "submitted_at"       => $submitted_at,
  "time_taken_seconds" => $time_taken_seconds,
  "time_taken_display" => $time_taken_display
]);
