<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/payment_methods.php";

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT t.id, t.type, t.amount, t.date, t.payment_method, t.description, c.full_name, c.phone, c.email, i.invoice_number FROM transactions t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id WHERE t.id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($tid,$type,$amount,$date,$method,$desc,$client_name,$client_phone,$client_email,$invoice_number);
$found = $stmt->fetch();
$stmt->close();
if ($found && !in_array($type, ['income', 'payment'], true)) $found = false;
if ($found && in_array($type, ['income', 'payment'], true)) {
  audit_event($conn, [
    'action' => 'receipt.issued', 'module' => 'receipts',
    'entity_type' => 'transaction', 'entity_id' => (string)$tid,
    'description' => 'Payment receipt viewed or issued.',
    'metadata' => ['transaction_type'=>$type,'amount'=>(float)$amount,'date'=>$date]
  ]);
}
$receipt_number = $found ? ("RCPT-" . date("Ymd", strtotime($date)) . "-" . str_pad($tid, 5, "0", STR_PAD_LEFT)) : '';
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Receipt <?= htmlspecialchars($receipt_number) ?> — LMG</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>
    @media print {
      .sidebar, .nav, .btn, header, footer { display:none !important; }
      .main { padding:0; }
      body { background:#fff; color:#000; }
      .paper { background:#fff; color:#000; box-shadow:none; }
      .table { color:#000; border-collapse: collapse; }
      .table thead th { border-bottom: 2px solid #000 !important; }
      .table th, .table td { border: 1px solid #000 !important; }
    }
  </style>
  <link rel="icon" href="../favicon/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="../favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
  <link rel="apple-touch-icon" sizes="180x180" href="../favicon/apple-touch-icon.png">
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='transactions'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Receipt'; $page_subtitle='Preview and print'; $header_actions='<button class="btn btn-outline-light me-2" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>'; include "../includes/layout/header.php"; ?>

    <?php if(!$found): ?>
      <div class="alert alert-danger">Transaction not found</div>
    <?php else: ?>
    <div class="paper card p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center">
          <img src="images/logo.png" alt="LMG" style="width:56px" class="me-3">
          <div>
            <div class="fw-bold fs-5">LMG Creative Media</div>
            <div class="text-muted small">Receipt</div>
          </div>
        </div>
        <div class="text-end">
          <div class="fw-bold"><?= htmlspecialchars($receipt_number) ?></div>
          <div class="text-muted small"><?= htmlspecialchars($date) ?></div>
        </div>
      </div>
      <hr>
      <div class="row mb-3">
        <div class="col-md-6">
          <div class="fw-bold mb-1">Received From</div>
          <div><?= htmlspecialchars($client_name ?? '') ?></div>
          <div class="text-muted small"><?= htmlspecialchars($client_phone ?? '') ?></div>
          <div class="text-muted small"><?= htmlspecialchars($client_email ?? '') ?></div>
        </div>
        <div class="col-md-6 text-end">
          <div class="fw-bold mb-1">Amount</div>
          <div class="fs-4"><?= fmtCurrency((float)$amount) ?></div>
          <div class="text-muted small"><?= htmlspecialchars(ucfirst($type)) ?><?= $method ? (' • '.htmlspecialchars(payment_method_label($method))) : '' ?></div>
        </div>
      </div>
      <div class="mb-3">
        <div class="fw-bold mb-1">Description</div>
        <div class="text-muted"><?= htmlspecialchars($desc ?? '') ?></div>
        <?php if (!empty($invoice_number)): ?><div class="text-muted small mt-1">Invoice: <?= htmlspecialchars($invoice_number) ?></div><?php endif; ?>
      </div>
      <div class="mt-4 d-flex justify-content-end">
        <button class="btn btn-outline-light me-2" onclick="window.history.back()"><i class="bi bi-arrow-left"></i> Back</button>
        <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <?php endif; ?>
  </main>
</div>
<script src="js/ui.js"></script>
</body>
</html>
