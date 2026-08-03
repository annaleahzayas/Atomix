<?php
$logFile = __DIR__ . '/resend_verification_log.txt';
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Resend Verification Log</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    pre { background: #fff; border: 1px solid #ddd; padding: 16px; white-space: pre-wrap; word-wrap: break-word; }
    .empty { color: #666; }
  </style>
</head>
<body>
  <h1>Resend Verification Log</h1>
  <?php if (!file_exists($logFile)): ?>
    <p class="empty">No log file found. Trigger resend verification first, then refresh this page.</p>
  <?php else: ?>
    <p>Showing contents of <code>resend_verification_log.txt</code></p>
    <pre><?php echo htmlspecialchars(file_get_contents($logFile), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>
  <?php endif; ?>
</body>
</html>
