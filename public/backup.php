<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
$role = $_SESSION['role'] ?? 'staff';
if ($role !== 'admin') {
  header('Location: dashboard.php');
  exit;
}

$tables = [
  'users' => ['id','full_name','username','role','status','created_at'],
  'clients' => ['id','full_name','phone','email','address','created_at'],
  'transactions' => ['id','client_id','amount','type','payment_method','description','date','created_at'],
  'invoices' => ['id','invoice_number','client_id','invoice_date','due_date','subtotal','tax','total','status','notes','created_at'],
  'invoice_items' => ['id','invoice_id','item_name','quantity','price','total'],
  'products' => ['id','name','sku','price','stock','min_stock','created_at'],
  'client_interactions' => ['id','client_id','note','created_at'],
  'user_logs' => ['id','user_id','action','details','created_at'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
  if (!csrf_verify()) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid request token';
    exit;
  }
  $table = $_POST['table'] ?? '';
  if (!isset($tables[$table])) {
    header('Location: backup.php');
    exit;
  }
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename='.$table.'_backup_'.date('Ymd_His').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, $tables[$table]);
  $cols = implode(',', $tables[$table]);
  $res = $conn->query("SELECT ".$cols." FROM ".$table);
  while($row = $res->fetch_assoc()) {
    $csvRow = [];
    foreach($tables[$table] as $col) { $csvRow[] = $row[$col]; }
    fputcsv($out, $csvRow);
  }
  fclose($out);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_all') {
  if (!csrf_verify()) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid request token';
    exit;
  }
  $zipName = 'backup_all_'.date('Ymd_His').'.zip';
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename='.$zipName);
  $zip = new ZipArchive();
  $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
  $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  foreach ($tables as $table => $cols) {
    $colsStr = implode(',', $cols);
    $res = $conn->query("SELECT ".$colsStr." FROM ".$table);
    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, $cols);
    while ($row = $res->fetch_assoc()) {
      $line = [];
      foreach ($cols as $c) { $line[] = $row[$c]; }
      fputcsv($csv, $line);
    }
    rewind($csv);
    $csvContent = stream_get_contents($csv);
    fclose($csv);
    $zip->addFromString($table.'.csv', $csvContent);
  }
  $zip->close();
  readfile($tmpFile);
  @unlink($tmpFile);
  exit;
}

$userName = htmlspecialchars($_SESSION['full_name']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Backup — LMG Creative Media</title>
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
  <?php $active_page='users'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Backup'; $page_subtitle='Export tables to CSV'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <div class="card p-3">
      <div class="row g-3">
        <?php foreach($tables as $t => $cols): ?>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100 d-flex flex-column">
            <div class="fw-bold mb-2"><?= htmlspecialchars(ucwords(str_replace('_',' ',$t))) ?></div>
            <div class="text-muted small mb-3"><?= htmlspecialchars(implode(', ', $cols)) ?></div>
            <form method="POST" class="mt-auto">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="export">
              <input type="hidden" name="table" value="<?= htmlspecialchars($t) ?>">
              <button class="btn btn-primary w-100"><i class="bi bi-cloud-download"></i> Export CSV</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="col-md-12">
          <div class="border rounded p-3 d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-bold mb-1">Full Backup</div>
              <div class="text-muted small">Download all tables as CSV inside a single ZIP</div>
            </div>
            <form method="POST" class="d-flex">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="export_all">
              <button class="btn btn-outline-light"><i class="bi bi-archive"></i> Export All (ZIP)</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>
<script src="js/ui.js"></script>
</body>
</html>
