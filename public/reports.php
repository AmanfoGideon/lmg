<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";

$message = '';
$reportError = '';
$group = $_GET['group'] ?? 'daily';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');
$allowedGroups = ['daily','weekly','monthly'];
if (!in_array($group, $allowedGroups)) $group = 'daily';
$hasBranch = $conn->query("SHOW COLUMNS FROM transactions LIKE 'branch'")->num_rows > 0;
$branch = $hasBranch ? ($_GET['branch'] ?? '') : '';
$branches = [];
if ($hasBranch) {
  $resB = $conn->query("SELECT DISTINCT branch FROM transactions WHERE branch IS NOT NULL AND branch<>'' ORDER BY branch ASC");
  while ($b = $resB->fetch_assoc()) { $branches[] = $b['branch']; }
}

if (isset($_POST['action']) && $_POST['action']==='export_csv') {
  $group = $_POST['group'] ?? 'daily';
  $start = $_POST['start'] ?? date('Y-m-01');
  $end = $_POST['end'] ?? date('Y-m-t');
  if (!in_array($group, $allowedGroups)) $group = 'daily';
  $rows = [];
  $sql = "SELECT type, amount, date FROM transactions WHERE date BETWEEN ? AND ?".($hasBranch && $branch!=='' ? " AND branch=?" : "")." ORDER BY date ASC";
  $stmt = $conn->prepare($sql);
  if ($hasBranch && $branch!=='') { $stmt->bind_param('sss', $start, $end, $branch); } else { $stmt->bind_param('ss', $start, $end); }
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()) {
    $key = $r['date'];
    if ($group==='weekly') $key = date('o-\WW', strtotime($r['date']));
    if ($group==='monthly') $key = date('Y-m', strtotime($r['date']));
    if (!isset($rows[$key])) $rows[$key] = ['income'=>0,'expense'=>0,'count'=>0];
    if ($r['type']==='income' || $r['type']==='payment') $rows[$key]['income'] += (float)$r['amount'];
    if ($r['type']==='expense') $rows[$key]['expense'] += (float)$r['amount'];
    $rows[$key]['count']++;
  }
  audit_event($conn, [
    'action' => 'report.exported', 'module' => 'reports',
    'description' => 'Financial report exported to CSV.',
    'metadata' => ['group'=>$group,'start'=>$start,'end'=>$end,'branch_filter'=>$branch !== '']
  ]);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=reports_'.$group.'_'.date('Ymd').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Period','Income','Expense','Profit','Transactions']);
  foreach($rows as $period=>$vals) {
    $profit = $vals['income'] - $vals['expense'];
    fputcsv($out, [$period, number_format($vals['income'],2,'.',''), number_format($vals['expense'],2,'.',''), number_format($profit,2,'.',''), $vals['count']]);
  }
  fclose($out);
  exit;
}

$rows = [];
try {
  $sql = "SELECT type, amount, date FROM transactions WHERE date BETWEEN ? AND ?".($hasBranch && $branch!=='' ? " AND branch=?" : "")." ORDER BY date ASC";
  $stmt = $conn->prepare($sql);
  if ($hasBranch && $branch!=='') { $stmt->bind_param('sss', $start, $end, $branch); } else { $stmt->bind_param('ss', $start, $end); }
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()) {
    $key = $r['date'];
    if ($group==='weekly') $key = date('o-\WW', strtotime($r['date']));
    if ($group==='monthly') $key = date('Y-m', strtotime($r['date']));
    if (!isset($rows[$key])) $rows[$key] = ['income'=>0,'expense'=>0,'count'=>0];
    if ($r['type']==='income' || $r['type']==='payment') $rows[$key]['income'] += (float)$r['amount'];
    if ($r['type']==='expense') $rows[$key]['expense'] += (float)$r['amount'];
    $rows[$key]['count']++;
  }
} catch (Throwable $error) {
  error_log('Financial report query failed: '.$error->getMessage());
  $reportError = 'Report data could not be loaded. Please try again later.';
}
$sorted = array_keys($rows);
sort($sorted);

$totalIncome = 0; $totalExpense = 0; $totalCount = 0;
foreach($rows as $v){ $totalIncome += $v['income']; $totalExpense += $v['expense']; $totalCount += $v['count']; }
$totalProfit = $totalIncome - $totalExpense;
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
$payload = [
  'group' => $group,
  'start' => $start,
  'end' => $end,
  'rows' => $rows,
  'sorted' => $sorted,
  'totals' => [
    'income' => $totalIncome,
    'expense' => $totalExpense,
    'profit' => $totalProfit,
    'count' => $totalCount
  ]
];
if (isset($_GET['format']) && $_GET['format'] === 'json') {
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
  <title>Reports — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="stylesheet" href="css/report-print.css">
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="../favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
  <link rel="apple-touch-icon" sizes="180x180" href="../favicon/apple-touch-icon.png">
</head>
<body class="report-page">
<div class="app-shell d-flex">
  <?php $active_page='dashboard'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Financial Reports'; $page_subtitle='Daily, weekly or monthly summaries'; $header_actions='<button class="btn btn-outline-light me-2 no-print" type="button" onclick="window.print()"'.($reportError !== '' ? ' disabled' : '').'><i class="bi bi-printer"></i> Print</button>'; include "../includes/layout/header.php"; ?>

    <div class="card p-3 mb-3 report-controls">
      <form method="GET" class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Group</label>
          <select class="form-select" name="group">
            <option value="daily" <?= $group==='daily'?'selected':'' ?>>Daily</option>
            <option value="weekly" <?= $group==='weekly'?'selected':'' ?>>Weekly</option>
            <option value="monthly" <?= $group==='monthly'?'selected':'' ?>>Monthly</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Start</label>
          <input class="form-control" type="date" name="start" value="<?= htmlspecialchars($start) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">End</label>
          <input class="form-control" type="date" name="end" value="<?= htmlspecialchars($end) ?>">
        </div>
        <?php if ($hasBranch): ?>
        <div class="col-md-3">
          <label class="form-label">Branch</label>
          <select class="form-select" name="branch">
            <option value="">All</option>
            <?php foreach($branches as $b): ?>
              <option value="<?= htmlspecialchars($b) ?>" <?= $branch===$b?'selected':'' ?>><?= htmlspecialchars($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
      </form>
    </div>

    <?php if ($reportError !== ''): ?><div class="alert alert-danger report-controls" role="alert"><?= htmlspecialchars($reportError) ?></div><?php endif; ?>
    <div id="reportPrintArea" class="report-print-area">
    <p class="report-print-period"><?= htmlspecialchars(ucfirst($group).' report · '.$start.' to '.$end.($branch !== '' ? ' · '.$branch : ' · All branches')) ?></p>
    <div class="row g-3">
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Total Income</div>
          <div class="stat-value"><?= fmtCurrency($totalIncome) ?></div>
        </div>
      </div>
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Total Expense</div>
          <div class="stat-value"><?= fmtCurrency($totalExpense) ?></div>
        </div>
      </div>
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Profit</div>
          <div class="stat-value"><?= fmtCurrency($totalProfit) ?></div>
        </div>
      </div>
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Transactions</div>
          <div class="stat-value"><?= (int)$totalCount ?></div>
        </div>
      </div>
    </div>

    <div class="card p-3 mt-3 report-table-card">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">Summary</h6>
        <form method="POST" class="no-print">
          <input type="hidden" name="action" value="export_csv">
          <input type="hidden" name="group" value="<?= htmlspecialchars($group) ?>">
          <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
          <input type="hidden" name="end" value="<?= htmlspecialchars($end) ?>">
          <button class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-borderless table-sm table-striped table-hover table-brand align-middle datatable">
          <thead>
            <tr class="text-muted small">
              <th>Period</th>
              <th>Income</th>
              <th>Expense</th>
              <th>Profit</th>
              <th>Transactions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$sorted): ?>
              <tr><td colspan="5" class="text-muted"><?= $reportError !== '' ? 'Report data is unavailable.' : 'No transactions in the selected period.' ?></td></tr>
            <?php endif; ?>
            <?php foreach($sorted as $period): ?>
              <?php $vals = $rows[$period]; $profit = $vals['income'] - $vals['expense']; ?>
              <tr>
                <td><?= htmlspecialchars($period) ?></td>
                <td><?= fmtCurrency($vals['income']) ?></td>
                <td><?= fmtCurrency($vals['expense']) ?></td>
                <td><?= fmtCurrency($profit) ?></td>
                <td><?= (int)$vals['count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>
<script>
  let reportTableBeforePrint = null;
  window.addEventListener('beforeprint', function(){
    const table = document.querySelector('table.datatable');
    if (!table || !jQuery.fn.DataTable.isDataTable(table)) return;
    const api = jQuery(table).DataTable();
    reportTableBeforePrint = { length: api.page.len(), page: api.page() };
    api.page.len(-1).draw();
  });
  window.addEventListener('afterprint', function(){
    const table = document.querySelector('table.datatable');
    if (!reportTableBeforePrint || !table || !jQuery.fn.DataTable.isDataTable(table)) return;
    const api = jQuery(table).DataTable();
    const previous = reportTableBeforePrint;
    reportTableBeforePrint = null;
    api.page.len(previous.length).draw(false);
    if (previous.length !== -1) api.page(previous.page).draw('page');
  });
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
