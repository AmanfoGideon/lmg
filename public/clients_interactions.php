<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $client_id = (int)($_POST['client_id'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($client_id > 0 && $note !== '') {
    $stmt = $conn->prepare("INSERT INTO client_interactions (client_id, note) VALUES (?,?)");
    $stmt->bind_param('is', $client_id, $note);
    $stmt->execute();
    $stmt->close();
    log_action($conn, 'client_note', (string)$client_id);
  }
}
header('Location: clients.php');
exit;
