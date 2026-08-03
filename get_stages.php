<?php
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "atomix_db");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}

$chapter_id = isset($_GET['chapter_id']) ? intval($_GET['chapter_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

if ($chapter_id == 0 || $student_id == 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing chapter_id or student_id"]);
    exit;
}

// Support both schema variants: `total_levels` (new) and `total_levers` (older).
$totalLevelsColumn = 'total_levels';
$colCheck = $conn->query("SHOW COLUMNS FROM stages LIKE 'total_levels'");
if (!$colCheck || $colCheck->num_rows === 0) {
    $totalLevelsColumn = 'total_levers';
}

$hasStageName = false;
$hasStageTitle = false;
$stageNameCheck = $conn->query("SHOW COLUMNS FROM stages LIKE 'stage_name'");
if ($stageNameCheck && $stageNameCheck->num_rows > 0) {
    $hasStageName = true;
}

$stageTitleCheck = $conn->query("SHOW COLUMNS FROM stages LIKE 'stage_title'");
if ($stageTitleCheck && $stageTitleCheck->num_rows > 0) {
    $hasStageTitle = true;
}

$stageNameExpr = "CONCAT('Stage ', s.stage_order)";
if ($hasStageName && $hasStageTitle) {
    $stageNameExpr = "COALESCE(NULLIF(TRIM(s.stage_name), ''), NULLIF(TRIM(s.stage_title), ''), CONCAT('Stage ', s.stage_order))";
} elseif ($hasStageName) {
    $stageNameExpr = "COALESCE(NULLIF(TRIM(s.stage_name), ''), CONCAT('Stage ', s.stage_order))";
} elseif ($hasStageTitle) {
    $stageNameExpr = "COALESCE(NULLIF(TRIM(s.stage_title), ''), CONCAT('Stage ', s.stage_order))";
}

// Get stages for chapter with student progress
$sql = "SELECT 
    s.stage_id,
    s.chapter_id,
    s.game_id,
    $stageNameExpr AS stage_name,
    COALESCE(NULLIF(TRIM(s.science_concept), ''), 'No concept provided') AS science_concept,
    s.stage_order,
    COALESCE(s.$totalLevelsColumn, 0) AS total_levels,
    COALESCE(gp.completed_levels, 0) as completed_levels,
    COALESCE(sar.score, 0) as score,
    COALESCE(gp.status, 'not_started') as status,
    COALESCE(gp.last_updated, '') as last_updated
FROM stages s
LEFT JOIN game_progress gp ON s.stage_id = gp.stage_id AND gp.student_id = ?
LEFT JOIN student_assessment_results sar ON s.stage_id = sar.stage_id AND sar.student_id = ? AND sar.assessment_type = 'stage'
WHERE s.chapter_id = ?
ORDER BY s.stage_order";

if (!$stmt = $conn->prepare($sql)) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to prepare query: " . $conn->error]);
    exit;
}

$stmt->bind_param("iii", $student_id, $student_id, $chapter_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to fetch stages."]);
    exit;
}

$stages = [];
while ($row = $result->fetch_assoc()) {
    $stages[] = $row;
}

echo json_encode([
    "status" => "success",
    "stages" => $stages
]);

$stmt->close();
$conn->close();
?>

