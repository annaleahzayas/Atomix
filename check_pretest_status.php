<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$conn = new mysqli('localhost', 'root', '', 'atomix_db');
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]);
    exit();
}

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$chapter_id = isset($_GET['chapter_id']) ? intval($_GET['chapter_id']) : 0;

if ($student_id <= 0 || $chapter_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Missing student_id or chapter_id", "completed" => 0]);
    $conn->close();
    exit();
}

$target_pretest_table = '';
$check_student_pretest = $conn->query("SHOW TABLES LIKE 'student_pretest_results'");
if ($check_student_pretest && $check_student_pretest->num_rows > 0) {
    $target_pretest_table = 'student_pretest_results';
} else {
    $check_pretest = $conn->query("SHOW TABLES LIKE 'pretest_results'");
    if ($check_pretest && $check_pretest->num_rows > 0) {
        $target_pretest_table = 'pretest_results';
    }
}

if ($target_pretest_table === '') {
    echo json_encode(["status" => "success", "completed" => 0]);
    $conn->close();
    exit();
}

$sql = "SELECT 1 FROM `" . $target_pretest_table . "` WHERE student_id = ? AND chapter_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => $conn->error, "completed" => 0]);
    $conn->close();
    exit();
}

$stmt->bind_param('ii', $student_id, $chapter_id);
$stmt->execute();
$res = $stmt->get_result();
$completed = ($res && $res->num_rows > 0) ? 1 : 0;

echo json_encode(["status" => "success", "completed" => $completed]);

$stmt->close();
$conn->close();
?>
