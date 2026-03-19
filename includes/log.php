<?php
function log_action(mysqli $conn, string $action, string $details = ''): void {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) return;
  $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, details) VALUES (?,?,?)");
  $stmt->bind_param('iss', $uid, $action, $details);
  $stmt->execute();
  $stmt->close();
}
?>
