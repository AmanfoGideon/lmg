<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/suppliers.php";

$supplierId = filter_input(INPUT_GET, 'supplier_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$supplier = supplier_get($conn, $supplierId);
if (!$supplier) {
  http_response_code(404);
  exit('Supplier not found.');
}
$start = (string)($_GET['start'] ?? date('Y-m-01'));
$end = (string)($_GET['end'] ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end) {
  $start = date('Y-m-01');
  $end = date('Y-m-t');
}
$stmt = $conn->prepare("SELECT entry_date, entry_type, reference, description, amount, entry_status FROM (
  SELECT bill_date AS entry_date, 'Bill' AS entry_type, bill_number AS reference, description, total AS amount, status AS entry_status, id AS sort_id
  FROM supplier_bills WHERE supplier_id=? AND bill_date <= ?
  UNION ALL
  SELECT payment_date AS entry_date, 'Payment' AS entry_type, payment_number AS reference, CONCAT(COALESCE(description,''), CASE WHEN status='cancelled' THEN ' (Cancelled)' ELSE '' END) AS description,
    CASE WHEN status='cancelled' THEN 0 ELSE amount END AS amount, status AS entry_status, id AS sort_id
  FROM supplier_payments WHERE supplier_id=? AND payment_date <= ?
) ledger ORDER BY entry_date, sort_id, entry_type");
$stmt->bind_param('isis', $supplierId, $end, $supplierId, $end);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$balanceCents = 0;
$openingCents = 0;
$periodEntries = [];
foreach ($entries as &$entry) {
  $amount = (string)$entry['amount'];
  preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts);
  $cents = (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
  $entry['debit'] = $entry['entry_type'] === 'Bill' && $entry['entry_status'] !== 'cancelled' ? $cents : 0;
  $entry['credit'] = $entry['entry_type'] === 'Payment' ? $cents : 0;
  $balanceCents += $entry['debit'] - $entry['credit'];
  $entry['balance'] = $balanceCents;
  if ($entry['entry_date'] < $start) $openingCents = $balanceCents;
  else $periodEntries[] = $entry;
}
unset($entry);
$summary = supplier_get_summary($conn, $supplierId);
$money = static fn(int $cents): string => number_format($cents / 100, 2, '.', ',');
if (($_GET['format'] ?? '') === 'csv') {
  audit_event($conn, [
    'action' => 'supplier.statement.exported',
    'module' => 'suppliers',
    'entity_type' => 'supplier',
    'entity_id' => (string)$supplierId,
    'description' => 'Supplier account statement exported.',
    'metadata' => ['start' => $start, 'end' => $end]
  ]);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="supplier-statement-' . $supplierId . '-' . date('Ymd') . '.csv"');
  $output = fopen('php://output', 'w');
  fputcsv($output, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance', 'Status']);
  fputcsv($output, [$start, 'Opening balance', '', '', '', '', number_format($openingCents / 100, 2, '.', ''), '']);
  foreach ($periodEntries as $entry) {
    fputcsv($output, [$entry['entry_date'], $entry['entry_type'], $entry['reference'], $entry['description'], number_format($entry['debit'] / 100, 2, '.', ''), number_format($entry['credit'] / 100, 2, '.', ''), number_format($entry['balance'] / 100, 2, '.', ''), $entry['entry_status']]);
  }
  fputcsv($output, [$end, 'Closing balance', '', '', '', '', number_format($balanceCents / 100, 2, '.', ''), '']);
  fclose($output);
  exit;
}
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Supplier Statement — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>@media print {.sidebar,.no-print,header,footer{display:none!important}.main{padding:0!important}.card{border:0!important}}</style>
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='suppliers'; include "../includes/layout/sidebar.php"; ?>
  <main class="main flex-grow-1 p-4">
    <?php $page_title='Supplier Statement'; $page_subtitle=$supplier['business_name']; $header_actions=''; include "../includes/layout/header.php"; ?>
    <div class="card p-3 mb-3">
      <form method="GET" class="row g-2 align-items-end no-print">
        <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
        <div class="col-sm-4"><label class="form-label">From</label><input class="form-control" type="date" name="start" value="<?= htmlspecialchars($start) ?>"></div>
        <div class="col-sm-4"><label class="form-label">To</label><input class="form-control" type="date" name="end" value="<?= htmlspecialchars($end) ?>"></div>
        <div class="col-sm-4 d-flex gap-2"><button class="btn btn-primary">Apply</button><a class="btn btn-outline-light" href="?supplier_id=<?= $supplierId ?>&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&format=csv">Export CSV</a><button class="btn btn-outline-light" type="button" onclick="window.print()">Print</button></div>
      </form>
      <div class="row g-3 mt-2">
        <div class="col-md"><small class="text-muted">Purchases</small><div><?= fmtCurrency($summary['total_purchases']) ?></div></div>
        <div class="col-md"><small class="text-muted">Allocated payments</small><div><?= fmtCurrency($summary['total_paid']) ?></div></div>
        <div class="col-md"><small class="text-muted">Outstanding</small><div><?= fmtCurrency($summary['outstanding_balance']) ?></div></div>
        <div class="col-md"><small class="text-muted">Overdue</small><div><?= fmtCurrency($summary['overdue_balance']) ?></div></div>
        <div class="col-md"><small class="text-muted">Unallocated advances</small><div><?= fmtCurrency($summary['unallocated_credit']) ?></div></div>
      </div>
    </div>
    <div class="card p-3">
      <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
        <tbody>
          <tr class="fw-semibold"><td colspan="6">Opening balance</td><td class="text-end"><?= $money($openingCents) ?></td><td></td></tr>
          <?php if (!$periodEntries): ?><tr><td colspan="8" class="text-center text-muted">No account activity in this date range.</td></tr><?php endif; ?>
          <?php foreach ($periodEntries as $entry): ?><tr>
            <td><?= htmlspecialchars($entry['entry_date']) ?></td><td><?= htmlspecialchars($entry['entry_type']) ?></td><td><?= htmlspecialchars($entry['reference']) ?></td><td><?= htmlspecialchars($entry['description'] ?? '') ?></td>
            <td class="text-end"><?= $entry['debit'] ? $money($entry['debit']) : '—' ?></td><td class="text-end"><?= $entry['credit'] ? $money($entry['credit']) : '—' ?></td><td class="text-end"><?= $money($entry['balance']) ?></td><td><?= htmlspecialchars($entry['entry_status']) ?></td>
          </tr><?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="text-end fw-semibold">Period closing balance: <?= $money($balanceCents) ?></div>
    </div>
  </main>
</div>
</body>
</html>
