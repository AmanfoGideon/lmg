<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/payment_methods.php";
require_once "../includes/invoice.php";

$page_title = 'Payment Method Summary';
$page_subtitle = 'Analyze payment distribution and transaction volume by payment method';
$active_page = 'reports';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="payment_method_summary_'.date('Ymd').'.csv"');
  
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Payment Method', 'Transactions', 'Income', 'Expenses', 'Total Amount', '% of Total']);
  
  foreach ($stats as $method => $data) {
    $percentage = ($overall['total_income'] + $overall['total_expense']) > 0 
      ? number_format(($data['total_amount'] / ($overall['total_income'] + $overall['total_expense'])) * 100, 1) . '%' 
      : '0%';
    fputcsv($out, [
      payment_method_label($method),
      $data['transaction_count'],
      number_format($data['income_total'], 2),
      number_format($data['expense_total'], 2),
      number_format($data['total_amount'], 2),
      $percentage
    ]);
  }
  
  fputcsv($out, [
    'Total',
    $overall['total_transactions'],
    number_format($overall['total_income'], 2),
    number_format($overall['total_expense'], 2),
    number_format($overall['total_income'] + $overall['total_expense'], 2),
    '100%'
  ]);
  
  fclose($out);
  exit;
}

// Get date range
$period = isset($_GET['period']) ? strtolower(trim($_GET['period'])) : 'month';
if (!in_array($period, ['week','month','year','custom'], true)) { $period = 'month'; }

$customStart = isset($_GET['start']) ? trim($_GET['start']) : '';
$customEnd = isset($_GET['end']) ? trim($_GET['end']) : '';

if ($period === 'week') {
  $rangeStart = date('Y-m-d', strtotime('-6 days'));
  $rangeEnd = date('Y-m-d');
  $periodLabel = 'This week';
} elseif ($period === 'year') {
  $rangeStart = date('Y-01-01');
  $rangeEnd = date('Y-12-31');
  $periodLabel = 'This year';
} elseif ($period === 'custom' && $customStart && $customEnd) {
  $rangeStart = $customStart;
  $rangeEnd = $customEnd;
  $periodLabel = "$customStart to $customEnd";
} else {
  $rangeStart = date('Y-m-01');
  $rangeEnd = date('Y-m-t');
  $periodLabel = 'This month';
}

// Validate dates
$validDate = function($date) {
  if (!$date) return false;
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
};

if ($period === 'custom' && (!$validDate($customStart) || !$validDate($customEnd) || $customStart > $customEnd)) {
  $period = 'month';
  $rangeStart = date('Y-m-01');
  $rangeEnd = date('Y-m-t');
  $periodLabel = 'This month';
}

// Get payment method statistics
$paymentStats = $conn->prepare("
  SELECT
    payment_method,
    COUNT(*) as transaction_count,
    COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount ELSE 0 END), 0) as income_total,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) as expense_total,
    COALESCE(SUM(amount), 0) as total_amount
  FROM transactions
  WHERE date BETWEEN ? AND ?
  GROUP BY payment_method
  ORDER BY total_amount DESC
");
$paymentStats->bind_param('ss', $rangeStart, $rangeEnd);
$paymentStats->execute();
$paymentStatsResult = $paymentStats->get_result();

$stats = [];
$totalIncome = 0;
$totalExpense = 0;
$totalTransactions = 0;

while ($row = $paymentStatsResult->fetch_assoc()) {
  $method = $row['payment_method'] ?: 'Not specified';
  $stats[$method] = [
    'transaction_count' => (int)$row['transaction_count'],
    'income_total' => (float)$row['income_total'],
    'expense_total' => (float)$row['expense_total'],
    'total_amount' => (float)$row['total_amount']
  ];
  $totalIncome += (float)$row['income_total'];
  $totalExpense += (float)$row['expense_total'];
  $totalTransactions += (int)$row['transaction_count'];
}

$paymentStats->close();

// Get overall totals
$overallStats = $conn->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN type IN ('income','payment') THEN amount ELSE 0 END), 0) as total_income,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) as total_expense,
    COUNT(*) as total_transactions
  FROM transactions
  WHERE date BETWEEN ? AND ?
");
$overallStats->bind_param('ss', $rangeStart, $rangeEnd);
$overallStats->execute();
$overall = $overallStats->get_result()->fetch_assoc();
$overallStats->close();

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Payment Method Summary — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='reports'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Payment Method Summary'; $page_subtitle='Analyze payment distribution by method'; include "../includes/layout/header.php"; ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-xl-3">
        <div class="card p-3">
          <div class="text-muted small">Total Transactions</div>
          <div class="h4 mb-0"><?= number_format($overall['total_transactions']) ?></div>
          <small class="text-muted"><?= $periodLabel ?></small>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card p-3">
          <div class="text-muted small">Total Income</div>
          <div class="h4 mb-0 text-success"><?= fmtCurrency($overall['total_income']) ?></div>
          <small class="text-muted">All payment methods</small>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card p-3">
          <div class="text-muted small">Total Expenses</div>
          <div class="h4 mb-0 text-danger"><?= fmtCurrency($overall['total_expense']) ?></div>
          <small class="text-muted">All payment methods</small>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card p-3">
          <div class="text-muted small">Net Movement</div>
          <div class="h4 mb-0 <?= ($overall['total_income'] - $overall['total_expense']) >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= fmtCurrency($overall['total_income'] - $overall['total_expense']) ?>
          </div>
          <small class="text-muted">Income minus expenses</small>
        </div>
      </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card p-3 mb-4">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Period</label>
          <select class="form-select" name="period" onchange="this.form.submit()">
            <option value="week" <?= $period==='week'?'selected':'' ?>>This week</option>
            <option value="month" <?= $period==='month'?'selected':'' ?>>This month</option>
            <option value="year" <?= $period==='year'?'selected':'' ?>>This year</option>
            <option value="custom" <?= $period==='custom'?'selected':'' ?>>Custom range</option>
          </select>
        </div>
        <?php if ($period === 'custom'): ?>
          <div class="col-md-3">
            <label class="form-label">From date</label>
            <input type="date" class="form-control" name="start" value="<?= htmlspecialchars($customStart) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">To date</label>
            <input type="date" class="form-control" name="end" value="<?= htmlspecialchars($customEnd) ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary">Apply</button>
          <a href="payment_method_summary.php" class="btn btn-outline-light">Reset</a>
        </div>
      </form>
    </div>

    <!-- Payment Method Table -->
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Payment Method Breakdown</h5>
        <a class="btn btn-sm btn-outline-light" href="payment_method_summary.php?<?= htmlspecialchars(http_build_query(['period'=>$period,'start'=>$customStart,'end'=>$customEnd,'export'=>'csv'])) ?>">
          <i class="bi bi-download"></i> Export CSV
        </a>
      </div>
      <div class="table-responsive">
        <table class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Payment Method</th>
              <th class="text-end">Transactions</th>
              <th class="text-end">Income</th>
              <th class="text-end">Expenses</th>
              <th class="text-end">Total Amount</th>
              <th class="text-end">% of Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($stats)): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-4">
                  No transactions found for this period.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($stats as $method => $data): ?>
                <tr>
                  <td><?= htmlspecialchars(payment_method_label($method)) ?></td>
                  <td class="text-end"><?= number_format($data['transaction_count']) ?></td>
                  <td class="text-end text-success"><?= fmtCurrency($data['income_total']) ?></td>
                  <td class="text-end text-danger"><?= fmtCurrency($data['expense_total']) ?></td>
                  <td class="text-end fw-bold"><?= fmtCurrency($data['total_amount']) ?></td>
                  <td class="text-end">
                    <?php if ($overall['total_income'] + $overall['total_expense'] > 0): ?>
                      <?= number_format(($data['total_amount'] / ($overall['total_income'] + $overall['total_expense'])) * 100, 1) ?>%
                    <?php else: ?>
                      0%
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="table-dark">
              <th>Total</th>
              <th class="text-end"><?= number_format($overall['total_transactions']) ?></th>
              <th class="text-end text-success"><?= fmtCurrency($overall['total_income']) ?></th>
              <th class="text-end text-danger"><?= fmtCurrency($overall['total_expense']) ?></th>
              <th class="text-end fw-bold"><?= fmtCurrency($overall['total_income'] + $overall['total_expense']) ?></th>
              <th class="text-end">100%</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
