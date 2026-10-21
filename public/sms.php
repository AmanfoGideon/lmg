<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/sms.php";

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
  $action = $_POST['action'] ?? '';
  if ($action === 'send_single') {
    $client_name = trim($_POST['client_name'] ?? '');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $text = trim($_POST['text'] ?? '');
    $schedule_for = trim($_POST['schedule_for'] ?? '');
    $tz_offset = trim($_POST['tz_offset'] ?? '');
    $selected_ids = array_values(array_filter(array_map('intval', $_POST['client_ids'] ?? []), function($v){ return $v > 0; }));
    if ($client_id <= 0 && $client_name !== '') {
      $find = $conn->prepare("SELECT id, phone FROM clients WHERE full_name=? LIMIT 1");
      $find->bind_param('s', $client_name);
      $find->execute();
      $find->bind_result($foundId,$foundPhone);
      if ($find->fetch()) {
        $client_id = (int)$foundId;
        if ($phone === '') { $phone = trim((string)$foundPhone); }
      }
      $find->close();
    }
    if (($phone === '' && count($selected_ids) === 0) || $text === '') {
      $message = 'Enter phone or select clients, and enter message';
    } else {
      $recipients = [];
      if ($phone !== '') { $recipients[] = ['num'=>$phone, 'cid'=>($client_id>0?$client_id:null)]; }
      if (count($selected_ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $typesSel = str_repeat('i', count($selected_ids));
        $selStmt = $conn->prepare("SELECT id, phone FROM clients WHERE id IN ($placeholders)");
        $selStmt->bind_param($typesSel, ...$selected_ids);
        $selStmt->execute();
        $selRes = $selStmt->get_result();
        while ($row = $selRes->fetch_assoc()) {
          $num = trim((string)$row['phone']);
          if ($num !== '') {
            $recipients[] = ['num'=>$num, 'cid'=>(int)$row['id']];
          }
        }
        $selStmt->close();
      }
      $seen = [];
      $sent = 0;
      $failed = 0;
      foreach ($recipients as $r) {
        $n = $r['num'];
        if (isset($seen[$n])) continue;
        $seen[$n] = true;
        $res = sms_send($conn, $n, $text, $r['cid'], $schedule_for !== '' ? $schedule_for : null, $tz_offset !== '' ? $tz_offset : null);
        if ($res['success']) $sent++; else $failed++;
      }
      $message = ($sent>0 ? "Sent {$sent}" : "").($sent>0 && $failed>0 ? ", " : "").($failed>0 ? "Failed {$failed}" : "");
      if ($message === '') { $message = 'No messages processed'; }
      log_action($conn, 'sms_send', (string)$sent.'|'.(string)$failed, [
        'status' => $failed > 0 ? 'failure' : 'success',
        'metadata' => ['sent'=>$sent,'failed'=>$failed]
      ]);
    }
  }
  if ($action === 'send_bulk') {
    $phonesRaw = trim($_POST['phones'] ?? '');
    $text = trim($_POST['text'] ?? '');
    $schedule_for = trim($_POST['schedule_for'] ?? '');
    $tz_offset = trim($_POST['tz_offset'] ?? '');
    $selected_ids = array_values(array_filter(array_map('intval', $_POST['client_ids'] ?? []), function($v){ return $v > 0; }));
    if ($phonesRaw === '' && count($selected_ids) === 0 || $text === '') {
      $message = 'Enter phones or select clients, and enter message';
    } else {
      $parts = preg_split('/[\\s,;]+/', $phonesRaw, -1, PREG_SPLIT_NO_EMPTY);
      if (count($selected_ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $typesSel = str_repeat('i', count($selected_ids));
        $selStmt = $conn->prepare("SELECT phone FROM clients WHERE id IN ($placeholders)");
        $selStmt->bind_param($typesSel, ...$selected_ids);
        $selStmt->execute();
        $selRes = $selStmt->get_result();
        while ($row = $selRes->fetch_assoc()) {
          $num = trim((string)$row['phone']);
          if ($num !== '') { $parts[] = $num; }
        }
        $selStmt->close();
      }
      $seen = [];
      $sent = 0;
      $failed = 0;
      $queued = 0;
      $limit = 200;
      $count = 0;
      foreach ($parts as $p) {
        $num = trim($p);
        if ($num === '') continue;
        if (isset($seen[$num])) continue;
        $seen[$num] = true;
        $count++;
        if ($count > $limit) break;
        $res = sms_send($conn, $num, $text, null, $schedule_for !== '' ? $schedule_for : null, $tz_offset !== '' ? $tz_offset : null);
        if ($res['success']) {
          if (($res['status'] ?? '') === 'queued') $queued++; else $sent++;
        } else {
          $failed++;
        }
      }
      if ($sent>0 && $queued>0) {
        $message = "Bulk SMS: Sent {$sent}, Queued {$queued}, Failed {$failed}";
      } else if ($queued>0 && $sent===0) {
        $message = "Bulk SMS: Queued {$queued}, Failed {$failed}";
      } else {
        $message = "Bulk SMS: Sent {$sent}, Failed {$failed}";
      }
      log_action($conn, 'sms_bulk_send', (string)$sent.'|'.(string)$failed, [
        'status' => $failed > 0 ? 'failure' : 'success',
        'metadata' => ['sent'=>$sent,'queued'=>$queued,'failed'=>$failed]
      ]);
    }
  }
  if ($action === 'check_connectivity') {
    $check = sms_connectivity_check();
    if ($check['ok'] ?? false) {
      $message = "Connectivity OK on host ".htmlspecialchars((string)$check['host']);
      log_action($conn, 'sms_connectivity', 'ok');
    } else {
      $message = "Connectivity Error: ".htmlspecialchars((string)$check['error'])." (host ".htmlspecialchars((string)$check['host']).")";
      log_action($conn, 'sms_connectivity', 'error');
    }
  }
  }
}

$q = trim($_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 25);
if (!in_array($limit, [10,25,50,100], true)) { $limit = 25; }
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $limit;
$whereSql = '';
$types = '';
$vals = [];
if ($q !== '') {
  $whereSql = "WHERE (phone LIKE ? OR message LIKE ? OR provider LIKE ?)";
  $like = "%".$q."%";
  $types = 'sss';
  $vals = [$like,$like,$like];
}
$countSql = "SELECT COUNT(*) AS c FROM sms_messages ".$whereSql;
$countStmt = $conn->prepare($countSql);
if ($types) { $countStmt->bind_param($types, ...$vals); }
$countStmt->execute();
$countRes = $countStmt->get_result();
$totalLogs = (int)($countRes->fetch_assoc()['c'] ?? 0);
$countStmt->close();
if ($offset >= $totalLogs && $totalLogs > 0) {
  $page = (int)ceil($totalLogs / $limit);
  $offset = ($page - 1) * $limit;
}
$listSql = "SELECT id, client_id, phone, message, status, error, provider, provider_message_id, created_at FROM sms_messages ".$whereSql." ORDER BY id DESC LIMIT ? OFFSET ?";
$listStmt = $conn->prepare($listSql);
if ($types) {
  $bindTypes = $types.'ii';
  $listStmt->bind_param($bindTypes, ...array_merge($vals, [$limit, $offset]));
} else {
  $listStmt->bind_param('ii', $limit, $offset);
}
$listStmt->execute();
$logs = $listStmt->get_result();
$pagesTotal = $limit > 0 ? (int)ceil($totalLogs / $limit) : 1;

$clientsRes = $conn->query("SELECT id, full_name, phone FROM clients ORDER BY full_name ASC");
$clientsArr = [];
while ($row = $clientsRes->fetch_assoc()) { $clientsArr[] = $row; }
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>SMS — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="../favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
  <link rel="apple-touch-icon" sizes="180x180" href="../favicon/apple-touch-icon.png">
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='sms'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='SMS'; $page_subtitle='Send and track messages'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" class="mb-3 d-flex">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="check_connectivity">
      <button class="btn btn-sm btn-outline-secondary">Connectivity Check</button>
    </form>

    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card p-3">
          <h6 class="mb-3">Send Single SMS</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_single">
            <div class="col-12">
              <label class="form-label">Client</label>
              <input class="form-control" list="clientsDatalist" name="client_name" id="clientNameSms" placeholder="Type client name (optional)">
              <datalist id="clientsDatalist">
                <?php foreach($clientsArr as $c): ?>
                  <option value="<?= htmlspecialchars($c['full_name']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <input type="hidden" name="client_id" id="clientIdSms">
            </div>
            <div class="col-12"><input class="form-control" name="phone" id="phoneSms" placeholder="Phone"></div>
            <div class="col-12">
              <div class="d-flex justify-content-between align-items-center">
                <label class="form-label mb-0">Select clients</label>
                <div class="form-check form-check-sm">
                  <input class="form-check-input" type="checkbox" id="selectAllSingle">
                  <label class="form-check-label small" for="selectAllSingle">Select all</label>
                </div>
              </div>
              <select class="form-select" name="client_ids[]" multiple size="8" id="clientSelectSingle">
                <?php foreach($clientsArr as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?><?= $c['phone']?' ('.htmlspecialchars($c['phone']).')':'' ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Hold Ctrl or Shift to select multiple</div>
            </div>
            <div class="col-12"><textarea class="form-control" name="text" rows="3" placeholder="Message" required></textarea></div>
            <div class="col-sm-7">
              <label class="form-label">Schedule for</label>
              <input type="datetime-local" class="form-control" name="schedule_for">
            </div>
            <div class="col-sm-5">
              <label class="form-label">UTC offset</label>
              <input type="text" class="form-control" name="tz_offset" placeholder="+00:00">
            </div>
            <div class="col-12"><button class="btn btn-primary w-100">Send</button></div>
          </form>
        </div>
        <div class="card p-3 mt-3">
          <h6 class="mb-3">Send Bulk SMS</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_bulk">
            <div class="col-12"><textarea class="form-control" name="phones" rows="4" placeholder="Enter phone numbers separated by comma, semicolon or new lines"></textarea></div>
            <div class="col-12">
              <div class="d-flex justify-content-between align-items-center">
                <label class="form-label mb-0">Select clients</label>
                <div class="form-check form-check-sm">
                  <input class="form-check-input" type="checkbox" id="selectAllBulk">
                  <label class="form-check-label small" for="selectAllBulk">Select all</label>
                </div>
              </div>
              <select class="form-select" name="client_ids[]" multiple size="8" id="clientSelectBulk">
                <?php foreach($clientsArr as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?><?= $c['phone']?' ('.htmlspecialchars($c['phone']).')':'' ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Hold Ctrl or Shift to select multiple</div>
            </div>
            <div class="col-12"><textarea class="form-control" name="text" rows="3" placeholder="Message" required></textarea></div>
            <div class="col-sm-7">
              <label class="form-label">Schedule for</label>
              <input type="datetime-local" class="form-control" name="schedule_for">
            </div>
            <div class="col-sm-5">
              <label class="form-label">UTC offset</label>
              <input type="text" class="form-control" name="tz_offset" placeholder="+00:00">
            </div>
            <div class="col-12"><button class="btn btn-outline-primary w-100">Send Bulk</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card p-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex align-items-center gap-3">
              <h6 class="mb-0">SMS Logs</h6>
              <div class="text-muted small">Total: <?= (int)$totalLogs ?><?php if($totalLogs>0): ?> · Showing <?= (int)($offset+1) ?>–<?= (int)min($offset+$limit,$totalLogs) ?><?php endif; ?></div>
            </div>
            <form method="GET" class="d-flex align-items-center gap-2">
              <input type="text" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search phone, provider, message" style="min-width:280px">
              <select class="form-select form-select-sm" name="limit" style="width:auto">
                <option value="10"<?= $limit===10?' selected':'' ?>>10</option>
                <option value="25"<?= $limit===25?' selected':'' ?>>25</option>
                <option value="50"<?= $limit===50?' selected':'' ?>>50</option>
                <option value="100"<?= $limit===100?' selected':'' ?>>100</option>
              </select>
              <button class="btn btn-sm btn-outline-light">Search</button>
              <a class="btn btn-sm btn-outline-light" href="sms.php">Reset</a>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable">
              <thead>
                <tr class="text-muted small">
                  <th>#</th>
                  <th>Phone</th>
                  <th>Status</th>
                  <th>Error</th>
                  <th>Provider</th>
                  <th>Message ID</th>
                  <th>Message</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php while($l = $logs->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$l['id'] ?></td>
                  <td><?= htmlspecialchars($l['phone']) ?></td>
                  <td><span class="badge bg-<?= $l['status']==='sent'?'success':($l['status']==='failed'?'danger':'secondary') ?>"><?= htmlspecialchars($l['status']) ?></span></td>
                  <td class="text-muted small" style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= htmlspecialchars($l['error'] ?? '') ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($l['provider'] ?? '') ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($l['provider_message_id'] ?? '') ?></td>
                  <td class="text-muted small" style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= htmlspecialchars($l['message']) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($l['created_at']) ?></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="text-muted small">Page <?= (int)$page ?> of <?= (int)max($pagesTotal,1) ?></div>
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <?php
                  $base = 'sms.php?'.http_build_query(['q'=>$q,'limit'=>$limit]);
                  $prevPage = max(1, $page-1);
                  $nextPage = min(max($pagesTotal,1), $page+1);
                ?>
                <li class="page-item<?= $page<=1?' disabled':'' ?>"><a class="page-link" href="<?= $base.'&page='.$prevPage ?>">Prev</a></li>
                <?php
                  $start = max(1, $page-2);
                  $end = min(max($pagesTotal,1), $page+2);
                  for ($p=$start;$p<=$end;$p++):
                ?>
                  <li class="page-item<?= $p===$page?' active':'' ?>"><a class="page-link" href="<?= $base.'&page='.$p ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item<?= $page>=$pagesTotal?' disabled':'' ?>"><a class="page-link" href="<?= $base.'&page='.$nextPage ?>">Next</a></li>
              </ul>
            </nav>
          </div>
        </div>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  (function(){
    const clients = <?php echo json_encode($clientsArr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    const nameInput = document.getElementById('clientNameSms');
    const idInput = document.getElementById('clientIdSms');
    const phoneInput = document.getElementById('phoneSms');
    const selectAllSingle = document.getElementById('selectAllSingle');
    const clientSelectSingle = document.getElementById('clientSelectSingle');
    const selectAllBulk = document.getElementById('selectAllBulk');
    const clientSelectBulk = document.getElementById('clientSelectBulk');
    const mapByName = new Map(clients.map(c => [c.full_name, c]));
    function syncClient(){
      const name = (nameInput.value||'').trim();
      const c = mapByName.get(name);
      idInput.value = c ? c.id : '';
      if (c && (!phoneInput.value || phoneInput.value.trim()==='')) {
        phoneInput.value = c.phone || '';
      }
    }
    nameInput?.addEventListener('change', syncClient);
    nameInput?.addEventListener('input', syncClient);
    function toggleAll(select, checked){
      if (!select) return;
      for (let i=0;i<select.options.length;i++){
        select.options[i].selected = checked;
      }
    }
    selectAllSingle?.addEventListener('change', function(){ toggleAll(clientSelectSingle, this.checked); });
    selectAllBulk?.addEventListener('change', function(){ toggleAll(clientSelectBulk, this.checked); });
  })();
  </script>
<script>
(function(){
  function formToObject(form){
    const fd = new FormData(form);
    const obj = {};
    fd.forEach((v,k)=>{
      if (k.endsWith('[]')) {
        const key = k.slice(0, -2);
        if (!obj[key]) obj[key] = [];
        obj[key].push(v);
      } else {
        if (obj[k] !== undefined) {
          if (!Array.isArray(obj[k])) obj[k] = [obj[k]];
          obj[k].push(v);
        } else {
          obj[k] = v;
        }
      }
    });
    return obj;
  }
  function queueSMS(form){
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.ready.then(reg=>{
      const data = formToObject(form);
      if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
          type: 'QUEUE_SMS',
          item: { path: 'sms.php', data }
        });
      }
      try { if (reg.sync) reg.sync.register('sms-sync'); } catch(e){}
    });
  }
  function onSubmit(e){
    const form = e.target;
    const actionInput = form.querySelector('input[name="action"]');
    const action = actionInput ? actionInput.value : '';
    if (action !== 'send_single' && action !== 'send_bulk') return;
    if (!navigator.onLine) {
      e.preventDefault();
      queueSMS(form);
      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = false;
      alert('You are offline. SMS queued and will send when online.');
    }
  }
  document.querySelectorAll('form').forEach(f=>f.addEventListener('submit', onSubmit));
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.ready.then(()=>{
      if (navigator.onLine && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'FLUSH_SMS_QUEUE' });
      }
    });
  }
})();
</script>
<script>
  jQuery(function($){
    $('table.datatable').each(function(){
      const el = this;
      if ($.fn.DataTable.isDataTable(el)) return;
      $(el).DataTable({
        pageLength: 10,
        lengthMenu: [10,25,50,100],
        order: [],
        stateSave: true,
        language: { searchPlaceholder: 'Search...' }
      });
    });
  });
</script>
<script src="js/ui.js"></script>
</body>
</html>
