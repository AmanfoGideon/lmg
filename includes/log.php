<?php
declare(strict_types=1);

/** Remove sensitive keys before any structured values are persisted. */
function audit_redact(mixed $value): mixed {
  if (is_string($value)) return substr($value, 0, 2000);
  if (!is_array($value)) return $value;
  $safe = [];
  foreach (array_slice($value, 0, 50, true) as $key => $item) {
    if (preg_match('/password|token|secret|cookie|authorization|credential/i', (string)$key)) continue;
    $safe[$key] = audit_redact($item);
  }
  return $safe;
}

/** Append one server-authored audit event. Actor identity always comes from the PHP session. */
function audit_event(mysqli $conn, array $event): void {
  $action = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($event['action'] ?? ''));
  if (!$action || strlen($action) > 120) throw new InvalidArgumentException('Invalid audit action');
  $status = (string)($event['status'] ?? 'success');
  if (!in_array($status, ['success', 'failure', 'denied'], true)) $status = 'success';

  $actorId = (int)($_SESSION['user_id'] ?? 0);
  $actorId = $actorId > 0 ? $actorId : null;
  $actorName = trim((string)($_SESSION['full_name'] ?? ''));
  if ($actorName === '' && isset($event['actor_name_snapshot'])) $actorName = trim((string)$event['actor_name_snapshot']);
  $actorRole = trim((string)($_SESSION['role'] ?? ''));
  if ($actorRole === '' && isset($event['actor_role_snapshot'])) $actorRole = trim((string)$event['actor_role_snapshot']);
  $module = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($event['module'] ?? 'system'));
  $entityType = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($event['entity_type'] ?? ''));
  $entityId = isset($event['entity_id']) ? substr((string)$event['entity_id'], 0, 100) : null;
  $description = substr(trim((string)($event['description'] ?? $action)), 0, 500);
  $requestId = (string)($_SERVER['LMG_REQUEST_ID'] ?? '');
  if (!preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $requestId)) {
    $requestId = bin2hex(random_bytes(16));
    $_SERVER['LMG_REQUEST_ID'] = $requestId;
  }
  $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : null;
  $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
  if ($action === 'auth.login.failed' && $ip !== null) {
    $rateStmt = $conn->prepare("SELECT COUNT(*) FROM audit_events WHERE action='auth.login.failed' AND ip_address=? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE");
    $rateStmt->bind_param('s', $ip);
    $rateStmt->execute();
    $rateStmt->bind_result($recentFailures);
    $rateStmt->fetch();
    $rateStmt->close();
    if ((int)$recentFailures >= 20) {
      $markerStmt = $conn->prepare("SELECT id FROM audit_events WHERE action='auth.login.rate_limited' AND ip_address=? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE LIMIT 1");
      $markerStmt->bind_param('s', $ip);
      $markerStmt->execute();
      $markerStmt->store_result();
      $markerExists = $markerStmt->num_rows > 0;
      $markerStmt->close();
      if ($markerExists) return;
      $action = 'auth.login.rate_limited';
      $status = 'denied';
      $description = 'Repeated failed sign-ins were rate-limited for audit logging.';
    }
  }
  $oldValues = isset($event['old_values']) ? json_encode(audit_redact($event['old_values']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
  $newValues = isset($event['new_values']) ? json_encode(audit_redact($event['new_values']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
  $metadata = isset($event['metadata']) ? json_encode(audit_redact($event['metadata']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;

  $stmt = $conn->prepare("INSERT INTO audit_events
    (event_uuid, actor_user_id, actor_name_snapshot, actor_role_snapshot, action, module, entity_type, entity_id, description, status, old_values, new_values, metadata, ip_address, user_agent, request_id, origin, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
  $eventUuid = bin2hex(random_bytes(16));
  $origin = 'web';
  $stmt->bind_param('sisssssssssssssss', $eventUuid, $actorId, $actorName, $actorRole, $action, $module, $entityType, $entityId, $description, $status, $oldValues, $newValues, $metadata, $ip, $userAgent, $requestId, $origin);
  $stmt->execute();
  $stmt->close();
}

/** Keep the legacy user_logs feed intact while mirroring its successful events into audit_events. */
function log_action(mysqli $conn, string $action, string $details = '', array $context = []): void {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) return;

  $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, details) VALUES (?,?,?)");
  $stmt->bind_param('iss', $uid, $action, $details);
  $stmt->execute();
  $stmt->close();

  $map = [
    'transaction_create' => ['transaction.created', 'transactions', 'transaction', 'Transaction created.'],
    'transaction_update' => ['transaction.updated', 'transactions', 'transaction', 'Transaction updated.'],
    'transaction_delete' => ['transaction.deleted', 'transactions', 'transaction', 'Transaction deleted.'],
    'client_create' => ['client.created', 'clients', 'client', 'Client created.'],
    'client_update' => ['client.updated', 'clients', 'client', 'Client updated.'],
    'client_delete' => ['client.deleted', 'clients', 'client', 'Client deleted.'],
    'client_note' => ['client.note.created', 'clients', 'client', 'Client note created.'],
    'clients_import_csv' => ['client.import.completed', 'clients', 'client', 'Client import completed.'],
    'invoice_create' => ['invoice.created', 'invoices', 'invoice', 'Invoice created.'],
    'invoice_update' => ['invoice.updated', 'invoices', 'invoice', 'Invoice updated.'],
    'invoice_delete' => ['invoice.deleted', 'invoices', 'invoice', 'Invoice deleted.'],
    'invoice_cancel' => ['invoice.cancelled', 'invoices', 'invoice', 'Invoice cancelled.'],
    'invoice_status' => ['invoice.status.updated', 'invoices', 'invoice', 'Invoice status updated.'],
    'invoice_payment' => ['invoice.payment.recorded', 'invoices', 'invoice', 'Invoice payment recorded.'],
    'user_create' => ['user.created', 'users', 'user', 'User created.'],
    'user_status' => ['user.status.updated', 'users', 'user', 'User status updated.'],
    'user_delete' => ['user.deleted', 'users', 'user', 'User deleted.'],
    'product_create' => ['product.created', 'products', 'product', 'Product created.'],
    'product_update' => ['product.updated', 'products', 'product', 'Product updated.'],
    'product_delete' => ['product.deleted', 'products', 'product', 'Product deleted.'],
    'sms_send' => ['sms.sent', 'communications', 'sms', 'SMS operation completed.'],
    'sms_bulk_send' => ['sms.bulk_sent', 'communications', 'sms', 'Bulk SMS operation completed.'],
    'sms_connectivity' => ['sms.connectivity.checked', 'communications', 'sms', 'SMS connectivity checked.'],
    'email_send' => ['email.sent', 'communications', 'email', 'Email operation completed.'],
  ];
  [$eventName, $module, $entityType, $description] = $map[$action] ?? ['system.'.preg_replace('/[^a-zA-Z0-9._-]/', '', $action), 'system', '', 'System activity recorded.'];
  $status = 'success';
  if ($action === 'sms_connectivity' && $details === 'error') {
    $status = 'failure';
    $description = 'SMS connectivity check failed.';
  }
  $event = array_merge([
    'action' => $eventName,
    'module' => $module,
    'entity_type' => $entityType,
    'description' => $description,
    'status' => $status,
  ], $context);
  audit_event($conn, $event);
}
?>
