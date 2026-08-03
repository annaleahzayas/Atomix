<?php
require_once "db.php";

$student_id = (int)($_POST["student_id"] ?? 0);
$first_name = trim($_POST["first_name"] ?? "");
$last_name = trim($_POST["last_name"] ?? "");
$gender = trim($_POST["gender"] ?? "");

if ($student_id <= 0) {
    echo json_encode(["success" => false, "message" => "student_id required"]);
    exit;
}

if (empty($first_name) || empty($last_name)) {
    echo json_encode(["success" => false, "message" => "First name and last name are required"]);
    exit;
}

if (!in_array($gender, ['male', 'female', 'others', ''])) {
    echo json_encode(["success" => false, "message" => "Invalid gender value"]);
    exit;
}

$sql = "UPDATE students SET first_name = ?, last_name = ?, gender = ? WHERE student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssi", $first_name, $last_name, $gender, $student_id);

if ($stmt->execute()) {
    // Fetch the updated profile including class from class_students
    $sql_fetch = "SELECT s.student_id, s.user_id, c.class_name as class, cs.joined_at, s.first_name, s.last_name, s.profile_image, s.gender,
                         u.email, u.username
                  FROM students s
                  JOIN users u ON s.user_id = u.user_id
                  LEFT JOIN class_students cs ON s.student_id = cs.student_id
                  LEFT JOIN classes c ON cs.class_id = c.class_id
                  WHERE s.student_id = ? LIMIT 1";
    $stmt_fetch = $conn->prepare($sql_fetch);
    $stmt_fetch->bind_param("i", $student_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();
    
    if ($result && $result->num_rows > 0) {
        $profile = $result->fetch_assoc();
        echo json_encode([
            "success" => true,
            "message" => "Profile updated successfully",
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
        echo json_encode(["success" => true, "message" => "Profile updated successfully, but failed to fetch updated data"]);
    }
    $stmt_fetch->close();
} else {
    echo json_encode(["success" => false, "message" => "Failed to update profile"]);
}

$stmt->close();
$conn->close();
?>