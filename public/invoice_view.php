<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/sms.php";
require_once "../includes/invoice.php";

$id = (int)($_GET['id'] ?? 0);
$message = '';
$requestKey = bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'add_payment') $requestKey = (string)($_POST['request_key'] ?? '');
  if ($action === 'add_payment') {
    try {
      $invoice_id = (int)($_POST['invoice_id'] ?? 0);
      $payment_method = (string)($_POST['payment_method'] ?? '');
      if ($payment_method === '') $payment_method = null;
      $result = invoice_record_payment(
        $conn,
        $invoice_id,
        (string)($_POST['amount'] ?? ''),
        $payment_method,
        trim((string)($_POST['description'] ?? '')),
        (string)($_POST['date'] ?? date('Y-m-d')),
        (string)($_POST['request_key'] ?? '')
      );
      $message = $result['duplicate'] ? 'Payment already recorded' : 'Payment recorded';
      $requestKey = bin2hex(random_bytes(16));
    } catch (InvalidArgumentException $e) {
      $message = $e->getMessage();
    } catch (Throwable $e) {
      $message = 'Payment could not be recorded. Please try again.';
      error_log('Invoice payment failed: '.$e->getMessage());
    }
  }
  if ($action === 'send_sms') {
    $phone = trim($_POST['phone'] ?? '');
    $text = trim($_POST['text'] ?? '');
    if ($phone === '' && !empty($client_phone)) { $phone = trim($client_phone); }
    if ($phone === '' || $text === '') {
      $message = 'Enter phone and message';
    } else {
      $res = sms_send($conn, $phone, $text, $client_id ?? null);
      $message = $res['success'] ? 'SMS sent' : ('SMS failed: '.$res['error']);
      $idStr = (string)$id;
      log_action($conn, 'sms_send', $idStr.'|'.$phone);
    }
  }
  if ($action === 'send_email') {
    $toEmail = trim($_POST['email'] ?? '');
    $body = trim($_POST['email_text'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    if ($subject === '') { $subject = 'Invoice Reminder'; }
    if ($toEmail === '' || $body === '') {
      $message = 'Enter email and message';
    } else {
      $fromEmail = _env('SMTP_FROM', _env('EMAIL_SENDER', 'no-reply@example.com'));
      $fromName = _env('SMTP_FROM_NAME', 'LMG Creative Media');
      $headers = [];
      $headers[] = 'MIME-Version: 1.0';
      $headers[] = 'Content-type: text/plain; charset=utf-8';
      $headers[] = 'From: '.$fromName.' <'.$fromEmail.'>';
      $ok = function_exists('mail') ? @mail($toEmail, $subject, $body, implode("\r\n", $headers)) : false;
      $message = $ok ? 'Email sent' : 'Email failed';
      log_action($conn, 'email_send', (string)$id.'|'.$toEmail);
    }
  }
}
$stmt = $conn->prepare("SELECT i.invoice_number, i.invoice_date, i.due_date, i.subtotal, i.tax, i.total,
  CASE WHEN COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.invoice_id=i.id AND t.type='payment'),0)<=0 THEN 'unpaid'
       WHEN COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.invoice_id=i.id AND t.type='payment'),0)<COALESCE(i.total,0) THEN 'partial'
       ELSE 'paid' END AS status,
  i.notes, i.client_id, c.full_name, c.email, c.phone, c.address FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($invoice_number,$invoice_date,$due_date,$subtotal,$tax,$total,$status,$notes,$client_id,$client_name,$client_email,$client_phone,$client_address);
$found = $stmt->fetch();
$stmt->close();

$paidCents = 0;
$balanceCents = 0;
$paymentHistory = [];
if ($found) {
  $sumPayments = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid FROM transactions WHERE invoice_id=? AND type='payment'");
  $sumPayments->bind_param('i', $id);
  $sumPayments->execute();
  $paidValue = (string)$sumPayments->get_result()->fetch_assoc()['paid'];
  $sumPayments->close();
  $paidCents = invoice_money_cents($paidValue) ?? 0;
  $totalCents = invoice_money_cents((string)$total) ?? 0;
  $balanceCents = max(0, $totalCents - $paidCents);
  $historyStmt = $conn->prepare("SELECT id, amount, date, payment_method, description FROM transactions WHERE invoice_id=? AND type='payment' ORDER BY date DESC, id DESC");
  $historyStmt->bind_param('i', $id);
  $historyStmt->execute();
  $historyResult = $historyStmt->get_result();
  while ($paymentRow = $historyResult->fetch_assoc()) $paymentHistory[] = $paymentRow;
  $historyStmt->close();
}

$items = $conn->prepare("SELECT item_name, quantity, price, total FROM invoice_items WHERE invoice_id=? ORDER BY id ASC");
$items->bind_param('i', $id);
$items->execute();
$resItems = $items->get_result();

$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Invoice <?= htmlspecialchars($invoice_number ?? '') ?> — LMG</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>
    @media print {
      @page { size: A4; margin: 12mm; }
      .sidebar, .nav, .btn, header, footer, .no-print { display:none !important; }
      .main { padding:0; }
      body { background:#fff; color:#000; }
      .invoice-paper { background:#fff; color:#000; box-shadow:none; }
      .invoice-header .fs-3 { letter-spacing: 1px; }
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
  <?php $active_page='invoices'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Invoice '.($invoice_number ?? ''); $page_subtitle='Preview and print'; $header_actions='<button class="btn btn-outline-light me-2" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>'; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if(!$found): ?>
      <div class="alert alert-danger">Invoice not found</div>
    <?php else: ?>
    <div class="invoice-paper card p-4">
      <div class="invoice-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
          <img src="images/logo.png" alt="LMG" style="width:56px" class="me-3">
          <div>
            <div class="fw-bold fs-4">LMG Creative Media</div>
            <div class="text-muted small">Professional Services</div>
          </div>
        </div>
        <div class="text-end">
          <div class="fs-3 fw-bold">INVOICE</div>
          <div class="text-muted small">Invoice #: <?= htmlspecialchars($invoice_number) ?></div>
          <div class="text-muted small">Date: <?= htmlspecialchars($invoice_date ?? '') ?></div>
          <div class="text-muted small">Due: <?= htmlspecialchars($due_date ?? '') ?></div>
          <div class="mt-1">Status: <span class="badge bg-<?= $status==='paid'?'success':($status==='partial'?'warning text-dark':'secondary') ?>"><?= htmlspecialchars($status) ?></span></div>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-start mt-3">
        <div>
          <div class="fw-bold">Bill To</div>
          <div><?= htmlspecialchars($client_name ?? '') ?></div>
          <div class="text-muted small"><?= htmlspecialchars($client_email ?? '') ?> <?= htmlspecialchars($client_phone ?? '') ?></div>
          <div class="text-muted small"><?= htmlspecialchars($client_address ?? '') ?></div>
        </div>
        <div class="text-end">
          <div class="text-muted small">Currency: GHS</div>
        </div>
      </div>

      <div class="table-responsive mt-3">
        <table class="table table-borderless table-sm table-striped table-hover table-brand">
          <thead>
            <tr class="text-muted small">
              <th>Description</th>
              <th class="text-end">Qty</th>
              <th class="text-end">Price</th>
              <th class="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
          <?php while($row = $resItems->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['item_name']) ?></td>
              <td class="text-end"><?= (int)$row['quantity'] ?></td>
              <td class="text-end"><?= fmtCurrency((float)$row['price']) ?></td>
              <td class="text-end"><?= fmtCurrency((float)$row['total']) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="d-flex justify-content-end">
        <div style="min-width:280px">
          <div class="d-flex justify-content-between"><div class="text-muted">Subtotal</div><div><?= fmtCurrency((float)$subtotal) ?></div></div>
          <div class="d-flex justify-content-between"><div class="text-muted">Tax</div><div><?= fmtCurrency((float)$tax) ?></div></div>
          <div class="d-flex justify-content-between fw-bold"><div>Total</div><div><?= fmtCurrency((float)$total) ?></div></div>
          <div class="text-muted small mt-3">Payment due within 14 days. Kindly reference the invoice number on payment.</div>
        </div>
      </div>

      <?php if($notes): ?><div class="mt-3 text-muted small"><?= nl2br(htmlspecialchars($notes)) ?></div><?php endif; ?>
      <?php if ($status !== 'paid'): ?>
      <div class="mt-4">
        <div class="card p-3">
          <h6 class="mb-3">Record Payment</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" value="<?= (int)$id ?>">
            <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
            <input type="hidden" name="request_key" value="<?= htmlspecialchars($requestKey, ENT_QUOTES) ?>">
            <div class="col-6"><input class="form-control" type="number" step="0.01" name="amount" placeholder="Amount" required></div>
            <div class="col-6">
              <select class="form-select" name="payment_method">
                <option value="">Payment Method</option>
                <option value="cash">Cash</option>
                <option value="mobile">Mobile</option>
                <option value="card">Card</option>
              </select>
            </div>
            <div class="col-6"><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><textarea class="form-control" name="description" placeholder="Description"></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Add Payment</button></div>
          </form>
        </div>
      </div>
      <?php endif; ?>
      <div class="mt-3">
        <div class="card p-3">
          <h6 class="mb-3">Send Reminder SMS</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_sms">
            <div class="col-md-5"><input class="form-control" name="phone" value="<?= htmlspecialchars($client_phone ?? '') ?>" placeholder="Phone"></div>
            <div class="col-md-7"><textarea class="form-control" name="text" rows="2">Hi <?= htmlspecialchars($client_name ?? '') ?>, this is a reminder for invoice <?= htmlspecialchars($invoice_number ?? '') ?> of <?= fmtCurrency((float)$total) ?> (status: <?= htmlspecialchars($status ?? '') ?>). Please pay by <?= htmlspecialchars($due_date ?? '') ?>. Thank you.</textarea></div>
            <div class="col-12"><button class="btn btn-outline-primary">Send SMS</button></div>
          </form>
        </div>
      </div>
      <div class="mt-3">
        <div class="card p-3">
          <h6 class="mb-3">Send Reminder Email</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_email">
            <div class="col-md-5"><input class="form-control" type="email" name="email" value="<?= htmlspecialchars($client_email ?? '') ?>" placeholder="Email"></div>
            <div class="col-md-7"><textarea class="form-control" name="email_text" rows="2">Hi <?= htmlspecialchars($client_name ?? '') ?>, this is a reminder for invoice <?= htmlspecialchars($invoice_number ?? '') ?> of <?= fmtCurrency((float)$total) ?> (status: <?= htmlspecialchars($status ?? '') ?>). Please pay by <?= htmlspecialchars($due_date ?? '') ?>. Thank you.</textarea></div>
            <div class="col-md-12"><input class="form-control" name="subject" value="Invoice <?= htmlspecialchars($invoice_number ?? '') ?> Reminder"></div>
            <div class="col-12"><button class="btn btn-outline-primary">Send Email</button></div>
          </form>
        </div>
      </div>
    </div>
    <section class="card p-3 mt-3 no-print" aria-labelledby="paymentHistoryTitle">
      <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h2 id="paymentHistoryTitle" class="h6 mb-0">Payment History</h2>
        <div class="small text-muted">Received: <?= fmtCurrency($paidCents / 100) ?> · Balance due: <?= fmtCurrency($balanceCents / 100) ?></div>
      </div>
      <?php if (!$paymentHistory): ?>
        <div class="text-muted small">No payments have been recorded for this invoice.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-borderless table-sm table-striped table-brand mb-0">
            <thead><tr class="text-muted small"><th>Payment #</th><th>Date</th><th>Method</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($paymentHistory as $paymentRow): ?>
                <tr>
                  <td><a href="receipt_view.php?id=<?= (int)$paymentRow['id'] ?>">TXN-<?= (int)$paymentRow['id'] ?></a></td>
                  <td><?= htmlspecialchars($paymentRow['date']) ?></td>
                  <td><?= htmlspecialchars($paymentRow['payment_method'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($paymentRow['description'] ?: '—') ?></td>
                  <td class="text-end"><?= fmtCurrency((float)$paymentRow['amount']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="js/ui.js"></script>
</body>
</html>
