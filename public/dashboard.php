<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/suppliers.php";
$period = isset($_GET['period']) ? strtolower(trim($_GET['period'])) : 'month';
if (!in_array($period, ['week','month','year'], true)) { $period = 'month'; }
if ($period === 'week') {
  $rangeStart = date('Y-m-d', strtotime('-6 days'));
  $rangeEnd = date('Y-m-d');
  $periodLabel = 'This week';
}
else if ($period === 'year') {
  $rangeStart = date('Y-01-01');
  $rangeEnd = date('Y-12-31');
  $periodLabel = 'This year';
}
else {
  $rangeStart = date('Y-m-01');
  $rangeEnd = date('Y-m-t');
  $periodLabel = 'This month';
}

$sqlMonth = $conn->prepare("SELECT
  COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount END),0) AS income_total,
  COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS expense_total,
  COUNT(*) AS total_transactions
  FROM transactions
  WHERE date BETWEEN ? AND ?");
$sqlMonth->bind_param('ss', $rangeStart, $rangeEnd);
$sqlMonth->execute();
$resMonth = $sqlMonth->get_result();
$rowMonth = $resMonth->fetch_assoc();
$monthIncome = (float)($rowMonth['income_total'] ?? 0.0);
$monthExpense = (float)($rowMonth['expense_total'] ?? 0.0);
$profit = $monthIncome - $monthExpense;
$pendingInvoices = 0;
if ($conn->query("SHOW COLUMNS FROM invoices LIKE 'status'")->num_rows > 0) {
  $pendingInvoices = (int)$conn->query("SELECT COUNT(*) AS c FROM invoices WHERE status <> 'paid' AND cancelled_at IS NULL")->fetch_assoc()['c'];
} else {
  $pendingInvoices = (int)$conn->query("SELECT COUNT(*) AS c FROM invoices WHERE cancelled_at IS NULL AND DATE_FORMAT(invoice_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch_assoc()['c'];
}
$recent = [];
$stmtRecent = $conn->prepare("SELECT t.type, t.amount, t.date, c.full_name AS client
              FROM transactions t
              LEFT JOIN clients c ON c.id = t.client_id
              WHERE t.date BETWEEN ? AND ?
              ORDER BY t.date DESC, t.id DESC
              LIMIT 10");
$stmtRecent->bind_param('ss', $rangeStart, $rangeEnd);
$stmtRecent->execute();
$resRecent = $stmtRecent->get_result();
while ($r = $resRecent->fetch_assoc()) { $recent[] = $r; }
$stmtRecent->close();
$topClients = [];
$stmtTop = $conn->prepare("SELECT c.full_name AS name, COALESCE(SUM(t.amount),0) AS total
           FROM clients c
           LEFT JOIN transactions t ON t.client_id = c.id AND t.type IN ('income','payment') AND t.date BETWEEN ? AND ?
           GROUP BY c.id
           ORDER BY total DESC
           LIMIT 5");
$stmtTop->bind_param('ss', $rangeStart, $rangeEnd);
$stmtTop->execute();
$resTop = $stmtTop->get_result();
while ($r = $resTop->fetch_assoc()) { $topClients[] = ['name'=>$r['name'], 'total'=>(float)$r['total']]; }
$stmtTop->close();
$sqlWeek = $conn->query("SELECT
  COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount END),0) AS weekly_income,
  COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS weekly_expense,
  COUNT(*) AS total_transactions
  FROM transactions
  WHERE date >= CURDATE() - INTERVAL 7 DAY");
$week = $sqlWeek->fetch_assoc();
$weekly = [
  'income' => (float)$week['weekly_income'],
  'expense' => (float)$week['weekly_expense'],
  'profit' => (float)$week['weekly_income'] - (float)$week['weekly_expense'],
  'count' => (int)$week['total_transactions'],
];
$weeklyTrend = [];
$weeklyLabels = [];
$resDaily = $conn->query("SELECT DATE(date) AS d, type, COALESCE(SUM(amount),0) AS total FROM transactions WHERE date >= CURDATE() - INTERVAL 6 DAY GROUP BY d, type ORDER BY d ASC");
$mapDaily = [];
if ($resDaily) {
  while ($row = $resDaily->fetch_assoc()) {
    $d = $row['d'];
    if (!isset($mapDaily[$d])) $mapDaily[$d] = ['income'=>0.0,'expense'=>0.0];
    if ($row['type'] === 'income' || $row['type'] === 'payment') $mapDaily[$d]['income'] += (float)$row['total'];
    if ($row['type'] === 'expense') $mapDaily[$d]['expense'] = (float)$row['total'];
  }
}
for ($i=6; $i>=0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} day"));
  $lbl = date('D', strtotime($d));
  $weeklyLabels[] = $lbl;
  $vals = $mapDaily[$d] ?? ['income'=>0.0,'expense'=>0.0];
  $weeklyTrend[] = (float)$vals['income'] - (float)$vals['expense'];
}
$weeklyIncomeSeries = [];
$weeklyExpenseSeries = [];
for ($i=6; $i>=0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} day"));
  $vals = $mapDaily[$d] ?? ['income'=>0.0,'expense'=>0.0];
  $weeklyIncomeSeries[] = (float)$vals['income'];
  $weeklyExpenseSeries[] = (float)$vals['expense'];
}
$labels = [];
$incomeSeries = [];
$expenseSeries = [];
if ($period === 'week') {
  $chartLabel = 'Last 7 days (daily)';
  $labels = $weeklyLabels;
  $incomeSeries = $weeklyIncomeSeries;
  $expenseSeries = $weeklyExpenseSeries;
} else {
  if ($period === 'year') {
    $chartLabel = 'This Year (Jan–Dec)';
    $year = date('Y');
    $chartStart = $year.'-01-01';
    $chartEnd = $year.'-12-31';
    $firstMonth = strtotime($chartStart);
  } else {
    $chartLabel = 'Last 12 months';
    $firstMonth = strtotime(date('Y-m-01').' -11 months');
    $chartStart = date('Y-m-01', $firstMonth);
    $chartEnd = date('Y-m-t');
  }

  $stmtChart = $conn->prepare("SELECT DATE_FORMAT(date, '%Y-%m') AS month_key,
      COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount ELSE 0 END),0) AS income_total,
      COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense_total
      FROM transactions
      WHERE date BETWEEN ? AND ?
      GROUP BY month_key");
  $stmtChart->bind_param('ss', $chartStart, $chartEnd);
  $stmtChart->execute();
  $chartRows = $stmtChart->get_result();
  $monthlyTotals = [];
  while ($chartRow = $chartRows->fetch_assoc()) {
    $monthlyTotals[$chartRow['month_key']] = [
      'income' => (float)$chartRow['income_total'],
      'expense' => (float)$chartRow['expense_total'],
    ];
  }
  $stmtChart->close();

  for ($i = 0; $i < 12; $i++) {
    $monthDate = strtotime("+{$i} months", $firstMonth);
    $monthKey = date('Y-m', $monthDate);
    $labels[] = date('M', $monthDate);
    $totals = $monthlyTotals[$monthKey] ?? ['income' => 0.0, 'expense' => 0.0];
    $incomeSeries[] = $totals['income'];
    $expenseSeries[] = $totals['expense'];
  }
}
$hasProducts = $conn->query("SHOW TABLES LIKE 'products'")->num_rows > 0;
$lowStockCount = 0;
$lowProducts = [];
if ($hasProducts) {
  $rowLS = $conn->query("SELECT COUNT(*) AS c FROM products WHERE min_stock > 0 AND stock <= min_stock")->fetch_assoc();
  $lowStockCount = (int)$rowLS['c'];
  $resLP = $conn->query("SELECT name, stock, min_stock FROM products WHERE min_stock > 0 AND stock <= min_stock ORDER BY stock ASC LIMIT 5");
  while($p = $resLP->fetch_assoc()) { $lowProducts[] = $p; }
}

// Supplier statistics
$hasSuppliers = $conn->query("SHOW TABLES LIKE 'suppliers'")->num_rows > 0;
$supplierStats = [];
if ($hasSuppliers) {
  $supplierStats = supplier_get_stats($conn);
}

$serverData = [
  'user' => [
    'full_name' => $_SESSION['full_name'],
    'role' => $_SESSION['role'] ?? 'staff',
  ],
  'month' => [
    'income' => $monthIncome,
    'expense' => $monthExpense,
    'profit' => $profit,
    'pending_invoices' => $pendingInvoices,
  ],
  'weekly' => $weekly,
  'weeklyTrend' => $weeklyTrend,
  'weeklyLabels' => $weeklyLabels,
  'monthlyChart' => [
    'labels' => $labels,
    'income' => $incomeSeries,
    'expense' => $expenseSeries,
  ],
  'recent' => $recent,
  'topClients' => $topClients,
  'inventory' => [
    'low_stock' => $lowStockCount
  ],
  'suppliers' => $supplierStats
];
$activities = [];
$hasLogs = $conn->query("SHOW TABLES LIKE 'user_logs'")->num_rows > 0;
if ($hasLogs) {
  $hasCreated = $conn->query("SHOW COLUMNS FROM user_logs LIKE 'created_at'")->num_rows > 0;
  $sqlAct = "SELECT l.action, l.details".($hasCreated?", l.created_at":"").", u.full_name AS user
             FROM user_logs l
             LEFT JOIN users u ON u.id = l.user_id
             ORDER BY ".($hasCreated?"l.created_at DESC":"l.id DESC")."
             LIMIT 12";
  $resAct = $conn->query($sqlAct);
  if ($resAct) {
    while ($a = $resAct->fetch_assoc()) {
      $label = (string)$a['action'];
      $details = (string)($a['details'] ?? '');
      $user = (string)($a['user'] ?? '');
      $time = $hasCreated ? (string)($a['created_at'] ?? '') : '';
      $badgeClass = 'bg-light text-dark';
      if ($label === 'sms_send' || $label === 'sms_bulk_send') $badgeClass = 'bg-info';
      else if ($label === 'sms_connectivity') $badgeClass = 'bg-secondary';
      else if ($label === 'invoice_create') $badgeClass = 'bg-primary';
      else if ($label === 'invoice_status') $badgeClass = 'bg-warning text-dark';
      $activities[] = [
        'type' => 'log',
        'label' => $label,
        'details' => $details !== '' ? $details : $user,
        'time' => $time,
        'badgeClass' => $badgeClass,
        'ts' => $time !== '' ? strtotime($time) : 0
      ];
    }
  }
}
$hasSMS = $conn->query("SHOW TABLES LIKE 'sms_messages'")->num_rows > 0;
if ($hasSMS) {
  $hasSMSCreated = $conn->query("SHOW COLUMNS FROM sms_messages LIKE 'created_at'")->num_rows > 0;
  $sqlSMS = "SELECT phone, status, provider, provider_message_id".($hasSMSCreated?", created_at":"")." FROM sms_messages ORDER BY ".($hasSMSCreated?"created_at DESC":"id DESC")." LIMIT 12";
  $resSMS = $conn->query($sqlSMS);
  if ($resSMS) {
    while ($s = $resSMS->fetch_assoc()) {
      $st = (string)$s['status'];
      $badgeClass = ($st==='sent'?'bg-success':($st==='failed'?'bg-danger':'bg-secondary'));
      $t = $hasSMSCreated ? (string)($s['created_at'] ?? '') : '';
      $activities[] = [
        'type' => 'sms',
        'label' => 'SMS '.$st,
        'details' => (string)$s['phone'],
        'time' => $t,
        'badgeClass' => $badgeClass,
        'ts' => $t !== '' ? strtotime($t) : 0
      ];
    }
  }
}
$hasInv = $conn->query("SHOW TABLES LIKE 'invoices'")->num_rows > 0;
if ($hasInv) {
  $sqlInv = "SELECT i.invoice_number, i.status, i.invoice_date, c.full_name AS client FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.cancelled_at IS NULL ORDER BY i.invoice_date DESC, i.id DESC LIMIT 12";
  $resInv = $conn->query($sqlInv);
  if ($resInv) {
    while ($i = $resInv->fetch_assoc()) {
      $st = (string)$i['status'];
      $badgeClass = ($st==='paid'?'bg-success':($st==='partial'?'bg-warning text-dark':'bg-secondary'));
      $time = (string)$i['invoice_date'];
      $activities[] = [
        'type' => 'invoice',
        'label' => 'Invoice '.$st.' '.$i['invoice_number'],
        'details' => (string)($i['client'] ?? ''),
        'time' => $time,
        'badgeClass' => $badgeClass,
        'ts' => $time !== '' ? strtotime($time) : 0
      ];
    }
  }
}
usort($activities, function($a,$b){ return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0); });
$serverData['recentActivities'] = array_slice($activities, 0, 15);
if (isset($_GET['format']) && $_GET['format'] === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($serverData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard — LMG Creative Media</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <!-- Custom Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="manifest" href="pwa-manifest.webmanifest">
  <meta name="theme-color" content="#0f1720">
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="../favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
  <link rel="apple-touch-icon" sizes="180x180" href="../favicon/apple-touch-icon.png">
</head>

<body>

<div class="app-shell d-flex">

  <!-- SIDEBAR -->
  <?php $active_page='dashboard'; include "../includes/layout/sidebar.php"; ?>

  <!-- MAIN -->
  <main class="main flex-grow-1 p-4">

    <!-- Header -->
    <?php $page_title='Dashboard'; $page_subtitle='Overview of business performance'; $header_actions='<a href="invoice_create.php" class="btn btn-black me-2"><i class="bi bi-plus-square"></i> New Invoice</a><a href="transactions.php" class="btn btn-black me-2"><i class="bi bi-wallet2"></i> Add Transaction</a><a href="products.php" class="btn btn-black me-2"><i class="bi bi-box-seam"></i> Add Product</a><a href="clients.php" class="btn btn-black me-2"><i class="bi bi-person-plus"></i> Add Client</a><form method="GET" class="d-inline-block ms-2"><select class="form-select form-select-sm" name="period" onchange="this.form.submit()"><option value="week"'.($period==='week'?' selected':'').'>Week</option><option value="month"'.($period==='month'?' selected':'').'>Month</option><option value="year"'.($period==='year'?' selected':'').'>Year</option></select></form>'; include "../includes/layout/header.php"; ?>

    <!-- Stats Row -->
    <div class="row g-3">
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Total Income</div>
          <div id="statIncome" class="stat-value"><?= fmtCurrency(0) ?></div>
          <div class="stat-sub"><?= htmlspecialchars($periodLabel) ?></div>
        </div>
      </div>

      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Total Expenses</div>
          <div id="statExpenses" class="stat-value"><?= fmtCurrency(0) ?></div>
          <div class="stat-sub"><?= htmlspecialchars($periodLabel) ?></div>
        </div>
      </div>

      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Profit</div>
          <div id="statProfit" class="stat-value"><?= fmtCurrency(0) ?></div>
          <div class="stat-sub"><?= htmlspecialchars($periodLabel) ?></div>
        </div>
      </div>

      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Pending Invoices</div>
          <div id="statInvoices" class="stat-value">0</div>
          <div class="stat-sub">Unpaid</div>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Low Stock</div>
          <div class="stat-value"><?= (int)$lowStockCount ?></div>
          <div class="stat-sub">Needs restocking</div>
        </div>
      </div>
    </div>

    <?php if ($hasSuppliers): ?>
    <!-- Supplier Overview -->
    <div class="row g-3 mt-2">
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Active Suppliers</div>
          <div class="stat-value"><?= (int)($supplierStats['active_suppliers'] ?? 0) ?></div>
          <div class="stat-sub">Total: <?= (int)($supplierStats['total_suppliers'] ?? 0) ?></div>
        </div>
      </div>
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Supplier Purchases</div>
          <div class="stat-value"><?= fmtCurrency($supplierStats['total_purchases'] ?? 0) ?></div>
          <div class="stat-sub">Total bills</div>
        </div>
      </div>
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Supplier Payments</div>
          <div class="stat-value"><?= fmtCurrency($supplierStats['total_payments'] ?? 0) ?></div>
          <div class="stat-sub">Total paid</div>
        </div>
      </div>
      <div class="col-lg-3 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Outstanding Payables</div>
          <div class="stat-value text-<?= ($supplierStats['total_outstanding'] ?? 0) > 0 ? 'danger' : 'success' ?>"><?= fmtCurrency($supplierStats['total_outstanding'] ?? 0) ?></div>
          <div class="stat-sub">Overdue: <?= fmtCurrency($supplierStats['total_overdue'] ?? 0) ?></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Weekly Overview -->
    <div class="row g-3 mt-2">
      <div class="col-lg-4 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Weekly Income</div>
          <div id="weekIncome" class="stat-value"><?= fmtCurrency(0) ?></div>
          <div class="stat-sub">Last 7 days</div>
        </div>
      </div>
      <div class="col-lg-4 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Weekly Expenses</div>
          <div id="weekExpense" class="stat-value"><?= fmtCurrency(0) ?></div>
          <div class="stat-sub">Last 7 days</div>
        </div>
      </div>
      <div class="col-lg-4 col-sm-6">
        <div class="stat-card p-3">
          <div class="stat-title">Weekly Profit</div>
          <div id="weekProfit" class="stat-value"><?= fmtCurrency(0) ?></div>
          <div class="stat-sub">Last 7 days</div>
        </div>
      </div>
    </div>

    <!-- Charts + Recent -->
    <div class="row g-3 mt-3">
      <div class="col-lg-8">
        <div id="monthlyChartSection" class="card p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0">Monthly Revenue</h5>
            <small class="text-muted"><?= htmlspecialchars($chartLabel) ?></small>
          </div>
          <canvas id="monthlyChart" height="120"></canvas>
        </div>

        <div class="card p-3 mt-3">
          <h6 class="mb-3">Recent Transactions</h6>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable" id="transactionsTable">
              <thead>
                <tr class="text-muted small">
                  <th>Client</th>
                  <th>Type</th>
                  <th>Amount</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <!-- JS Loads Data -->
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card p-3 mb-3">
          <h6 class="mb-3">Notifications</h6>
          <ul class="list-unstyled mb-0 small">
            <li>Pending invoices: <span class="fw-bold"><?= (int)$pendingInvoices ?></span></li>
            <?php if ($lowStockCount > 0): ?>
              <li class="mt-2">Low stock:</li>
              <?php foreach($lowProducts as $lp): ?>
                <li class="text-muted">• <?= htmlspecialchars($lp['name']) ?> — <?= (int)$lp['stock'] ?>/<?= (int)$lp['min_stock'] ?></li>
              <?php endforeach; ?>
            <?php else: ?>
              <li class="text-muted">No low stock items</li>
            <?php endif; ?>
          </ul>
        </div>
        <div class="card p-3 mb-3">
          <h6 class="mb-3">Top Clients</h6>
          <ul id="clientsList" class="list-unstyled mb-0">
            <!-- JS Loads Data -->
          </ul>
        </div>
        <?php if ($hasSuppliers && !empty($supplierStats['recent_bills'])): ?>
        <div class="card p-3 mb-3">
          <h6 class="mb-3">Recent Supplier Bills</h6>
          <ul class="list-unstyled mb-0 small">
            <?php foreach($supplierStats['recent_bills'] as $bill): ?>
              <li class="mb-2">
                <div class="d-flex justify-content-between">
                  <span><?= htmlspecialchars($bill['business_name']) ?></span>
                  <span class="fw-bold"><?= fmtCurrency((float)$bill['total']) ?></span>
                </div>
                <div class="text-muted small">
                  <?= htmlspecialchars($bill['bill_number']) ?> • <?= htmlspecialchars($bill['bill_date']) ?>
                  <span class="badge bg-<?= $bill['status'] === 'paid' ? 'success' : ($bill['status'] === 'partial' ? 'warning text-dark' : ($bill['status'] === 'overdue' ? 'danger' : 'secondary')) ?> ms-1"><?= htmlspecialchars($bill['status']) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div class="card p-3">
          <h6 class="mb-2">Weekly Trend</h6>
          <canvas id="sparkline" height="80"></canvas>
        </div>
        <div class="card p-3 mt-3">
          <h6 class="mb-3">Recent Activities</h6>
          <ul id="activitiesList" class="list-unstyled mb-0 small"></ul>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <footer class="mt-4 text-center text-muted small">
      © 2025 LMG Creative Media — Built with ❤️
    </footer>

  </main>
</div>
<script src="js/ui.js"></script>

  <!-- Dashboard JS -->
  <script>
    window.serverData = <?php echo json_encode($serverData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK); ?>;
  </script>
<script src="js/dashboard.js"></script>
</body>
</html>
