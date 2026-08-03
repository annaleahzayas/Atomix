<?php
require_once "db.php";

$student_id = (int)($_POST["student_id"] ?? 0);
$quiz_id    = (int)($_POST["quiz_id"] ?? 0);

if ($student_id <= 0 || $quiz_id <= 0) {
    echo json_encode(["status" => "error", "message" => "student_id and quiz_id required"]);
    exit;
}

// Auto-migration: add attempt_number column if it doesn't exist yet
$colCheck = $conn->query("SHOW COLUMNS FROM `student_quizzes` LIKE 'attempt_number'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE `student_quizzes` ADD COLUMN `attempt_number` INT NOT NULL DEFAULT 1");
}

// Get quiz details (time limit, instructions, title)
$time_limit   = 0;
$instructions = "";
$quiz_title   = "";
$q = $conn->prepare("SELECT time_limit, instruction, quiz_title FROM quizzes WHERE quiz_id = ? LIMIT 1");
if ($q) {
    $q->bind_param("i", $quiz_id);
    $q->execute();
    $qr = $q->get_result();
    if ($qr && $qr->num_rows > 0) {
        $r            = $qr->fetch_assoc();
        $time_limit   = (int)($r['time_limit'] ?? 0);
        $instructions = $r['instruction'] ?? "";
        $quiz_title   = $r['quiz_title'] ?? "";
    }
    $q->close();
}

// Fetch ALL existing attempts for this student + quiz
$check = $conn->prepare(
    "SELECT student_quiz_id, status, attempt_number, taken_at
     FROM student_quizzes
     WHERE student_id = ? AND quiz_id = ?
     ORDER BY student_quiz_id ASC"
);
$check->bind_param("ii", $student_id, $quiz_id);
$check->execute();
$allRows = $check->get_result()->fetch_all(MYSQLI_ASSOC);
$check->close();

$completedCount = 0;
$inProgressRow  = null;
$hasPassed      = false;

foreach ($allRows as $row) {
    $s = strtolower(trim($row['status'] ?? ''));
    if ($s === 'passed') {
        $hasPassed = true;
        $completedCount++;
    } elseif ($s === 'completed' || $s === 'submitted') {
        $completedCount++;
    } elseif ($s === 'in_progress' && $inProgressRow === null) {
        $inProgressRow = $row;
    }
}

// Block if already passed
if ($hasPassed) {
    echo json_encode(["status" => "error", "message" => "You have already passed this quest!"]);
    exit;
}

// Block if the only attempt is exhausted and none is in-progress
if ($completedCount >= 1 && $inProgressRow === null) {
    echo json_encode(["status" => "error", "message" => "Your only attempt has been used."]);
    exit;
}

if ($inProgressRow !== null) {
    // Resume existing in-progress session
    $student_quiz_id   = (int)$inProgressRow['student_quiz_id'];
    $attemptNumber     = (int)($inProgressRow['attempt_number'] ?? 1);
    $attemptsRemaining = 0; // no additional attempts after this one

    // Set taken_at (start time) if not yet set
    if (empty($inProgressRow['taken_at'])) {
        $upd = $conn->prepare("UPDATE student_quizzes SET taken_at = NOW() WHERE student_quiz_id = ?");
        $upd->bind_param("i", $student_quiz_id);
        $upd->execute();
        $upd->close();
    }

    echo json_encode([
        "status"             => "success",
        "student_quiz_id"    => $student_quiz_id,
        "attempt_number"     => $attemptNumber,
        "attempts_remaining" => $attemptsRemaining,
        "time_limit"         => $time_limit,
        "instructions"       => $instructions,
        "quiz_title"         => $quiz_title,
        "message"            => "existing"
    ]);
} else {
    // Start a new attempt
    $newAttemptNumber  = $completedCount + 1;
    $attemptsRemaining = max(0, 1 - $newAttemptNumber); // remaining AFTER this attempt is done

    $stmt = $conn->prepare(
        "INSERT INTO student_quizzes (student_id, quiz_id, score, status, attempt_number, started_at, taken_at)
         VALUES (?, ?, 0, 'in_progress', ?, NOW(), NOW())"
    );
    $stmt->bind_param("iii", $student_id, $quiz_id, $newAttemptNumber);
    if ($stmt->execute()) {
        $student_quiz_id = (int)$conn->insert_id;
        echo json_encode([
            "status"             => "success",
            "student_quiz_id"    => $student_quiz_id,
            "attempt_number"     => $newAttemptNumber,
            "attempts_remaining" => $attemptsRemaining,
            "time_limit"         => $time_limit,
            "instructions"       => $instructions,
            "quiz_title"         => $quiz_title
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to start quiz"]);
    }
    $stmt->close();
}
