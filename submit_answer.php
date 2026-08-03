<?php
require_once "db.php";

$student_quiz_id = (int)($_POST["student_quiz_id"] ?? 0);
$question_id = (int)($_POST["question_id"] ?? 0);
$selected_choice_id = (int)($_POST["selected_choice_id"] ?? 0);
$answer_text = trim($_POST["answer_text"] ?? "");

if ($student_quiz_id <= 0 || $question_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid parameters"]);
    exit;
}

if ($selected_choice_id > 0) {
    // MCQ answer
    $stmt = $conn->prepare("INSERT INTO student_answers (student_quiz_id, question_id, selected_choice_id, answer_text) VALUES (?, ?, ?, NULL)");
    $stmt->bind_param("iii", $student_quiz_id, $question_id, $selected_choice_id);
} elseif (!empty($answer_text)) {
    // Short answer
    $stmt = $conn->prepare("INSERT INTO student_answers (student_quiz_id, question_id, selected_choice_id, answer_text) VALUES (?, ?, NULL, ?)");
    $stmt->bind_param("iis", $student_quiz_id, $question_id, $answer_text);
} else {
    echo json_encode(["status" => "error", "message" => "No answer provided"]);
    exit;
}

if ($stmt->execute()) {
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to save answer"]);
}

$stmt->close();
$conn->close();
?>