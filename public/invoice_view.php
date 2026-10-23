<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/sms.php";
require_once "../includes/payment_methods.php";
require_once "../includes/invoice.php";

$id = (int)($_GET['id'] ?? 0);
$message = '';
$paymentRequestKey = bin2hex(random_bytes(16));
$paymentForm = ['amount'=>'', 'payment_method'=>'', 'description'=>'', 'date'=>date('Y-m-d')];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $paymentForm = [
    'amount'=>(string)($_POST['amount'] ?? ''),
    'payment_method'=>(string)($_POST['payment_method'] ?? ''),
    'description'=>(string)($_POST['description'] ?? ''),
    'date'=>(string)($_POST['date'] ?? date('Y-m-d')),
  ];
  $postedRequestKey = (string)($_POST['request_key'] ?? '');
  if (preg_match('/^[a-f0-9]{32}$/', $postedRequestKey)) $paymentRequestKey = $postedRequestKey;
  if (!csrf_verify()) {
    $message = 'Invalid request token. Refresh the page and try again.';
  } else {
  $action = $_POST['action'] ?? '';
  if ($action === 'add_payment') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    try {
      if ($invoice_id !== $id || $invoice_id <= 0) throw new InvalidArgumentException('Invalid invoice. Refresh and try again.');
      $result = invoice_record_payment(
        $conn,
        $invoice_id,
        $paymentForm['amount'],
        $paymentForm['payment_method'] !== '' ? $paymentForm['payment_method'] : null,
        trim($paymentForm['description']),
        $paymentForm['date'],
        $paymentRequestKey
      );
      $message = $result['duplicate'] ? 'This payment was already recorded.' : 'Payment recorded.';
      if (!$result['duplicate']) {
        $paymentRequestKey = bin2hex(random_bytes(16));
        $paymentForm = ['amount'=>'', 'payment_method'=>'', 'description'=>'', 'date'=>date('Y-m-d')];
      }
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
}
$stmt = $conn->prepare("SELECT i.invoice_number, i.invoice_date, i.due_date, i.subtotal, i.tax, i.total, i.status, i.notes, i.client_id, i.cancelled_at, i.cancellation_reason, c.full_name, c.email, c.phone, c.address FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($invoice_number,$invoice_date,$due_date,$subtotal,$tax,$total,$status,$notes,$client_id,$cancelled_at,$cancellation_reason,$client_name,$client_email,$client_phone,$client_address);
$found = $stmt->fetch();
$stmt->close();

$paidTotal = '0.00';
$balanceDue = '0.00';
$paymentRows = [];
if ($found) {
  $paymentSummary = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE invoice_id=? AND type='payment'");
  $paymentSummary->bind_param('i', $id);
  $paymentSummary->execute();
  $paymentSummary->bind_result($paidTotal);
  $paymentSummary->fetch();
  $paymentSummary->close();
  $totalCents = invoice_money_cents((string)$total);
  $paidCents = invoice_money_cents((string)$paidTotal);
  if ($totalCents === null || $paidCents === null) throw new RuntimeException('Invoice payment totals are invalid.');
  $paidTotal = invoice_cents_decimal($paidCents);
  $balanceDue = invoice_cents_decimal(max(0, $totalCents - $paidCents));
  $paymentHistory = $conn->prepare("SELECT id, amount, date, payment_method, description FROM transactions WHERE invoice_id=? AND type='payment' ORDER BY date DESC, id DESC");
  $paymentHistory->bind_param('i', $id);
  $paymentHistory->execute();
  $paymentRows = $paymentHistory->get_result()->fetch_all(MYSQLI_ASSOC);
  $paymentHistory->close();
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
      .sidebar, .nav, .btn, header, footer { display:none !important; }
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
            <div class="text-muted small">Bringing Creative Ideas to L</div>
          </div>
        </div>
        <div class="text-end">
          <div class="fs-3 fw-bold">INVOICE</div>
          <div class="text-muted small">Invoice #: <?= htmlspecialchars($invoice_number) ?></div>
          <div class="text-muted small">Date: <?= htmlspecialchars($invoice_date ?? '') ?></div>
          <div class="text-muted small">Due: <?= htmlspecialchars($due_date ?? '') ?></div>
          <div class="mt-1">Status: <span class="badge bg-<?= $status==='paid'?'success':($status==='partial'?'warning text-dark':'secondary') ?>"><?= htmlspecialchars($status) ?></span></div>
          <?php if ($cancelled_at !== null): ?><div class="alert alert-warning mt-3 mb-0" role="status"><strong>Cancelled</strong><?= $cancellation_reason ? ': '.htmlspecialchars($cancellation_reason, ENT_QUOTES, 'UTF-8') : '' ?>. Recorded payments remain in the financial history.</div><?php endif; ?>
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
          <div class="d-flex justify-content-between"><div class="text-muted">Payments received</div><div><?= fmtCurrency((float)$paidTotal) ?></div></div>
          <div class="d-flex justify-content-between fw-bold"><div>Balance due</div><div><?= fmtCurrency((float)$balanceDue) ?></div></div>
          <div class="text-muted small mt-3">Payment due within 14 days. Kindly reference the invoice number on payment.</div>
        </div>
      </div>

      <section class="mt-4" aria-labelledby="invoicePaymentHistoryTitle">
        <h2 id="invoicePaymentHistoryTitle" class="h6">Payment History</h2>
        <?php if (!$paymentRows): ?>
          <p class="text-muted small">No payments have been recorded for this invoice.</p>
        <?php else: ?>
          <div class="table-responsive"><table class="table table-sm table-brand align-middle">
            <thead><tr><th>Date</th><th>Reference</th><th>Method</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
            <tbody><?php foreach ($paymentRows as $payment): ?>
              <tr><td><?= htmlspecialchars($payment['date'], ENT_QUOTES, 'UTF-8') ?></td><td><a href="receipt_view.php?id=<?= (int)$payment['id'] ?>">RCPT-<?= str_pad((string)$payment['id'], 5, '0', STR_PAD_LEFT) ?></a></td><td><?= htmlspecialchars(payment_method_label($payment['payment_method'] ?? null), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($payment['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><td class="text-end"><?= fmtCurrency((float)$payment['amount']) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php endif; ?>
      </section>

      <?php if($notes): ?><div class="mt-3 text-muted small"><?= nl2br(htmlspecialchars($notes)) ?></div><?php endif; ?>
      <?php if ($cancelled_at === null): ?><div class="mt-4">
        <div class="card p-3">
          <h6 class="mb-3">Record Payment</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" value="<?= (int)$id ?>">
            <input type="hidden" name="request_key" value="<?= htmlspecialchars($paymentRequestKey, ENT_QUOTES, 'UTF-8') ?>">
            <div class="col-6"><label class="visually-hidden" for="paymentAmount">Amount</label><input class="form-control" id="paymentAmount" type="number" min="0.01" step="0.01" name="amount" placeholder="Amount" value="<?= htmlspecialchars($paymentForm['amount'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="col-6">
              <label class="visually-hidden" for="paymentMethod">Payment method</label><select class="form-select" id="paymentMethod" name="payment_method">
                <option value="">Payment Method</option>
                <option value="cash" <?= $paymentForm['payment_method']==='cash'?'selected':'' ?>>Cash</option>
                <option value="mobile" <?= $paymentForm['payment_method']==='mobile'?'selected':'' ?>>MoMo</option>
                <option value="card" <?= $paymentForm['payment_method']==='card'?'selected':'' ?>>Card</option>
              </select>
            </div>
            <div class="col-6"><label class="visually-hidden" for="paymentDate">Payment date</label><input class="form-control" id="paymentDate" type="date" name="date" value="<?= htmlspecialchars($paymentForm['date'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-12"><label class="visually-hidden" for="paymentDescription">Description</label><textarea class="form-control" id="paymentDescription" name="description" placeholder="Description"><?= htmlspecialchars($paymentForm['description'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Add Payment</button></div>
          </form>
        </div>
      </div><?php endif; ?>
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
    <?php endif; ?>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="js/ui.js"></script>
</body>
</html>
