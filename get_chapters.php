<?php
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "atomix_db");

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

$conn->query(
    "CREATE TABLE IF NOT EXISTS class_chapter_access (
        class_id INT NOT NULL,
        chapter_id INT NOT NULL,
        is_locked TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (class_id, chapter_id),
        KEY idx_class_chapter_access_chapter (chapter_id),
        CONSTRAINT fk_class_chapter_access_class FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_class_chapter_access_chapter FOREIGN KEY (chapter_id) REFERENCES chapters(chapter_id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$class_id = 0;
if ($student_id > 0) {
        $class_sql = "SELECT cs.class_id
                                        FROM class_students cs
                                        JOIN classes c ON c.class_id = cs.class_id
                                        JOIN school_year sy ON sy.sy_id = c.sy_id
                    WHERE cs.student_id = ?
                                            AND sy.is_active = 1
                    ORDER BY cs.joined_at DESC, cs.class_stud_id DESC
                    LIMIT 1";
    $class_stmt = $conn->prepare($class_sql);
    if ($class_stmt) {
        $class_stmt->bind_param("i", $student_id);
        $class_stmt->execute();
        $class_result = $class_stmt->get_result();
        if ($class_result && $class_result->num_rows > 0) {
            $class_row = $class_result->fetch_assoc();
            $class_id = (int)($class_row['class_id'] ?? 0);
        }
        $class_stmt->close();
    }
}

$sql = "SELECT c.chapter_id,
               c.chapter_title,
               c.chapter_order,
               COALESCE(cca.is_locked, CASE WHEN c.chapter_order = 1 THEN 0 ELSE 1 END) AS is_locked
        FROM chapters c
        LEFT JOIN class_chapter_access cca
          ON cca.chapter_id = c.chapter_id
         AND cca.class_id = ?
        ORDER BY c.chapter_order";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();

$chapters = [];
while ($row = $result->fetch_assoc()) {
    $chapters[] = $row;
}

$stmt->close();

echo json_encode([
    "status" => "success",
    "chapters" => $chapters
]);
