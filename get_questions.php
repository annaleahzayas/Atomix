<?php
$quiz_id = $_POST['quiz_id'];
$conn = new mysqli("localhost", "username", "password", "atomix_db");
if ($conn->connect_error) { die("Connection failed"); }

$stmt = $conn->prepare(
    "SELECT qq.question_id, qm.question_text, qm.question_type
     FROM quiz_questions qq
     JOIN questions_master qm ON qq.question_id = qm.question_id
     WHERE qq.quiz_id = ?"
);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$result = $stmt->get_result();

$questions = [];
while ($row = $result->fetch_assoc()) {
    $choices = [];
    $choice_stmt = $conn->prepare(
        "SELECT choice_id, choice_text FROM quiz_choices WHERE question_id = ?"
    );
    $choice_stmt->bind_param("i", $row['question_id']);
    $choice_stmt->execute();
    $choice_result = $choice_stmt->get_result();
    while ($choice = $choice_result->fetch_assoc()) {
        $choices[] = $choice;
    }
    $row['choices'] = $choices;
    $questions[] = $row;
    $choice_stmt->close();
}
echo json_encode($questions);

$stmt->close();
$conn->close();
?>