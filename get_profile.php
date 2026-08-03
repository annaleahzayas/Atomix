<?php
require_once "db.php";

$student_id = (int)($_GET["student_id"] ?? 0);

if ($student_id <= 0) {
    echo json_encode(["success" => false, "message" => "student_id required"]);
    exit;
}

$sql = "SELECT s.student_id, s.user_id, c.class_name as class, cs.joined_at, s.first_name, s.last_name, s.profile_image, s.gender,
               u.email, u.username
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN class_students cs ON s.student_id = cs.student_id
        LEFT JOIN classes c ON cs.class_id = c.class_id
        WHERE s.student_id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $profile = $result->fetch_assoc();
    echo json_encode([
        "success" => true,
        "profile" => [
            "student_id" => $profile['student_id'],
            "user_id" => $profile['user_id'],
            "class" => $profile['class'],
            "joined_at" => $profile['joined_at'],
            "first_name" => $profile['first_name'],
            "last_name" => $profile['last_name'],
            "profile_image" => $profile['profile_image'],
            "gender" => $profile['gender'],
            "email" => $profile['email'],
            "username" => $profile['username']
        ]
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Profile not found"]);
}

$stmt->close();
$conn->close();
?>