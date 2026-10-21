<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/suppliers.php";

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$payment = supplier_payment_get($conn, $paymentId);
if (!$payment) {
  http_response_code(404);
  exit('Supplier payment not found.');
}
$allocations = supplier_payment_get_allocations($conn, $paymentId);
$allocations = array_values(array_filter($allocations, static fn($allocation) => $allocation['reversed_at'] === null));
$currencySymbol = (string)($_SESSION['currency_symbol'] ?? 'GH₵');
$formatAmount = static fn($amount): string => htmlspecialchars($currencySymbol) . number_format((float)$amount, 2);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Supplier Payment Receipt — <?= htmlspecialchars($payment['payment_number']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>@media print {.no-print{display:none!important}body{background:#fff;color:#000}.card{border:0!important}}</style>
</head>
<body class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <a href="supplier_payments.php" class="btn btn-outline-secondary">Back to payments</a>
    <button class="btn btn-primary" onclick="window.print()">Print receipt</button>
  </div>
  <div class="card p-4 mx-auto" style="max-width:760px">
    <h1 class="h3">Supplier Payment Receipt</h1>
    <div class="text-muted mb-4">LMG Creative Media</div>
    <dl class="row">
      <dt class="col-sm-4">Payment number</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['payment_number']) ?></dd>
      <dt class="col-sm-4">Supplier</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['business_name']) ?></dd>
      <dt class="col-sm-4">Payment date</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['payment_date']) ?></dd>
      <dt class="col-sm-4">Method</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['payment_method']) ?></dd>
      <dt class="col-sm-4">Reference</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['reference_number'] ?? '—') ?></dd>
      <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['description'] ?? '—') ?></dd>
      <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= htmlspecialchars($payment['status']) ?></dd>
      <dt class="col-sm-4">Amount</dt><dd class="col-sm-8 fw-bold"><?= $formatAmount($payment['amount']) ?></dd>
    </dl>
    <h2 class="h6 mt-3">Current bill allocations</h2>
    <?php if (!$allocations): ?><p class="text-muted">This payment is currently unallocated.</p><?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($allocations as $allocation): ?><li class="list-group-item px-0 d-flex justify-content-between"><span><?= htmlspecialchars($allocation['bill_number']) ?></span><span><?= $formatAmount($allocation['amount']) ?></span></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="text-muted small mt-4">This receipt reflects the payment record and current allocations. Allocation reversals remain in the payment history.</p>
  </div>
</body>
</html>
