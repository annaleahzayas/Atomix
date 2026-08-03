<?php
require_once "db.php";

$student_id = (int)($_POST["student_id"] ?? 0);

if ($student_id <= 0) {
    echo json_encode(["success" => false, "message" => "student_id required"]);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "No image file uploaded"]);
    exit;
}

$file = $_FILES['profile_image'];

// Validate file type
$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(["success" => false, "message" => "Only JPG, PNG, and GIF images are allowed"]);
    exit;
}

// Validate file size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(["success" => false, "message" => "Image file too large (max 5MB)"]);
    exit;
}

// Create uploads directory if it doesn't exist
$upload_dir = "uploads/profile_images/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = "profile_" . $student_id . "_" . time() . "." . $extension;
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(["success" => false, "message" => "Failed to save image"]);
    exit;
}

// Update database with new image path
$sql = "UPDATE students SET profile_image = ? WHERE student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $filename, $student_id);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Profile image updated successfully",
        "image_url" => "http://localhost/atomix/" . $filepath
    ]);
} else {
    // If database update fails, delete the uploaded file
    unlink($filepath);
    echo json_encode(["success" => false, "message" => "Failed to update profile image in database"]);
}

$stmt->close();
$conn->close();
?>