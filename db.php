<?php
// Suppress PHP warnings/notices from polluting JSON responses
ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json");

$host = "localhost";
$user = "root";
$pass = "";
$db   = "atomix_db"; // change to your DB name

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "DB connection failed"]);
  exit;
}

$conn->set_charset("utf8mb4");