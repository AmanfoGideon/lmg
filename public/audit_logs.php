<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";

set_exception_handler(static function (Throwable $error): void {
  error_log('Audit trail request failed: '.$error->getMessage());
  http_response_code(503);
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Audit Trail unavailable</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="css/dashboard.css"></head><body><main class="container py-5"><h1 class="h3">Audit Trail</h1><div class="alert alert-warning" role="alert">Audit records could not be loaded. Please refresh and try again.</div><a class="btn btn-outline-light" href="audit_logs.php">Retry</a></main></body></html>';
  exit;
});

$role = $_SESSION['role'] ?? 'staff';
if ($role === 'admin') {
  $authStmt = $conn->prepare("SELECT role,status FROM users WHERE id=? LIMIT 1");
  $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
  $authStmt->bind_param('i', $sessionUserId);
  $authStmt->execute();
  $authStmt->bind_result($currentRole, $currentStatus);
  $roleIsAdmin = $authStmt->fetch() && $currentRole === 'admin' && $currentStatus === 'active';
  $authStmt->close();
} else {
  $roleIsAdmin = false;
}
if (!$roleIsAdmin) {
  audit_event($conn, [
    'action' => 'security.audit_access.denied', 'module' => 'audit', 'status' => 'denied',
    'description' => 'A user without administrator access tried to open the audit trail.'
  ]);
  header('Location: dashboard.php');
  exit;
}
$rateWindow = intdiv(time(), 60);
if (($_SESSION['audit_search_window'] ?? 0) !== $rateWindow) {
  $_SESSION['audit_search_window'] = $rateWindow;
  $_SESSION['audit_search_count'] = 0;
}
$_SESSION['audit_search_count'] = (int)($_SESSION['audit_search_count'] ?? 0) + 1;
if ($_SESSION['audit_search_count'] > 120) {
  http_response_code(429);
  exit('Too many audit searches. Wait a minute and try again.');
}
$isExport = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export';
if ($isExport && !csrf_verify()) {
  http_response_code(400);
  exit('Invalid request token');
}
$filterInput = $isExport ? $_POST : $_GET;

function audit_bind_params(mysqli_stmt $stmt, string $types, array &$values): void {
  if ($types === '') return;
  $args = [$types];
  foreach ($values as $key => &$value) $args[] = &$value;
  call_user_func_array([$stmt, 'bind_param'], $args);
}
function audit_date_filter(string $value): string {
  if ($value === '') return '';
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date && $date->format('Y-m-d') === $value ? $value : '';
}
function audit_input_string(mixed $value, int $limit): string {
  return is_string($value) || is_numeric($value) ? substr((string)$value, 0, $limit) : '';
}
function audit_date_utc_boundary(string $value, bool $dayAfter = false): string {
  $local = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(date_default_timezone_get()));
  if (!$local) return '';
  if ($dayAfter) $local = $local->modify('+1 day');
  return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function audit_csv_safe(mixed $value): string {
  $value = (string)$value;
  return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
}
function audit_display_time(string $value): string {
  try {
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
      ->setTimezone(new DateTimeZone(date_default_timezone_get()))
      ->format('Y-m-d H:i:s T');
  } catch (Throwable) { return $value.' UTC'; }
}

$q = trim(audit_input_string($filterInput['q'] ?? '', 120));
$userId = filter_var(audit_input_string($filterInput['user_id'] ?? '', 20), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$module = audit_input_string($filterInput['module'] ?? '', 80);
$action = audit_input_string($filterInput['action_type'] ?? '', 120);
$status = audit_input_string($filterInput['status'] ?? '', 16);
if (!in_array($status, ['', 'success', 'failure', 'denied'], true)) $status = '';
$start = audit_date_filter(audit_input_string($filterInput['start'] ?? '', 10));
$end = audit_date_filter(audit_input_string($filterInput['end'] ?? '', 10));
$conditions = [];
$types = '';
$values = [];
if ($q !== '') {
  $conditions[] = '(actor_name_snapshot LIKE ? OR action LIKE ? OR description LIKE ? OR entity_id LIKE ? OR request_id LIKE ?)';
  $needle = '%'.$q.'%';
  array_push($values, $needle, $needle, $needle, $needle, $needle);
  $types .= 'sssss';
}
if ($userId) { $conditions[] = 'actor_user_id=?'; $values[] = $userId; $types .= 'i'; }
if ($module !== '') { $conditions[] = 'module=?'; $values[] = $module; $types .= 's'; }
if ($action !== '') { $conditions[] = 'action=?'; $values[] = $action; $types .= 's'; }
if ($status !== '') { $conditions[] = 'status=?'; $values[] = $status; $types .= 's'; }
if ($start !== '') { $conditions[] = 'created_at >= ?'; $values[] = audit_date_utc_boundary($start); $types .= 's'; }
if ($end !== '') { $conditions[] = 'created_at < ?'; $values[] = audit_date_utc_boundary($end, true); $types .= 's'; }
$where = $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';

$countStmt = $conn->prepare('SELECT COUNT(*) FROM audit_events'.$where);
$countValues = $values;
audit_bind_params($countStmt, $types, $countValues);
$countStmt->execute();
$countStmt->bind_result($total);
$countStmt->fetch();
$countStmt->close();
$total = (int)$total;
$pageSize = 25;
$pages = max(1, (int)ceil($total / $pageSize));
$pageInput = filter_var(audit_input_string($_GET['page'] ?? '1', 10), FILTER_VALIDATE_INT) ?: 1;
$page = min($pages, max(1, $pageInput));
$offset = ($page - 1) * $pageSize;
$limit = $isExport ? 10000 : $pageSize;
$selectSql = 'SELECT id,event_uuid,actor_user_id,actor_name_snapshot,actor_role_snapshot,action,module,entity_type,entity_id,description,status,old_values,new_values,metadata,ip_address,user_agent,request_id,origin,created_at FROM audit_events'.$where.' ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?';
$selectStmt = $conn->prepare($selectSql);
$selectValues = $values;
$selectValues[] = $limit;
$selectValues[] = $isExport ? 0 : $offset;
$selectTypes = $types.'ii';
audit_bind_params($selectStmt, $selectTypes, $selectValues);
$selectStmt->execute();
$result = $selectStmt->get_result();
$events = [];
while ($row = $result->fetch_assoc()) $events[] = $row;
$selectStmt->close();

if ($isExport) {
  audit_event($conn, [
    'action' => 'audit.exported', 'module' => 'audit', 'entity_type' => 'audit_event',
    'description' => 'Administrator exported filtered audit events to CSV.',
    'metadata' => ['filter_count' => count($conditions), 'exported_count' => count($events), 'limit_applied' => 10000]
  ]);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=audit_trail_'.gmdate('Ymd_His').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Date (UTC)','User','Role','Action','Module','Record','Status','Description','Old values','New values','Request ID'], ',', '"', '');
  foreach ($events as $event) {
    $record = trim(($event['entity_type'] ?? '').' '.($event['entity_id'] ?? ''));
    $line = [$event['created_at'].' UTC',$event['actor_name_snapshot'],$event['actor_role_snapshot'],$event['action'],$event['module'],$record,$event['status'],$event['description'],$event['old_values'],$event['new_values'],$event['request_id']];
    fputcsv($out, array_map('audit_csv_safe', $line), ',', '"', '');
  }
  fclose($out);
  exit;
}

$modules = [];
$moduleRes = $conn->query("SELECT DISTINCT module FROM audit_events ORDER BY module");
while ($item = $moduleRes->fetch_assoc()) $modules[] = (string)$item['module'];
$actions = [];
$actionRes = $conn->query("SELECT DISTINCT action FROM audit_events ORDER BY action");
while ($item = $actionRes->fetch_assoc()) $actions[] = (string)$item['action'];
$actors = [];
$actorRes = $conn->query("SELECT actor_user_id,MAX(actor_name_snapshot) AS actor_name FROM audit_events WHERE actor_user_id IS NOT NULL GROUP BY actor_user_id,actor_name_snapshot ORDER BY actor_name_snapshot");
while ($item = $actorRes->fetch_assoc()) $actors[] = $item;
$queryParams = $filterInput;
unset($queryParams['page'], $queryParams['export'], $queryParams['action'], $queryParams['_csrf']);
$queryBase = http_build_query($queryParams);
$userName = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Audit Trail — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='audit'; include "../includes/layout/sidebar.php"; ?>
  <main class="main flex-grow-1 p-4">
    <?php $page_title='Audit Trail'; $page_subtitle='Monitor user activities and important changes across the system.'; $header_actions=''; include "../includes/layout/header.php"; ?>
    <div class="card p-3 mb-3">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label" for="auditSearch">Search</label><input id="auditSearch" class="form-control" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="User, action, record, request ID"></div>
        <div class="col-md-2"><label class="form-label" for="auditUser">User</label><select class="form-select" id="auditUser" name="user_id"><option value="">All users</option><?php foreach ($actors as $actor): ?><option value="<?= (int)$actor['actor_user_id'] ?>" <?= $userId===(int)$actor['actor_user_id']?'selected':'' ?>><?= htmlspecialchars($actor['actor_name'] ?: 'Deleted user', ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="auditModule">Module</label><select class="form-select" id="auditModule" name="module"><option value="">All modules</option><?php foreach ($modules as $value): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $module===$value?'selected':'' ?>><?= htmlspecialchars(ucfirst($value), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="auditAction">Activity</label><select class="form-select" id="auditAction" name="action_type"><option value="">All activities</option><?php foreach ($actions as $value): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $action===$value?'selected':'' ?>><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="auditStatus">Outcome</label><select class="form-select" id="auditStatus" name="status"><option value="">All outcomes</option><?php foreach (['success','failure','denied'] as $value): ?><option value="<?= $value ?>" <?= $status===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="auditStart">From</label><input id="auditStart" class="form-control" type="date" name="start" value="<?= htmlspecialchars($start, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="col-md-2"><label class="form-label" for="auditEnd">To</label><input id="auditEnd" class="form-control" type="date" name="end" value="<?= htmlspecialchars($end, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="col-md-8"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Apply filters</button></div>
      </form>
      <div class="d-flex gap-2 flex-wrap mt-2">
        <a class="btn btn-outline-light" href="audit_logs.php"><i class="bi bi-x-circle"></i> Clear</a>
        <a class="btn btn-outline-light" href="audit_logs.php<?= $queryBase ? '?'.htmlspecialchars($queryBase, ENT_QUOTES, 'UTF-8') : '' ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
        <form method="POST" action="audit_logs.php" class="d-inline">
          <?= csrf_input() ?>
          <?php foreach (['q'=>$q,'user_id'=>$userId,'module'=>$module,'action_type'=>$action,'status'=>$status,'start'=>$start,'end'=>$end] as $key=>$value): if ($value !== '' && $value !== 0): ?>
          <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>">
          <?php endif; endforeach; ?>
          <button class="btn btn-outline-primary" type="submit" name="action" value="export"><i class="bi bi-download"></i> Export CSV (max 10,000)</button>
        </form>
      </div>
    </div>
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h6 mb-0">Activity events</h3><span class="small text-muted"><?= number_format($total) ?> records</span></div>
      <?php if (!$events): ?><div class="text-muted py-4 text-center" role="status">No audit events match these filters.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-borderless table-sm table-striped table-hover table-brand align-middle">
        <thead><tr class="text-muted small"><th>Date &amp; Time</th><th>User</th><th>Activity</th><th>Module</th><th>Record</th><th>Status</th><th>Details</th></tr></thead>
        <tbody><?php foreach ($events as $event): $record = trim(($event['entity_type'] ?? '').' '.($event['entity_id'] ?? '')); ?>
          <tr><td class="text-nowrap small"><?= htmlspecialchars(audit_display_time($event['created_at']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($event['actor_name_snapshot'] ?: 'System / unknown', ENT_QUOTES, 'UTF-8') ?><div class="small text-muted"><?= htmlspecialchars($event['actor_role_snapshot'], ENT_QUOTES, 'UTF-8') ?></div></td><td><?= htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8') ?><div class="small text-muted font-monospace"><?= htmlspecialchars($event['action'], ENT_QUOTES, 'UTF-8') ?></div></td><td><?= htmlspecialchars(ucfirst($event['module']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($record ?: '—', ENT_QUOTES, 'UTF-8') ?></td><td><span class="badge bg-<?= $event['status']==='success'?'success':($event['status']==='denied'?'danger':'warning') ?>"><?= htmlspecialchars(ucfirst($event['status']), ENT_QUOTES, 'UTF-8') ?></span></td><td><details><summary class="link-primary">View details</summary><div class="small mt-2" style="min-width:260px;max-width:520px"><div><strong>Event:</strong> <?= htmlspecialchars($event['event_uuid'], ENT_QUOTES, 'UTF-8') ?></div><div><strong>Request:</strong> <?= htmlspecialchars($event['request_id'], ENT_QUOTES, 'UTF-8') ?></div><div><strong>Origin:</strong> <?= htmlspecialchars($event['origin'], ENT_QUOTES, 'UTF-8') ?></div><?php foreach (['old_values'=>'Before','new_values'=>'After','metadata'=>'Metadata'] as $key=>$label): if (!empty($event[$key])): ?><div class="mt-1"><strong><?= $label ?>:</strong><pre class="small mb-1 text-wrap"><?= htmlspecialchars((string)$event[$key], ENT_QUOTES, 'UTF-8') ?></pre></div><?php endif; endforeach; ?><div class="text-muted">IP: <?= htmlspecialchars($event['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($event['user_agent'] ?? '', ENT_QUOTES, 'UTF-8') ?></div></div></details></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
      <nav class="d-flex justify-content-between align-items-center" aria-label="Audit pages"><span class="small text-muted">Page <?= $page ?> of <?= $pages ?></span><div class="d-flex gap-2"><?php if ($page>1): ?><a class="btn btn-sm btn-outline-light" href="?<?= htmlspecialchars(http_build_query(array_merge($queryParams,['page'=>$page-1])), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?><?php if ($page<$pages): ?><a class="btn btn-sm btn-outline-light" href="?<?= htmlspecialchars(http_build_query(array_merge($queryParams,['page'=>$page+1])), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?></div></nav>
    </div>
    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/ui.js"></script>
</body>
</html>
