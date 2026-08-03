<?php
// quest_check.php
// Expects POST 'code' and returns JSON: {status: 'ok'|'invalid'|'not_started'|'expired', quiz_id?: id}

// Send JSON and allow cross-origin (useful for WebGL builds/tests). Adjust origin in production.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Respond to OPTIONS preflight quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Basic input handling
$code = isset($_POST['code']) ? trim($_POST['code']) : null;
if (empty($code)) {
    echo json_encode(["status" => "invalid"]);
    exit;
}

// TODO: update DB connection credentials and host as needed
$conn = new mysqli("localhost", "username", "password", "atomix_db");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT gac.quiz_id, q.start_time, q.end_time
     FROM game_access_codes gac
     JOIN quizzes q ON gac.quiz_id = q.quiz_id
     WHERE gac.access_code = ? AND gac.is_active = 1"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB prepare failed"]);
    $conn->close();
    exit;
}

$stmt->bind_param("s", $code);
$stmt->execute();
$stmt->bind_result($quiz_id, $start, $end);

if ($stmt->fetch()) {
    $now = date("Y-m-d H:i:s");
    if ($now < $start) {
        echo json_encode(["status" => "not_started"]);
    } else if ($now > $end) {
        echo json_encode(["status" => "expired"]);
    } else {
        echo json_encode(["status" => "ok", "quiz_id" => (string)$quiz_id]);
    }
} else {
    echo json_encode(["status" => "invalid"]);
}

$stmt->close();
$conn->close();
?>