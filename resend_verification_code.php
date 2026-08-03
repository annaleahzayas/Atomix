<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "db.php";

$requestMethod = $_SERVER["REQUEST_METHOD"];
$email = "";
if ($requestMethod === "POST") {
    $email = isset($_POST["email"]) ? trim($_POST["email"]) : "";
} elseif ($requestMethod === "GET") {
    $email = isset($_GET["email"]) ? trim($_GET["email"]) : "";
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

if ($email === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email is required"]);
    exit;
}

$stmt = $conn->prepare("SELECT user_id, email_verified_at, status FROM users WHERE email = ? AND role = 'student' LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Student account not found"]);
    exit;
}

if (!empty($user["email_verified_at"]) && $user["status"] === "active") {
    echo json_encode(["success" => false, "message" => "Account is already verified"]);
    exit;
}

$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $code);
$expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
$status = 'pending';

$update = $conn->prepare("UPDATE users SET email_verification_code_hash = ?, email_verification_code_expires_at = ?, status = ?, email_verified_at = NULL WHERE user_id = ?");
if (!$update) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

$update->bind_param("sssi", $codeHash, $expiresAt, $status, $user["user_id"]);
if (!$update->execute()) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to update verification code"]);
    exit;
}

$update->close();

$message = "A new verification code has been generated and sent to your email.";
$sent = false;
$emailError = "";

// SMTP email delivery settings using Atomix finalweb SMTP configuration.
$smtpEnabled = true;
$smtpHost = 'smtp.gmail.com';
$smtpPort = 587; // 465 for SSL, 587 for TLS
$smtpUser = 'atomixinteractive933@gmail.com';
$smtpPass = 'dtoucbdukqbpenro';
$smtpFrom = 'atomixinteractive933@gmail.com';
$smtpName = 'Atomix Learning Platform';
$useTls = true;

$subject = "Atomix Verification Code";
$body = "Your Atomix verification code is: " . $code . "\n\nThis code expires in 24 hours.";
$headers = "From: " . $smtpName . " <" . $smtpFrom . ">\r\n" .
           "Reply-To: " . $smtpFrom . "\r\n" .
           "Content-Type: text/plain; charset=UTF-8\r\n";

if (function_exists('mail')) {
    $sent = mail($email, $subject, $body, $headers);
    if (!$sent) {
        $emailError = 'PHP mail() failed or is not configured.';
    }
}

if (!$sent && $smtpEnabled && !empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
    $smtpResult = smtp_send_email($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom, $smtpName, $email, $subject, $body, $useTls);
    $sent = $smtpResult['success'];
    if (!$sent) {
        $emailError = $smtpResult['error'];
    }
}

$logData = date('Y-m-d H:i:s') . " email=" . $email . " code=" . $code . " sent=" . ($sent ? '1' : '0') . " error=" . addslashes($emailError) . "\n";
file_put_contents(__DIR__ . '/resend_verification_log.txt', $logData, FILE_APPEND | LOCK_EX);

if ($sent) {
    $message = "A new verification code has been sent to your email.";
} else {
    $message = "A new verification code was generated. Use the code shown in the app if email is not available.";
}

echo json_encode([
    "success" => true,
    "message" => $message,
    "verification_code" => $code,
    "email_sent" => $sent,
    "email_error" => $emailError,
]);

function smtp_send_email($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $body, $useTls = true)
{
    $timeout = 30;
    $remote = (($useTls && $port == 465) ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['success' => false, 'error' => "SMTP connect failed: $errno $errstr"];
    }

    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'error' => 'SMTP server error: ' . trim($response)];
    }

    $serverName = 'localhost';
    fputs($socket, "EHLO $serverName\r\n");
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => 'EHLO failed: ' . trim($response)];
    }

    if ($useTls && $port === 587) {
        fputs($socket, "STARTTLS\r\n");
        $response = smtp_read_response($socket);
        if (substr($response, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => 'STARTTLS failed: ' . trim($response)];
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        fputs($socket, "EHLO $serverName\r\n");
        $response = smtp_read_response($socket);
        if (substr($response, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => 'EHLO after STARTTLS failed: ' . trim($response)];
        }
    }

    fputs($socket, "AUTH LOGIN\r\n");
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '334') {
        fclose($socket);
        return ['success' => false, 'error' => 'AUTH LOGIN not accepted: ' . trim($response)];
    }

    fputs($socket, base64_encode($user) . "\r\n");
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '334') {
        fclose($socket);
        return ['success' => false, 'error' => 'SMTP username not accepted: ' . trim($response)];
    }

    fputs($socket, base64_encode($pass) . "\r\n");
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '235') {
        fclose($socket);
        return ['success' => false, 'error' => 'SMTP password not accepted: ' . trim($response)];
    }

    fputs($socket, "MAIL FROM:<$fromEmail>\r\n");
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => 'MAIL FROM failed: ' . trim($response)];
    }

    fputs($socket, "RCPT TO:<$toEmail>\r\n");
    $response = smtp_read_response($socket);
    if (!in_array(substr($response, 0, 3), ['250', '251'])) {
        fclose($socket);
        return ['success' => false, 'error' => 'RCPT TO failed: ' . trim($response)];
    }

    fputs($socket, "DATA\r\n");
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'error' => 'DATA command failed: ' . trim($response)];
    }

    $headers = "From: $fromName <$fromEmail>\r\n" .
               "Reply-To: $fromEmail\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "Subject: $subject\r\n" .
               "Date: " . date('r') . "\r\n" .
               "To: $toEmail\r\n" .
               "\r\n";
    $message = $headers . $body . "\r\n.\r\n";
    fputs($socket, $message);
    $response = smtp_read_response($socket);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => 'Message send failed: ' . trim($response)];
    }

    fputs($socket, "QUIT\r\n");
    fclose($socket);
    return ['success' => true, 'error' => ''];
}

function smtp_read_response($socket)
{
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}
