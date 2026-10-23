<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/payment_methods.php";

function get_report_rows(mysqli $conn, string $group, string $start, string $end, bool $hasBranch, string $branch): array {
  $period = match ($group) {
    'weekly' => "DATE_FORMAT(date, '%x-W%v')",
    'monthly' => "DATE_FORMAT(date, '%Y-%m')",
    default => "DATE_FORMAT(date, '%Y-%m-%d')"
  };
  $sql = "SELECT $period AS period, COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount ELSE 0 END),0) AS income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense, COUNT(*) AS transactions FROM transactions WHERE date BETWEEN ? AND ?".($hasBranch && $branch !== '' ? " AND branch=?" : "")." GROUP BY period ORDER BY period";
  $stmt = $conn->prepare($sql);
  if ($hasBranch && $branch !== '') $stmt->bind_param('sss', $start, $end, $branch);
  else $stmt->bind_param('ss', $start, $end);
  $stmt->execute();
  $result = $stmt->get_result();
  $rows = [];
  while ($row = $result->fetch_assoc()) $rows[$row['period']] = ['income'=>(float)$row['income'], 'expense'=>(float)$row['expense'], 'count'=>(int)$row['transactions']];
  $stmt->close();
  return $rows;
}

function get_payment_method_summary(mysqli $conn, string $start, string $end, bool $hasBranch, string $branch): array {
  $sql = "SELECT COALESCE(NULLIF(TRIM(payment_method), ''), 'unspecified') AS payment_method, COUNT(CASE WHEN type IN ('income','payment') THEN 1 END) AS income_count, COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount ELSE 0 END),0) AS income_total, COUNT(CASE WHEN type='expense' THEN 1 END) AS expense_count, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense_total FROM transactions WHERE date BETWEEN ? AND ? AND type IN ('income','payment','expense')".($hasBranch && $branch !== '' ? " AND branch=?" : "")." GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), 'unspecified') ORDER BY payment_method";
  $stmt = $conn->prepare($sql);
  if ($hasBranch && $branch !== '') $stmt->bind_param('sss', $start, $end, $branch);
  else $stmt->bind_param('ss', $start, $end);
  $stmt->execute();
  $result = $stmt->get_result();
  $methods = [];
  $incomeTotal = 0.0;
  $expenseTotal = 0.0;
  while ($row = $result->fetch_assoc()) {
    $row['income_count'] = (int)$row['income_count']; $row['income_total'] = (float)$row['income_total'];
    $row['expense_count'] = (int)$row['expense_count']; $row['expense_total'] = (float)$row['expense_total'];
    $incomeTotal += $row['income_total']; $expenseTotal += $row['expense_total']; $methods[] = $row;
  }
  $stmt->close();
  $byMethod = [];
  foreach ($methods as $method) $byMethod[$method['payment_method']] = $method;
  foreach (['cash', 'mobile', 'card', 'unspecified'] as $knownMethod) {
    if (!isset($byMethod[$knownMethod])) $byMethod[$knownMethod] = ['payment_method'=>$knownMethod, 'income_count'=>0, 'income_total'=>0.0, 'expense_count'=>0, 'expense_total'=>0.0];
  }
  $methods = array_values($byMethod);
  usort($methods, static fn($a, $b) => strcmp($a['payment_method'], $b['payment_method']));
  foreach ($methods as &$method) {
    $method['income_percent'] = $incomeTotal > 0 ? round($method['income_total'] * 100 / $incomeTotal, 2) : 0;
    $method['expense_percent'] = $expenseTotal > 0 ? round($method['expense_total'] * 100 / $expenseTotal, 2) : 0;
  }
  unset($method);
  return ['rows'=>$methods, 'income_total'=>$incomeTotal, 'expense_total'=>$expenseTotal];
}

$message = '';
$reportError = '';
$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-t');
$validReportDate = static function ($value): bool {
  if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date !== false && $date->format('Y-m-d') === $value;
};
$group = is_string($_GET['group'] ?? null) ? $_GET['group'] : 'daily';
$requestedStart = $_GET['start'] ?? $defaultStart;
$requestedEnd = $_GET['end'] ?? $defaultEnd;
$start = $validReportDate($requestedStart) ? $requestedStart : $defaultStart;
$end = $validReportDate($requestedEnd) ? $requestedEnd : $defaultEnd;
$reportFiltersInvalid = !$validReportDate($requestedStart) || !$validReportDate($requestedEnd) || $start > $end;
if ($reportFiltersInvalid) $reportError = 'Choose a valid date range with the start date on or before the end date.';
$allowedGroups = ['daily','weekly','monthly'];
if (!in_array($group, $allowedGroups, true)) $group = 'daily';
$hasBranch = $conn->query("SHOW COLUMNS FROM transactions LIKE 'branch'")->num_rows > 0;
$branch = $hasBranch && is_string($_GET['branch'] ?? '') ? $_GET['branch'] : '';
$branches = [];
if ($hasBranch) {
  $resB = $conn->query("SELECT DISTINCT branch FROM transactions WHERE branch IS NOT NULL AND branch<>'' ORDER BY branch ASC");
  while ($b = $resB->fetch_assoc()) { $branches[] = $b['branch']; }
  if ($branch !== '' && !in_array($branch, $branches, true)) $branch = '';
}

if (isset($_POST['action']) && $_POST['action']==='export_csv') {
  if (!csrf_verify()) {
    http_response_code(403);
    exit('Invalid request token. Refresh the report and try again.');
  }
  $group = is_string($_POST['group'] ?? null) ? $_POST['group'] : 'daily';
  $postedStart = $_POST['start'] ?? $defaultStart;
  $postedEnd = $_POST['end'] ?? $defaultEnd;
  if (!$validReportDate($postedStart) || !$validReportDate($postedEnd) || $postedStart > $postedEnd) {
    http_response_code(422);
    exit('Choose a valid report date range.');
  }
  $start = $postedStart;
  $end = $postedEnd;
  $postedBranch = $_POST['branch'] ?? $branch;
  $branch = $hasBranch && is_string($postedBranch) ? trim($postedBranch) : '';
  if ($hasBranch && $branch !== '' && !in_array($branch, $branches, true)) $branch = '';
  if (!in_array($group, $allowedGroups, true)) $group = 'daily';
  $rows = get_report_rows($conn, $group, $start, $end, $hasBranch, $branch);
  $paymentMethods = get_payment_method_summary($conn, $start, $end, $hasBranch, $branch);
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
  fputcsv($out, []);
  fputcsv($out, ['Payment Method Summary']);
  fputcsv($out, ['Payment Method','Income Count','Income Total','Income %','Expense Count','Expense Total','Expense %']);
  foreach ($paymentMethods['rows'] as $method) {
    fputcsv($out, [payment_method_label($method['payment_method']), $method['income_count'], number_format($method['income_total'],2,'.',''), number_format($method['income_percent'],2,'.','').'%', $method['expense_count'], number_format($method['expense_total'],2,'.',''), number_format($method['expense_percent'],2,'.','').'%']);
  }
  fputcsv($out, ['Total', array_sum(array_column($paymentMethods['rows'],'income_count')), number_format($paymentMethods['income_total'],2,'.',''), $paymentMethods['income_total'] > 0 ? '100.00%' : '0.00%', array_sum(array_column($paymentMethods['rows'],'expense_count')), number_format($paymentMethods['expense_total'],2,'.',''), $paymentMethods['expense_total'] > 0 ? '100.00%' : '0.00%']);
  fclose($out);
  exit;
}

$rows = [];
$paymentMethods = ['rows'=>[], 'income_total'=>0, 'expense_total'=>0];
try {
  if ($reportFiltersInvalid) throw new InvalidArgumentException($reportError);
  $rows = get_report_rows($conn, $group, $start, $end, $hasBranch, $branch);
  $paymentMethods = get_payment_method_summary($conn, $start, $end, $hasBranch, $branch);
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
  ],
  'payment_methods' => $paymentMethods['rows']
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
    <?php $active_page='reports'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Financial Reports'; $page_subtitle='Daily, weekly or monthly summaries'; $header_actions='<a class="btn btn-outline-light me-2 no-print" href="supplier_reports.php">Supplier Reports</a><a class="btn btn-outline-light me-2 no-print" href="suppliers.php">Supplier Accounts</a><a class="btn btn-outline-light me-2 no-print" href="supplier_bills.php">Supplier Bills</a><button class="btn btn-outline-light me-2 no-print" type="button" onclick="window.print()"'.($reportError !== '' ? ' disabled' : '').'><i class="bi bi-printer"></i> Print</button>'; include "../includes/layout/header.php"; ?>

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

    <section class="mt-4" aria-labelledby="paymentMethodSummaryTitle">
      <h2 id="paymentMethodSummaryTitle" class="h5">Payment Method Summary</h2>
      <p class="text-muted small">Income includes recorded income and invoice payments. Expenses are shown separately to keep receipts and outflows clear.</p>
      <div class="row g-3 mb-3">
        <?php foreach ($paymentMethods['rows'] as $method): ?>
          <div class="col-xl-3 col-md-4 col-sm-6"><div class="stat-card p-3 h-100">
            <div class="stat-title"><?= htmlspecialchars(payment_method_label($method['payment_method'])) ?></div>
            <div class="stat-value fs-6 mt-2">Income · <?= fmtCurrency($method['income_total']) ?> (<?= (int)$method['income_count'] ?>)</div>
            <div class="small text-muted mt-1">Expenses · <?= fmtCurrency($method['expense_total']) ?> (<?= (int)$method['expense_count'] ?>)</div>
          </div></div>
        <?php endforeach; ?>
      </div>
      <div class="card p-3 report-table-card"><div class="table-responsive">
        <table class="table table-borderless table-sm table-striped table-hover table-brand align-middle mb-0">
          <thead><tr class="text-muted small"><th>Payment Method</th><th>Income Count</th><th>Income Total</th><th>Income %</th><th>Expense Count</th><th>Expense Total</th><th>Expense %</th></tr></thead>
          <tbody>
            <?php foreach ($paymentMethods['rows'] as $method): ?>
              <tr><td><?= htmlspecialchars(payment_method_label($method['payment_method'])) ?></td><td><?= (int)$method['income_count'] ?></td><td><?= fmtCurrency($method['income_total']) ?></td><td><?= number_format($method['income_percent'], 2) ?>%</td><td><?= (int)$method['expense_count'] ?></td><td><?= fmtCurrency($method['expense_total']) ?></td><td><?= number_format($method['expense_percent'], 2) ?>%</td></tr>
            <?php endforeach; ?>
            <tr class="fw-bold"><td>Total</td><td><?= array_sum(array_column($paymentMethods['rows'], 'income_count')) ?></td><td><?= fmtCurrency($paymentMethods['income_total']) ?></td><td><?= $paymentMethods['income_total'] > 0 ? '100.00%' : '0.00%' ?></td><td><?= array_sum(array_column($paymentMethods['rows'], 'expense_count')) ?></td><td><?= fmtCurrency($paymentMethods['expense_total']) ?></td><td><?= $paymentMethods['expense_total'] > 0 ? '100.00%' : '0.00%' ?></td></tr>
            <?php if (!$paymentMethods['rows']): ?><tr><td colspan="7" class="text-muted">No matching income or expense transactions.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div></div>
    </section>

    <div class="card p-3 mt-3 report-table-card">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">Summary</h6>
        <form method="POST" class="no-print">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="export_csv">
          <input type="hidden" name="group" value="<?= htmlspecialchars($group) ?>">
          <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
          <input type="hidden" name="end" value="<?= htmlspecialchars($end) ?>">
          <input type="hidden" name="branch" value="<?= htmlspecialchars($branch) ?>">
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
