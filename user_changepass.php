<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
require_once "db.php";

// Only allow POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

// Read inputs safely
$user_id = isset($_POST["user_id"]) ? (int)$_POST["user_id"] : 0;
$current_password = isset($_POST["current_password"]) ? trim((string)$_POST["current_password"]) : "";
$new_password = isset($_POST["new_password"]) ? trim((string)$_POST["new_password"]) : "";

if ($user_id <= 0 || $new_password === "") {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

if (strlen($new_password) < 6) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "New password must be at least 6 characters"]);
    exit;
}

// Optional: prevent re-using the same password string
if ($current_password === $new_password) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "New password must be different from current password"]);
    exit;
}

// 1) Get stored password hash/plain from DB
$stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error (prepare failed)"]);
    exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

$stored = (string)$row["password"];

// Check if user is required to change password (allow skipping current password check)
$mustStmt = $conn->prepare("SELECT must_change_password FROM users WHERE user_id = ? LIMIT 1");
if ($mustStmt) {
    $mustStmt->bind_param("i", $user_id);
    $mustStmt->execute();
    $mustRes = $mustStmt->get_result();
    $mustRow = $mustRes ? $mustRes->fetch_assoc() : null;
    $mustStmt->close();
    $isRequireChange = $mustRow && ((int)$mustRow["must_change_password"] === 1);
} else {
    $isRequireChange = false;
}

// If the user is not in forced-change mode, current password is required
if (!$isRequireChange && $current_password === "") {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Current password is required"]);
    exit;
}

// 2) Verify current password
// Detect bcrypt/argon2 hashes. If not hashed, treat as legacy plaintext.
$isHashed = (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0);

$valid = $isHashed ? password_verify($current_password, $stored) : hash_equals($stored, $current_password);

if (!$valid) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Current password incorrect"]);
    exit;
}

// 3) Hash new password + update
$newHash = password_hash($new_password, PASSWORD_DEFAULT);

$up = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?");
if (!$up) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error (prepare failed)"]);
    exit;
}

$up->bind_param("si", $newHash, $user_id);

if (!$up->execute()) {
    $up->close();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to update password"]);
    exit;
}

$up->close();

// Success
echo json_encode(["status" => "success", "message" => "Password updated"]);
