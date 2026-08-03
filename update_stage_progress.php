<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "atomix_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Connection failed: " . $conn->connect_error
    ]);
    exit();
}

// Get parameters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$stage_id = isset($_GET['stage_id']) ? intval($_GET['stage_id']) : 0;
$stage_order = isset($_GET['stage_order']) ? intval($_GET['stage_order']) : 0;
$chapter_id = isset($_GET['chapter_id']) ? intval($_GET['chapter_id']) : 0;
$pretest_id = isset($_GET['pretest_id']) ? intval($_GET['pretest_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'in_progress';
$completed_levels = isset($_GET['completed_levels']) ? intval($_GET['completed_levels']) : 0;
$score = isset($_GET['score']) ? intval($_GET['score']) : 0;
$total_questions = isset($_GET['total_questions']) ? intval($_GET['total_questions']) : 0;
$record_assessment = isset($_GET['record_assessment']) ? intval($_GET['record_assessment']) === 1 : false;
$assessment_type = isset($_GET['assessment_type']) ? trim($_GET['assessment_type']) : 'stage';

if ($assessment_type !== 'chapter') {
    $assessment_type = 'stage';
}

// game_progress.status supports only these values.
$is_pretest_submit = ($status === 'pretest_completed');
if ($is_pretest_submit) {
    $status = 'completed';
}

$allowed_status = ['locked', 'in_progress', 'completed'];
if (!in_array($status, $allowed_status, true)) {
    $status = 'in_progress';
}

if ($student_id == 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing student_id"
    ]);
    exit();
}

// For pretest-only flow, chapter_id can be enough. Resolve stage 1 automatically when possible.
if ($is_pretest_submit && $stage_id == 0 && $chapter_id > 0) {
    $resolve_sql = "SELECT stage_id FROM stages WHERE chapter_id = ? ORDER BY stage_order ASC LIMIT 1";
    $resolve_stmt = $conn->prepare($resolve_sql);
    if ($resolve_stmt) {
        $resolve_stmt->bind_param("i", $chapter_id);
        $resolve_stmt->execute();
        $resolve_result = $resolve_stmt->get_result();
        if ($resolve_result && $resolve_result->num_rows > 0) {
            $resolved = $resolve_result->fetch_assoc();
            $stage_id = intval($resolved['stage_id']);
        }
        $resolve_stmt->close();
    }
}

if ($chapter_id <= 0 && $stage_id > 0) {
    $resolve_chapter_sql = "SELECT chapter_id FROM stages WHERE stage_id = ? LIMIT 1";
    $resolve_chapter_stmt = $conn->prepare($resolve_chapter_sql);
    if ($resolve_chapter_stmt) {
        $resolve_chapter_stmt->bind_param("i", $stage_id);
        $resolve_chapter_stmt->execute();
        $resolve_chapter_result = $resolve_chapter_stmt->get_result();
        if ($resolve_chapter_result && $resolve_chapter_result->num_rows > 0) {
            $resolved_chapter = $resolve_chapter_result->fetch_assoc();
            $chapter_id = intval($resolved_chapter['chapter_id']);
        }
        $resolve_chapter_stmt->close();
    }
}

// If stage_id is missing, allow resolving by chapter + stage_order (useful when scene launched directly).
if ($stage_id <= 0 && $chapter_id > 0 && $stage_order > 0) {
    $resolve_stage_sql = "SELECT stage_id FROM stages WHERE chapter_id = ? AND stage_order = ? LIMIT 1";
    $resolve_stage_stmt = $conn->prepare($resolve_stage_sql);
    if ($resolve_stage_stmt) {
        $resolve_stage_stmt->bind_param("ii", $chapter_id, $stage_order);
        $resolve_stage_stmt->execute();
        $resolve_stage_result = $resolve_stage_stmt->get_result();
        if ($resolve_stage_result && $resolve_stage_result->num_rows > 0) {
            $resolved_stage = $resolve_stage_result->fetch_assoc();
            $stage_id = intval($resolved_stage['stage_id']);
        }
        $resolve_stage_stmt->close();
    }
}

$progress_action = "skipped_pretest";
$assessment_action = "skipped";

// Save stage evaluation progress and score (used for stage unlocking),
// but keep pretest flow separate from game_progress.
if (!$is_pretest_submit) {
    if ($assessment_type !== 'chapter' && $stage_id <= 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Missing stage_id for stage progress update"
        ]);
        $conn->close();
        exit();
    }

    if ($stage_id > 0) {
        $check_sql = "SELECT 1 FROM game_progress WHERE student_id = ? AND stage_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $student_id, $stage_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result();
        $hasRow = ($existing && $existing->num_rows > 0);
        $check_stmt->close();

        if ($hasRow) {
            if ($record_assessment) {
                $update_sql = "UPDATE game_progress
                               SET status = ?, completed_levels = ?, last_updated = NOW()
                               WHERE student_id = ? AND stage_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("siii", $status, $completed_levels, $student_id, $stage_id);
            } else {
                $update_sql = "UPDATE game_progress
                               SET status = ?, completed_levels = ?, score = ?, last_updated = NOW()
                               WHERE student_id = ? AND stage_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("siiii", $status, $completed_levels, $score, $student_id, $stage_id);
            }
            $update_stmt->execute();
            $update_stmt->close();
            $progress_action = "updated";
        } else {
            $progress_score = $record_assessment ? 0 : $score;
            $insert_sql = "INSERT INTO game_progress (student_id, stage_id, status, completed_levels, score, last_updated)
                           VALUES (?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iisii", $student_id, $stage_id, $status, $completed_levels, $progress_score);
            $insert_stmt->execute();
            $insert_stmt->close();
            $progress_action = "inserted";
        }
    } else {
        $progress_action = "skipped_chapter_assessment";
    }

    if ($record_assessment) {
        if ($assessment_type === 'chapter' && $chapter_id <= 0) {
            echo json_encode([
                "status" => "error",
                "message" => "Missing chapter_id for chapter assessment"
            ]);
            $conn->close();
            exit();
        }

        if ($assessment_type === 'stage' && $stage_id <= 0) {
            echo json_encode([
                "status" => "error",
                "message" => "Missing stage_id for stage assessment"
            ]);
            $conn->close();
            exit();
        }

        if ($total_questions <= 0) {
            $total_questions = max($score, 1);
        }

        $create_assessment_sql = "CREATE TABLE IF NOT EXISTS student_assessment_results (
            assessment_result_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            chapter_id INT NOT NULL DEFAULT 0,
            stage_id INT NULL,
            assessment_type ENUM('stage','chapter') NOT NULL DEFAULT 'stage',
            score INT NOT NULL DEFAULT 0,
            total_questions INT NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student_assessment (student_id, assessment_type, chapter_id, stage_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $conn->query($create_assessment_sql);

        $existing_assessment_id = 0;
        if ($assessment_type === 'stage') {
            $assessment_check_sql = "SELECT assessment_result_id FROM student_assessment_results
                                     WHERE student_id = ? AND assessment_type = 'stage' AND stage_id = ?
                                     LIMIT 1";
            $assessment_check_stmt = $conn->prepare($assessment_check_sql);
            $assessment_check_stmt->bind_param("ii", $student_id, $stage_id);
        } else {
            $assessment_check_sql = "SELECT assessment_result_id FROM student_assessment_results
                                     WHERE student_id = ? AND assessment_type = 'chapter' AND chapter_id = ? AND (stage_id IS NULL OR stage_id = 0)
                                     LIMIT 1";
            $assessment_check_stmt = $conn->prepare($assessment_check_sql);
            $assessment_check_stmt->bind_param("ii", $student_id, $chapter_id);
        }
        $assessment_check_stmt->execute();
        $assessment_existing = $assessment_check_stmt->get_result();
        if ($assessment_existing && $assessment_existing->num_rows > 0) {
            $existing_assessment = $assessment_existing->fetch_assoc();
            $existing_assessment_id = intval($existing_assessment['assessment_result_id']);
        }
        $assessment_check_stmt->close();

        if ($existing_assessment_id > 0) {
            $update_assessment_sql = "UPDATE student_assessment_results
                                      SET chapter_id = ?, stage_id = ?, score = ?, total_questions = ?, attempted_at = NOW()
                                      WHERE assessment_result_id = ?";
            $update_assessment_stmt = $conn->prepare($update_assessment_sql);
            $nullable_stage_id = $stage_id > 0 ? $stage_id : null;
            $update_assessment_stmt->bind_param("iiiii", $chapter_id, $nullable_stage_id, $score, $total_questions, $existing_assessment_id);
            $update_assessment_stmt->execute();
            $update_assessment_stmt->close();
            $assessment_action = "updated";
        } else {
            $insert_assessment_sql = "INSERT INTO student_assessment_results
                                      (student_id, chapter_id, stage_id, assessment_type, score, total_questions, attempted_at)
                                      VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $insert_assessment_stmt = $conn->prepare($insert_assessment_sql);
            $nullable_stage_id = $stage_id > 0 ? $stage_id : null;
            $insert_assessment_stmt->bind_param("iiisii", $student_id, $chapter_id, $nullable_stage_id, $assessment_type, $score, $total_questions);
            $insert_assessment_stmt->execute();
            $insert_assessment_stmt->close();
            $assessment_action = "inserted";
        }
    }
}

$pretest_action = "skipped";
$target_pretest_table = "";

// Save dedicated pretest result row if this request came from pretest flow and the table exists.
if ($is_pretest_submit) {
    $check_student_pretest = $conn->query("SHOW TABLES LIKE 'student_pretest_results'");
    if ($check_student_pretest && $check_student_pretest->num_rows > 0) {
        $target_pretest_table = "student_pretest_results";
    } else {
        $check_pretest = $conn->query("SHOW TABLES LIKE 'pretest_results'");
        if ($check_pretest && $check_pretest->num_rows > 0) {
            $target_pretest_table = "pretest_results";
        }
    }

    if ($target_pretest_table !== "") {
        // pretest_id must reference chapter_pretests.pretest_id (FK constraint).
        if ($pretest_id == 0) {
            if ($chapter_id > 0) {
                $resolve_pretest_sql = "SELECT pretest_id FROM chapter_pretests WHERE chapter_id = ? AND is_active = 1 ORDER BY pretest_id DESC LIMIT 1";
                $resolve_pretest_stmt = $conn->prepare($resolve_pretest_sql);
                $resolve_pretest_stmt->bind_param("i", $chapter_id);
                $resolve_pretest_stmt->execute();
                $resolve_pretest_result = $resolve_pretest_stmt->get_result();
                if ($resolve_pretest_result && $resolve_pretest_result->num_rows > 0) {
                    $resolved_pretest = $resolve_pretest_result->fetch_assoc();
                    $pretest_id = intval($resolved_pretest['pretest_id']);
                }
                $resolve_pretest_stmt->close();
            }
        }

        if ($pretest_id <= 0 && $chapter_id > 0) {
            $resolve_any_pretest_sql = "SELECT pretest_id FROM chapter_pretests WHERE chapter_id = ? ORDER BY pretest_id DESC LIMIT 1";
            $resolve_any_pretest_stmt = $conn->prepare($resolve_any_pretest_sql);
            $resolve_any_pretest_stmt->bind_param("i", $chapter_id);
            $resolve_any_pretest_stmt->execute();
            $resolve_any_pretest_result = $resolve_any_pretest_stmt->get_result();
            if ($resolve_any_pretest_result && $resolve_any_pretest_result->num_rows > 0) {
                $resolved_any_pretest = $resolve_any_pretest_result->fetch_assoc();
                $pretest_id = intval($resolved_any_pretest['pretest_id']);
            }
            $resolve_any_pretest_stmt->close();
        }

        if ($pretest_id <= 0) {
            echo json_encode([
                "status" => "error",
                "message" => "No valid pretest_id found for chapter_id",
                "chapter_id" => $chapter_id,
                "pretest_result" => "failed_invalid_pretest_id"
            ]);
            $conn->close();
            exit();
        }

        if ($total_questions <= 0) {
            $total_questions = max($score, 1);
        }

        $insert_pretest_sql = "INSERT INTO `" . $target_pretest_table . "` (student_id, pretest_id, chapter_id, score, total_questions, attempted_at)
                       VALUES (?, ?, ?, ?, ?, NOW())";
        $insert_pretest_stmt = $conn->prepare($insert_pretest_sql);

        if ($insert_pretest_stmt) {
            try {
                $insert_pretest_stmt->bind_param("iiiii", $student_id, $pretest_id, $chapter_id, $score, $total_questions);
                if ($insert_pretest_stmt->execute()) {
                    $pretest_action = "inserted";
                } else {
                    $pretest_action = "failed: " . $insert_pretest_stmt->error;
                }
            } catch (Throwable $e) {
                $pretest_action = "failed: " . $e->getMessage();
            }
            $insert_pretest_stmt->close();
        } else {
            $pretest_action = "failed: " . $conn->error;
        }
    } else {
        $pretest_action = "table_not_found";
    }
}

echo json_encode([
    "status" => "success",
    "message" => "Progress saved",
    "action" => $progress_action,
    "assessment_result" => $assessment_action,
    "pretest_result" => $pretest_action,
    "pretest_table" => $is_pretest_submit ? ($target_pretest_table === "" ? null : $target_pretest_table) : null
]);

$conn->close();
?>
