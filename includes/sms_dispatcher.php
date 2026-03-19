<?php
require_once __DIR__."/db.php";
require_once __DIR__."/sms.php";
$hasScheduledFor = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'scheduled_for'")->num_rows > 0;
$hasOffset = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'schedule_offset'")->num_rows > 0;
$hasRetryCount = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'retry_count'")->num_rows > 0;
$hasNextRetry = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'next_retry_at'")->num_rows > 0;
$hasLastAttempt = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'last_attempt_at'")->num_rows > 0;
if (!$hasRetryCount) { $conn->query("ALTER TABLE sms_messages ADD COLUMN retry_count INT NOT NULL DEFAULT 0"); $hasRetryCount = true; }
if (!$hasNextRetry) { $conn->query("ALTER TABLE sms_messages ADD COLUMN next_retry_at DATETIME NULL"); $hasNextRetry = true; }
if (!$hasLastAttempt) { $conn->query("ALTER TABLE sms_messages ADD COLUMN last_attempt_at DATETIME NULL"); $hasLastAttempt = true; }
$strictOffset = ($hasScheduledFor && $hasOffset && getenv('SMS_STRICT_OFFSET') !== '0');
$maxRetry = (int)(getenv('SMS_MAX_RETRY') ?: 3);
$baseBackoffMin = (int)(getenv('SMS_RETRY_BASE_MINUTES') ?: 15);
$fields = ["id","client_id","phone","message","status"];
if ($hasScheduledFor) { $fields[] = "scheduled_for"; }
if ($hasOffset) { $fields[] = "schedule_offset"; }
if ($hasRetryCount) { $fields[] = "retry_count"; }
if ($hasNextRetry) { $fields[] = "next_retry_at"; }
$sql = "SELECT ".implode(", ", $fields)." FROM sms_messages WHERE provider_message_id IS NULL AND (status='queued' OR status='failed') LIMIT 500";
$res = $conn->query($sql);
$nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
if ($res && $res->num_rows > 0) {
  while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $cid = isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null;
    $phone = (string)$row['phone'];
    $msg = (string)$row['message'];
    $status = (string)$row['status'];
    $sf = $hasScheduledFor ? (isset($row['scheduled_for']) ? trim((string)$row['scheduled_for']) : '') : '';
    $so = $hasOffset ? (isset($row['schedule_offset']) ? trim((string)$row['schedule_offset']) : '') : '';
    $rc = $hasRetryCount ? (int)$row['retry_count'] : 0;
    $nr = $hasNextRetry ? (isset($row['next_retry_at']) ? trim((string)$row['next_retry_at']) : '') : '';
    $due = false;
    if ($status === 'queued') {
      if ($strictOffset) {
        if ($sf === '' || $so === '' || !preg_match('/^[+-]\\d{2}:\\d{2}$/', $so)) { $due = false; }
        else {
          try { $schedLocal = new \DateTime($sf, new \DateTimeZone('UTC')); } catch (\Throwable $e) { $schedLocal = $nowUtc; }
          $sign = $so[0] === '-' ? -1 : 1;
          $h = (int)substr($so, 1, 2);
          $m = (int)substr($so, 4, 2);
          $offsetSeconds = $sign * ($h * 3600 + $m * 60);
          $schedUtc = clone $schedLocal;
          $schedUtc->modify(($sign === 1 ? '-' : '+').sprintf('%02d:%02d', $h, $m));
          $due = ($schedUtc <= $nowUtc);
        }
      } else {
        if ($sf !== '') {
          try { $sched = new \DateTime($sf); } catch (\Throwable $e) { $sched = $nowUtc; }
          $due = ($sched <= new \DateTime());
        } else { $due = true; }
      }
    } else if ($status === 'failed') {
      if ($strictOffset && $sf !== '' && $so !== '' && preg_match('/^[+-]\\d{2}:\\d{2}$/', $so)) {
        try { $schedLocal = new \DateTime($sf, new \DateTimeZone('UTC')); } catch (\Throwable $e) { $schedLocal = $nowUtc; }
        $sign = $so[0] === '-' ? -1 : 1;
        $h = (int)substr($so, 1, 2);
        $m = (int)substr($so, 4, 2);
        $schedUtc = clone $schedLocal;
        $schedUtc->modify(($sign === 1 ? '-' : '+').sprintf('%02d:%02d', $h, $m));
        if ($schedUtc > $nowUtc) { $due = false; } else { $due = true; }
      } else {
        if ($maxRetry > 0 && $rc < $maxRetry) {
          if ($nr === '') { $due = true; }
          else {
            try { $nextRetry = new \DateTime($nr, new \DateTimeZone('UTC')); } catch (\Throwable $e) { $nextRetry = $nowUtc; }
            $due = ($nextRetry <= $nowUtc);
          }
        }
      }
    }
    if (!$due) { continue; }
    if ($status === 'failed' && $hasRetryCount && $hasLastAttempt) {
      $stmt = $conn->prepare("UPDATE sms_messages SET status='sending', retry_count=retry_count+1, last_attempt_at=UTC_TIMESTAMP(), next_retry_at=NULL WHERE id=? AND status='failed'");
      $stmt->bind_param('i', $id);
    } else {
      $stmt = $conn->prepare("UPDATE sms_messages SET status='sending' WHERE id=? AND status='queued'");
      $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) { continue; }
    $resp = sms_send($conn, $phone, $msg, $cid, null, null, true);
    $prov = isset($resp['provider_message_id']) && $resp['provider_message_id'] ? (string)$resp['provider_message_id'] : null;
    $stat = isset($resp['status']) && in_array($resp['status'], ['sent','queued','failed'], true) ? $resp['status'] : 'sent';
    $err = isset($resp['error']) ? (string)$resp['error'] : '';
    if ($stat === 'failed' && $hasNextRetry && $hasRetryCount) {
      $curRc = $rc + 1;
      $factor = ($curRc > 1 ? pow(2, min($curRc - 1, 3)) : 1);
      $mins = max(1, (int)($baseBackoffMin * $factor));
      $next = clone $nowUtc;
      $next->modify("+{$mins} minutes");
      $nextStr = $next->format('Y-m-d H:i:s');
      $stmt2 = $conn->prepare("UPDATE sms_messages SET status=?, provider_message_id=?, error=?, next_retry_at=? WHERE id=?");
      $stmt2->bind_param('ssssi', $stat, $prov, $err, $nextStr, $id);
    } else {
      $stmt2 = $conn->prepare("UPDATE sms_messages SET status=?, provider_message_id=?, error=?, next_retry_at=NULL WHERE id=?");
      $stmt2->bind_param('sssi', $stat, $prov, $err, $id);
    }
    $stmt2->execute();
    $stmt2->close();
  }
}
echo "done";
?>
