<?php
header("Content-Type: application/json; charset=utf-8");

$conn = new mysqli("localhost", "root", "", "atomix_db"); // change db name if needed
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["success"=>false, "message"=>"Database connection failed"]);
  exit;
}
$conn->set_charset("utf8mb4");

$email = isset($_POST["email"]) ? trim($_POST["email"]) : "";
$password = isset($_POST["password"]) ? $_POST["password"] : "";

if ($email === "") {
  echo json_encode(["success"=>false, "message"=>"Email is required"]);
  exit;
}

$stmt = $conn->prepare("
  SELECT user_id, username, email, password, role, status, must_change_password, email_verification_code_hash, email_verification_code_expires_at
  FROM users
  WHERE email = ? AND role = 'student'
  LIMIT 1
");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
  echo json_encode(["success"=>false, "message"=>"Student account not found"]);
  exit;
}

$user = $res->fetch_assoc();
$verificationCodeUsed = false;

if ($user["status"] !== "active") {
  if ($user["status"] === "pending") {
    if (trim($password) === "") {
      echo json_encode([
        "success" => true,
        "needs_verification" => true,
        "message" => "Account pending verification. Enter the 6-digit code on the verification screen.",
        "email" => $user["email"]
      ]);
      exit;
    }

    if (!empty($user["email_verification_code_hash"])) {
      if (!empty($user["email_verification_code_expires_at"]) && strtotime($user["email_verification_code_expires_at"]) < time()) {
        echo json_encode(["success"=>false, "message"=>"Verification code has expired. Please ask your teacher or admin to resend it."]);
        exit;
      }

      if (hash('sha256', $password) === $user["email_verification_code_hash"]) {
        $updateStmt = $conn->prepare(
          "UPDATE users SET email_verified_at = NOW(), status = 'active', email_verification_code_hash = NULL, email_verification_code_expires_at = NULL, must_change_password = 1 WHERE user_id = ?"
        );
        $updateStmt->bind_param("i", $user["user_id"]);
        $updateStmt->execute();
        $user["status"] = "active";
        $user["must_change_password"] = 1;
        $verificationCodeUsed = true;
      } else {
        echo json_encode(["success"=>false, "message"=>"Invalid email or verification code"]);
        exit;
      }
    } else {
      echo json_encode(["success"=>false, "message"=>"Account pending verification."]);
      exit;
    }
  } else {
    echo json_encode(["success"=>false, "message"=>"Account inactive"]);
    exit;
  }
}

if (!$verificationCodeUsed && !password_verify($password, $user["password"])) {
  echo json_encode(["success"=>false, "message"=>"Invalid email or password"]);
  exit;
}

// Get student_id and class_id from students and class_students tables
$studentStmt = $conn->prepare("
    SELECT s.student_id, cs.class_id 
    FROM students s 
    JOIN class_students cs ON s.student_id = cs.student_id 
    WHERE s.user_id = ? 
    LIMIT 1
");
$studentStmt->bind_param("i", $user["user_id"]);
$studentStmt->execute();
$studentRes = $studentStmt->get_result();
$student = $studentRes->fetch_assoc();
$student_id = $student ? (int)$student["student_id"] : 0;
$class_id = $student ? (int)$student["class_id"] : 0;

if ($student_id === 0) {
  echo json_encode(["success"=>false, "message"=>"Student profile not found"]);
  exit;
}

if ((int)$user["must_change_password"] === 1) {
  echo json_encode([
    "success" => true,
    "message" => "Password change required",
    "must_change_password" => true,
    "user_id" => (int)$user["user_id"],
    "student_id" => $student_id,
    "class_id" => $class_id,
    "username" => $user["username"],
    "email" => $user["email"],
    "role" => "student"
  ]);
  exit;
}

echo json_encode([
  "success" => true,
  "message" => "Login success",
  "user_id" => (int)$user["user_id"],
  "student_id" => $student_id,
  "class_id" => $class_id,
  "username" => $user["username"],
  "email" => $user["email"],
  "role" => "student"
]);
