<?php
header("Content-Type: application/json; charset=utf-8");

$conn = new mysqli("localhost", "root", "", "atomix_db"); // change db name if needed
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["success"=>false, "message"=>"Database connection failed"]);
  exit;
}
$conn->set_charset("utf8mb4");

$user_id = isset($_POST["user_id"]) ? intval($_POST["user_id"]) : 0;
$new_password = isset($_POST["new_password"]) ? $_POST["new_password"] : "";

if ($user_id <= 0 || $new_password === "") {
  echo json_encode(["success"=>false, "message"=>"user_id and new_password required"]);
  exit;
}

$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ? AND role = 'student' LIMIT 1");
$stmt->bind_param("si", $hash, $user_id);
$ok = $stmt->execute();

if ($ok && $stmt->affected_rows > 0) {
  echo json_encode(["success"=>true, "message"=>"Password updated"]);
} else {
  echo json_encode(["success"=>false, "message"=>"Failed to update password"]);
}

$stmt->close();
$conn->close();
