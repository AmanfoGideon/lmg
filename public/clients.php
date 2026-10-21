<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/sms.php";

$message = '';
if (isset($_GET['msg'])) { $message = $_GET['msg']; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $action = $_GET['action'] ?? '';
  if ($action === 'export_csv') {
    $q = trim($_GET['q'] ?? '');
    $limit = (int)($_GET['limit'] ?? 25);
    $page = (int)($_GET['page'] ?? 1);
    if (!in_array($limit, [10,25,50,100], true)) { $limit = 25; }
    if ($page < 1) { $page = 1; }
    $offset = ($page - 1) * $limit;
    $whereSql = '';
    $types = '';
    $vals = [];
    if ($q !== '') {
      $whereSql = "WHERE (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?)";
      $like = "%".$q."%";
      $types = 'ssss';
      $vals = [$like,$like,$like,$like];
    }
    $sql = "SELECT full_name, phone, email, address FROM clients ".$whereSql." ORDER BY full_name ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($types) {
      $bindTypes = $types.'ii';
      $stmt->bind_param($bindTypes, ...array_merge($vals, [$limit, $offset]));
    } else {
      $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=clients.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Full Name','Phone','Email','Address']);
    while ($r = $res->fetch_assoc()) {
      fputcsv($out, [$r['full_name'], $r['phone'], $r['email'], $r['address']]);
    }
    fclose($out);
    $stmt->close();
    exit;
  }
  if ($action === 'export_excel') {
    $q = trim($_GET['q'] ?? '');
    $limit = (int)($_GET['limit'] ?? 25);
    $page = (int)($_GET['page'] ?? 1);
    if (!in_array($limit, [10,25,50,100], true)) { $limit = 25; }
    if ($page < 1) { $page = 1; }
    $offset = ($page - 1) * $limit;
    $whereSql = '';
    $types = '';
    $vals = [];
    if ($q !== '') {
      $whereSql = "WHERE (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?)";
      $like = "%".$q."%";
      $types = 'ssss';
      $vals = [$like,$like,$like,$like];
    }
    $sql = "SELECT full_name, phone, email, address FROM clients ".$whereSql." ORDER BY full_name ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($types) {
      $bindTypes = $types.'ii';
      $stmt->bind_param($bindTypes, ...array_merge($vals, [$limit, $offset]));
    } else {
      $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=clients.xls');
    echo "<html><head><meta charset='utf-8'><title>Clients</title></head><body>";
    echo "<table border='1'><thead><tr><th>Full Name</th><th>Phone</th><th>Email</th><th>Address</th></tr></thead><tbody>";
    while ($r = $res->fetch_assoc()) {
      echo "<tr><td>".htmlspecialchars($r['full_name'])."</td><td>".htmlspecialchars($r['phone'])."</td><td>".htmlspecialchars($r['email'])."</td><td>".htmlspecialchars($r['address'])."</td></tr>";
    }
    echo "</tbody></table></body></html>";
    $stmt->close();
    exit;
  }
  if ($action === 'print') {
    $q = trim($_GET['q'] ?? '');
    $limit = (int)($_GET['limit'] ?? 25);
    $page = (int)($_GET['page'] ?? 1);
    if (!in_array($limit, [10,25,50,100], true)) { $limit = 25; }
    if ($page < 1) { $page = 1; }
    $offset = ($page - 1) * $limit;
    $whereSql = '';
    $types = '';
    $vals = [];
    if ($q !== '') {
      $whereSql = "WHERE (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?)";
      $like = "%".$q."%";
      $types = 'ssss';
      $vals = [$like,$like,$like,$like];
    }
    $countSql = "SELECT COUNT(*) AS c FROM clients ".$whereSql;
    $countStmt = $conn->prepare($countSql);
    if ($types) { $countStmt->bind_param($types, ...$vals); }
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $totalClients = (int)($countRes->fetch_assoc()['c'] ?? 0);
    $countStmt->close();
    if ($offset >= $totalClients && $totalClients > 0) {
      $page = (int)ceil($totalClients / $limit);
      $offset = ($page - 1) * $limit;
    }
    $sql = "SELECT full_name, phone, email, address FROM clients ".$whereSql." ORDER BY full_name ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($types) {
      $bindTypes = $types.'ii';
      $stmt->bind_param($bindTypes, ...array_merge($vals, [$limit, $offset]));
    } else {
      $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    echo "<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Clients</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'><style>@media print {.btn{display:none}}</style></head><body>";
    echo "<div class='container my-4'><div class='d-flex justify-content-between align-items-center'><h4 class='mb-3'>Clients</h4><button class='btn btn-primary' onclick='window.print()'>Print</button></div>";
    echo "<table class='table table-bordered table-sm'><thead><tr><th>Full Name</th><th>Phone</th><th>Email</th><th>Address</th></tr></thead><tbody>";
    while ($r = $res->fetch_assoc()) {
      echo "<tr><td>".htmlspecialchars($r['full_name'])."</td><td>".htmlspecialchars($r['phone'])."</td><td>".htmlspecialchars($r['email'])."</td><td>".htmlspecialchars($r['address'])."</td></tr>";
    }
    echo "</tbody></table></div></body></html>";
    $stmt->close();
    exit;
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $conn->begin_transaction();
    $stmt = $conn->prepare("INSERT INTO clients (full_name, phone, email, address) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $full_name, $phone, $email, $address);
    $message = $stmt->execute() ? 'Client added' : 'Error adding client';
    if ($message === 'Client added') {
      log_action($conn, 'client_create', $full_name, [
        'entity_id' => (string)$stmt->insert_id,
        'new_values' => ['full_name'=>$full_name,'phone'=>$phone,'email'=>$email,'address'=>$address]
      ]);
      $conn->commit();
    } else { $conn->rollback(); }
    $stmt->close();
  }
  if ($action === 'update') {
    $id = (int)$_POST['id'];
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $beforeStmt = $conn->prepare("SELECT full_name, phone, email, address FROM clients WHERE id=? LIMIT 1");
    $beforeStmt->bind_param('i', $id);
    $beforeStmt->execute();
    $beforeResult = $beforeStmt->get_result();
    $beforeClient = $beforeResult->fetch_assoc() ?: null;
    $beforeStmt->close();
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE clients SET full_name=?, phone=?, email=?, address=? WHERE id=?");
    $stmt->bind_param('ssssi', $full_name, $phone, $email, $address, $id);
    $message = $stmt->execute() ? 'Client updated' : 'Error updating client';
    if ($message === 'Client updated' && $beforeClient) {
      log_action($conn, 'client_update', (string)$id, [
        'entity_id' => (string)$id,
        'old_values' => $beforeClient,
        'new_values' => ['full_name'=>$full_name,'phone'=>$phone,'email'=>$email,'address'=>$address]
      ]);
      $conn->commit();
    } else { $conn->rollback(); }
    $stmt->close();
  }
  if ($action === 'delete') {
    $id = (int)$_POST['id'];
    $beforeStmt = $conn->prepare("SELECT full_name, phone, email, address FROM clients WHERE id=? LIMIT 1");
    $beforeStmt->bind_param('i', $id);
    $beforeStmt->execute();
    $beforeResult = $beforeStmt->get_result();
    $beforeClient = $beforeResult->fetch_assoc() ?: null;
    $beforeStmt->close();
    $conn->begin_transaction();
    $stmt = $conn->prepare("DELETE FROM clients WHERE id=?");
    $stmt->bind_param('i', $id);
    $message = $stmt->execute() ? 'Client deleted' : 'Error deleting client';
    if ($message === 'Client deleted' && $beforeClient) {
      log_action($conn, 'client_delete', (string)$id, ['entity_id'=>(string)$id,'old_values'=>$beforeClient]);
      $conn->commit();
    } else { $conn->rollback(); }
    $stmt->close();
  }
  if ($action === 'import_csv') {
    @set_time_limit(0);
    @ini_set('auto_detect_line_endings', '1');
    @ini_set('max_execution_time', '0');
    @ini_set('memory_limit', '512M');
    $inserted = 0;
    $updated = 0;
    if (isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
      $tmp = $_FILES['csv_file']['tmp_name'];
      $h = fopen($tmp, 'r');
      if ($h) {
        $conn->begin_transaction();
        $chkEmailStmt = $conn->prepare("SELECT id FROM clients WHERE email=? LIMIT 1");
        $chkPhoneStmt = $conn->prepare("SELECT id FROM clients WHERE phone=? LIMIT 1");
        $chkNameStmt  = $conn->prepare("SELECT id FROM clients WHERE full_name=? LIMIT 1");
        $updStmt = $conn->prepare("UPDATE clients SET full_name=COALESCE(NULLIF(?,''), full_name), phone=COALESCE(NULLIF(?,''), phone), email=COALESCE(NULLIF(?,''), email), address=COALESCE(NULLIF(?,''), address) WHERE id=?");
        $insStmt = $conn->prepare("INSERT INTO clients (full_name, phone, email, address) VALUES (?,?,?,?)");
        $first = fgets($h);
        $delim = ',';
        $cComma = substr_count((string)$first, ',');
        $cSemi = substr_count((string)$first, ';');
        $cTab = substr_count((string)$first, "\t");
        if ($cSemi > $cComma && $cSemi >= $cTab) { $delim = ';'; }
        if ($cTab > $cComma && $cTab > $cSemi) { $delim = "\t"; }
        rewind($h);
        $header = fgetcsv($h, 0, $delim);
        $map = array_map(fn($x)=>strtolower(trim($x)), $header ?: []);
        $findIndex = function(array $opts) use ($map) {
          foreach ($opts as $o) {
            $i = array_search(strtolower($o), $map, true);
            if ($i !== false) return (int)$i;
          }
          return -1;
        };
        $nameIdx = $findIndex(['Name','Full Name']);
        $givenIdx = $findIndex(['Given Name','First Name']);
        $familyIdx = $findIndex(['Family Name','Last Name']);
        $phoneIdx = $findIndex(['Phone 1 - Value','Phone','Primary Phone']);
        $emailIdx = $findIndex(['E-mail 1 - Value','Email','Primary Email']);
        $addrIdx = $findIndex(['Address 1 - Formatted','Address','Primary Address']);
        while (($row = fgetcsv($h, 0, $delim)) !== false) {
          $full_name = '';
          if ($nameIdx >= 0) { $full_name = trim((string)($row[$nameIdx] ?? '')); }
          else {
            $full_name = trim(((string)($row[$givenIdx] ?? '')).' '.((string)($row[$familyIdx] ?? '')));
          }
          $phone = $phoneIdx >= 0 ? trim((string)($row[$phoneIdx] ?? '')) : '';
          $email = $emailIdx >= 0 ? trim((string)($row[$emailIdx] ?? '')) : '';
          $address = $addrIdx >= 0 ? trim((string)($row[$addrIdx] ?? '')) : '';
          if ($full_name === '' && $email === '' && $phone === '') { continue; }
          $existingId = 0;
          if ($email !== '') {
            $chkEmailStmt->bind_param('s', $email);
            $chkEmailStmt->execute();
            $res = $chkEmailStmt->get_result();
            if ($r = $res->fetch_assoc()) { $existingId = (int)$r['id']; }
          }
          if ($existingId <= 0 && $phone !== '') {
            $chkPhoneStmt->bind_param('s', $phone);
            $chkPhoneStmt->execute();
            $res = $chkPhoneStmt->get_result();
            if ($r = $res->fetch_assoc()) { $existingId = (int)$r['id']; }
          }
          if ($existingId <= 0 && $full_name !== '') {
            $chkNameStmt->bind_param('s', $full_name);
            $chkNameStmt->execute();
            $res = $chkNameStmt->get_result();
            if ($r = $res->fetch_assoc()) { $existingId = (int)$r['id']; }
          }
          if ($existingId > 0) {
            $updStmt->bind_param('ssssi', $full_name, $phone, $email, $address, $existingId);
            $updStmt->execute();
            $updated++;
          } else {
            $insStmt->bind_param('ssss', $full_name, $phone, $email, $address);
            if ($insStmt->execute()) { $inserted++; }
          }
        }
        fclose($h);
        $chkEmailStmt->close();
        $chkPhoneStmt->close();
        $chkNameStmt->close();
        $updStmt->close();
        $insStmt->close();
        $message = "Imported: ".$inserted.", Updated: ".$updated;
        log_action($conn, 'clients_import_csv', (string)$inserted.'|'.(string)$updated, [
          'metadata' => ['inserted'=>$inserted,'updated'=>$updated]
        ]);
        $conn->commit();
        header('Location: clients.php?msg='.urlencode($message));
        exit;
      } else {
        $message = 'Unable to read CSV';
      }
    } else {
      $message = 'Upload a CSV file';
    }
  }
  if ($action === 'send_sms') {
    $id = (int)($_POST['id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $text = trim($_POST['text'] ?? '');
    if ($phone === '' && $id > 0) {
      $row = $conn->query("SELECT phone FROM clients WHERE id=".$id." LIMIT 1")->fetch_assoc();
      $phone = trim($row['phone'] ?? '');
    }
    if ($phone === '' || $text === '') {
      $message = 'Enter phone and message';
    } else {
      $res = sms_send($conn, $phone, $text, $id > 0 ? $id : null);
      $message = $res['success'] ? 'SMS sent' : ('SMS failed: '.$res['error']);
      log_action($conn, 'sms_send', (string)$id.'|'.$phone);
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
  $whereSql = "WHERE (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?)";
  $like = "%".$q."%";
  $types = 'ssss';
  $vals = [$like,$like,$like,$like];
}
$countSql = "SELECT COUNT(*) AS c FROM clients ".$whereSql;
$countStmt = $conn->prepare($countSql);
if ($types) { $countStmt->bind_param($types, ...$vals); }
$countStmt->execute();
$countRes = $countStmt->get_result();
$totalClients = (int)($countRes->fetch_assoc()['c'] ?? 0);
$countStmt->close();
if ($offset >= $totalClients && $totalClients > 0) {
  $page = (int)ceil($totalClients / $limit);
  $offset = ($page - 1) * $limit;
}
$listSql = "SELECT id, full_name, phone, email, address, created_at FROM clients ".$whereSql." ORDER BY full_name ASC LIMIT ? OFFSET ?";
$listStmt = $conn->prepare($listSql);
if ($types) {
  $bindTypes = $types.'ii';
  $listStmt->bind_param($bindTypes, ...array_merge($vals, [$limit, $offset]));
} else {
  $listStmt->bind_param('ii', $limit, $offset);
}
$listStmt->execute();
$clients = $listStmt->get_result();
$pagesTotal = $limit > 0 ? (int)ceil($totalClients / $limit) : 1;
$hasInteractions = $conn->query("SHOW TABLES LIKE 'client_interactions'")->num_rows > 0;
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
$payload = [
  'query' => $q,
  'limit' => $limit,
  'page' => $page,
  'total' => $totalClients,
  'items' => []
];
if (isset($_GET['format']) && $_GET['format'] === 'json') {
  while ($row = $clients->fetch_assoc()) {
    $payload['items'][] = [
      'id' => (int)$row['id'],
      'full_name' => (string)$row['full_name'],
      'phone' => (string)($row['phone'] ?? ''),
      'email' => (string)($row['email'] ?? ''),
      'address' => (string)($row['address'] ?? ''),
      'created_at' => (string)($row['created_at'] ?? '')
    ];
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Clients — LMG Creative Media</title>
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
  <?php $active_page='clients'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Clients'; $page_subtitle='Manage client records'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="addClient" class="card p-3">
          <h6 class="mb-3">Add Client</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-12"><input class="form-control" name="full_name" placeholder="Full name" required></div>
            <div class="col-md-6"><input class="form-control" name="phone" placeholder="Phone"></div>
            <div class="col-md-6"><input class="form-control" name="email" placeholder="Email"></div>
            <div class="col-12"><textarea class="form-control" name="address" placeholder="Address"></textarea></div>
            <div class="col-12"><button class="btn btn-black w-100">Save</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="listClients" class="card p-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex align-items-center gap-3">
              <h6 class="mb-0">All Clients</h6>
              <div class="text-muted small">Total: <?= (int)$totalClients ?><?php if($totalClients>0): ?> · Showing <?= (int)($offset+1) ?>–<?= (int)min($offset+$limit,$totalClients) ?><?php endif; ?></div>
            </div>
            <form method="GET" class="d-flex align-items-center gap-2">
              <input type="text" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, phone, email, address" style="min-width:280px">
              <select class="form-select form-select-sm" name="limit" style="width:auto">
                <option value="10"<?= $limit===10?' selected':'' ?>>10</option>
                <option value="25"<?= $limit===25?' selected':'' ?>>25</option>
                <option value="50"<?= $limit===50?' selected':'' ?>>50</option>
                <option value="100"<?= $limit===100?' selected':'' ?>>100</option>
              </select>
              <button class="btn btn-sm btn-outline-light">Search</button>
              <a class="btn btn-sm btn-outline-light" href="clients.php">Reset</a>
            </form>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#importCsvModal"><i class="bi bi-upload"></i> Import CSV</button>
              <a class="btn btn-sm btn-outline-light" href="clients.php?action=export_csv&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>&page=<?= (int)$page ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
              <a class="btn btn-sm btn-outline-light" href="clients.php?action=export_excel&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>&page=<?= (int)$page ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
              <a class="btn btn-sm btn-outline-light" href="clients.php?action=print&q=<?= urlencode($q) ?>&limit=<?= (int)$limit ?>&page=<?= (int)$page ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable">
              <thead>
                <tr class="text-muted small">
                  <th>Name</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th>Address</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($c = $clients->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($c['full_name']) ?></td>
                  <td><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                  <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($c['address'] ?? '') ?></td>
                  <td>
                    <details>
                      <summary class="btn btn-sm btn-outline-light">Edit</summary>
                      <form method="POST" class="mt-2">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input class="form-control mb-1" name="full_name" value="<?= htmlspecialchars($c['full_name']) ?>">
                        <input class="form-control mb-1" name="phone" value="<?= htmlspecialchars($c['phone'] ?? '') ?>">
                        <input class="form-control mb-1" name="email" value="<?= htmlspecialchars($c['email'] ?? '') ?>">
                        <textarea class="form-control mb-1" name="address"><?= htmlspecialchars($c['address'] ?? '') ?></textarea>
                        <div class="d-flex gap-2">
                          <button class="btn btn-sm btn-primary">Save</button>
                          <button formaction="clients.php" name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Delete client?')">Delete</button>
                        </div>
                      </form>
                      <hr>
                      <form method="POST" class="mt-2">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="send_sms">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <div class="mb-1"><input class="form-control" name="phone" value="<?= htmlspecialchars($c['phone'] ?? '') ?>" placeholder="Phone"></div>
                        <div class="mb-1"><textarea class="form-control" name="text" placeholder="Message">Hello <?= htmlspecialchars($c['full_name']) ?>, this is LMG Creative Media.</textarea></div>
                        <button class="btn btn-sm btn-outline-primary">Send SMS</button>
                      </form>
                      <?php if ($hasInteractions): ?>
                      <hr>
                      <form method="POST" action="clients_interactions.php" class="mt-2 d-flex gap-2">
                        <?= csrf_input() ?>
                        <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                        <input class="form-control" name="note" placeholder="Add interaction note">
                        <button class="btn btn-sm btn-outline-primary">Add Note</button>
                      </form>
                      <div class="mt-2">
                        <?php
                          $iid = (int)$c['id'];
                          $notes = $conn->query("SELECT note, created_at FROM client_interactions WHERE client_id=".$iid." ORDER BY id DESC LIMIT 3");
                          while ($n = $notes->fetch_assoc()):
                        ?>
                          <div class="small text-muted">• <?= htmlspecialchars($n['note']) ?> <span class="text-secondary"><?= htmlspecialchars($n['created_at']) ?></span></div>
                        <?php endwhile; ?>
                      </div>
                      <?php endif; ?>
                    </details>
                  </td>
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
                  $base = 'clients.php?'.http_build_query(['q'=>$q,'limit'=>$limit]);
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

    <div class="modal fade" id="importCsvModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content p-3">
          <h6 class="mb-3">Import Clients CSV</h6>
          <form method="POST" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="action" value="import_csv">
            <div class="col-12">
              <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
            </div>
            <div class="col-12">
              <button class="btn btn-primary">Import</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
