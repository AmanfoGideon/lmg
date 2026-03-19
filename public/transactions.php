<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $client_name = trim($_POST['client_name'] ?? '');
    if ($client_id <= 0 && $client_name !== '') {
      $find = $conn->prepare("SELECT id FROM clients WHERE full_name=? LIMIT 1");
      $find->bind_param('s', $client_name);
      $find->execute();
      $find->bind_result($foundId);
      if ($find->fetch()) { $client_id = (int)$foundId; }
      $find->close();
      if ($client_id <= 0) {
        $ins = $conn->prepare("INSERT INTO clients (full_name, phone, email, address) VALUES (?,?,?,?)");
        $empty = '';
        $ins->bind_param('ssss', $client_name, $empty, $empty, $empty);
        if ($ins->execute()) { $client_id = $ins->insert_id; }
        $ins->close();
      }
    }
    $amount = (float)($_POST['amount'] ?? 0);
    $type = $_POST['type'] ?? 'income';
    $payment_method = $_POST['payment_method'] ?? null;
    $description = trim($_POST['description'] ?? '');
    $date = $_POST['date'] ?: date('Y-m-d');
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if (!in_array($type, ['income','expense','invoice','payment'])) { $type = 'income'; }
    if ($client_id > 0) {
      if ($payment_method && !in_array($payment_method, ['cash','mobile','card'])) { $payment_method = null; }
      if ($type === 'payment' && $invoice_id > 0) {
        $checkInv = $conn->prepare("SELECT id, client_id, total FROM invoices WHERE id=? LIMIT 1");
        $checkInv->bind_param('i', $invoice_id);
        $checkInv->execute();
        $checkInv->bind_result($invId,$invClientId,$invTotal);
        $invFound = $checkInv->fetch();
        $checkInv->close();
        if ($invFound && (int)$invClientId === $client_id) {
          $stmt = $conn->prepare("INSERT INTO transactions (client_id, amount, type, payment_method, description, date, invoice_id) VALUES (?,?,?,?,?,?,?)");
          $stmt->bind_param('idssssi', $client_id, $amount, $type, $payment_method, $description, $date, $invoice_id);
          $message = $stmt->execute() ? 'Transaction added' : 'Error adding transaction';
          if ($message === 'Transaction added') {
            $sum = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE invoice_id=? AND type='payment'");
            $sum->bind_param('i', $invoice_id);
            $sum->execute();
            $sum->bind_result($paidTotal);
            $sum->fetch();
            $sum->close();
            $newStatus = 'unpaid';
            if ((float)$paidTotal > 0 && (float)$paidTotal < (float)$invTotal) { $newStatus = 'partial'; }
            if ((float)$paidTotal >= (float)$invTotal) { $newStatus = 'paid'; }
            $up = $conn->prepare("UPDATE invoices SET status=? WHERE id=?");
            $up->bind_param('si', $newStatus, $invoice_id);
            $up->execute();
            $up->close();
          }
          if ($message === 'Transaction added') { log_action($conn, 'transaction_create', (string)$client_id.'|'.$type.'|'.$amount.'|INV#'.$invoice_id); }
          $stmt->close();
        } else {
          $stmt = $conn->prepare("INSERT INTO transactions (client_id, amount, type, payment_method, description, date) VALUES (?,?,?,?,?,?)");
          $stmt->bind_param('idssss', $client_id, $amount, $type, $payment_method, $description, $date);
          $message = $stmt->execute() ? 'Transaction added' : 'Error adding transaction';
          if ($message === 'Transaction added') { log_action($conn, 'transaction_create', (string)$client_id.'|'.$type.'|'.$amount); }
          $stmt->close();
        }
      } else {
        $stmt = $conn->prepare("INSERT INTO transactions (client_id, amount, type, payment_method, description, date) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('idssss', $client_id, $amount, $type, $payment_method, $description, $date);
        $message = $stmt->execute() ? 'Transaction added' : 'Error adding transaction';
        if ($message === 'Transaction added') { log_action($conn, 'transaction_create', (string)$client_id.'|'.$type.'|'.$amount); }
        $stmt->close();
      }
    } else {
      $message = 'Specify a client by name';
    }
    } elseif ($action === 'delete') {
      $txId = (int)($_POST['id'] ?? 0);
      if ($txId > 0) {
        $q = $conn->prepare("SELECT id, type, invoice_id FROM transactions WHERE id=? LIMIT 1");
        $q->bind_param('i', $txId);
        $q->execute();
        $q->bind_result($foundId, $foundType, $foundInv);
        if ($q->fetch()) {
          $q->close();
          $del = $conn->prepare("DELETE FROM transactions WHERE id=?");
          $del->bind_param('i', $txId);
          if ($del->execute()) {
            $message = 'Transaction deleted';
            log_action($conn, 'transaction_delete', (string)$txId);
            if ($foundType === 'payment' && (int)$foundInv > 0) {
              $sum = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE invoice_id=? AND type='payment'");
              $sum->bind_param('i', $foundInv);
              $sum->execute();
              $sum->bind_result($paidTotal);
              $sum->fetch();
              $sum->close();
              $checkInv = $conn->prepare("SELECT total FROM invoices WHERE id=? LIMIT 1");
              $checkInv->bind_param('i', $foundInv);
              $checkInv->execute();
              $checkInv->bind_result($invTotal);
              $checkInv->fetch();
              $checkInv->close();
              $newStatus = 'unpaid';
              if ((float)$paidTotal > 0 && (float)$paidTotal < (float)$invTotal) { $newStatus = 'partial'; }
              if ((float)$paidTotal >= (float)$invTotal) { $newStatus = 'paid'; }
              $up = $conn->prepare("UPDATE invoices SET status=? WHERE id=?");
              $up->bind_param('si', $newStatus, $foundInv);
              $up->execute();
              $up->close();
            }
          } else {
            $message = 'Error deleting transaction';
          }
          $del->close();
        } else {
          $q->close();
          $message = 'Transaction not found';
        }
      }
    } elseif ($action === 'update') {
      $txId = (int)($_POST['id'] ?? 0);
      if ($txId > 0) {
        $amount = (float)($_POST['amount'] ?? 0);
        $type = $_POST['type'] ?? 'income';
        $payment_method = $_POST['payment_method'] ?? null;
        $description = trim($_POST['description'] ?? '');
        $date = $_POST['date'] ?: date('Y-m-d');
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        if (!in_array($type, ['income','expense','invoice','payment'])) { $type = 'income'; }
        $upd = $conn->prepare("UPDATE transactions SET amount=?, type=?, payment_method=?, description=?, date=?, invoice_id=? WHERE id=?");
        $upd->bind_param('dssssii', $amount, $type, $payment_method, $description, $date, $invoice_id, $txId);
        if ($upd->execute()) {
          $message = 'Transaction updated';
          log_action($conn, 'transaction_update', (string)$txId.'|'.$type.'|'.$amount);
          if ($invoice_id > 0) {
            $sum = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE invoice_id=? AND type='payment'");
            $sum->bind_param('i', $invoice_id);
            $sum->execute();
            $sum->bind_result($paidTotal);
            $sum->fetch();
            $sum->close();
            $checkInv = $conn->prepare("SELECT total FROM invoices WHERE id=? LIMIT 1");
            $checkInv->bind_param('i', $invoice_id);
            $checkInv->execute();
            $checkInv->bind_result($invTotal);
            $checkInv->fetch();
            $checkInv->close();
            $newStatus = 'unpaid';
            if ((float)$paidTotal > 0 && (float)$paidTotal < (float)$invTotal) { $newStatus = 'partial'; }
            if ((float)$paidTotal >= (float)$invTotal) { $newStatus = 'paid'; }
            $up = $conn->prepare("UPDATE invoices SET status=? WHERE id=?");
            $up->bind_param('si', $newStatus, $invoice_id);
            $up->execute();
            $up->close();
          }
        } else {
          $message = 'Error updating transaction';
        }
        $upd->close();
      }
  }
  }
}

$clients = $conn->query("SELECT id, full_name FROM clients ORDER BY full_name ASC");
$clientsArr = [];
while ($cRow = $clients->fetch_assoc()) { $clientsArr[] = $cRow; }
$tx = $conn->query("SELECT t.id, t.type, t.amount, t.date, t.payment_method, t.description, t.invoice_id, c.full_name AS client, i.invoice_number
                    FROM transactions t
                    LEFT JOIN clients c ON c.id=t.client_id
                    LEFT JOIN invoices i ON i.id=t.invoice_id
                    ORDER BY t.date DESC, t.id DESC LIMIT 100");
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
$payload = [];
if (isset($_GET['format']) && $_GET['format'] === 'json') {
  while ($row = $tx->fetch_assoc()) {
    $payload[] = [
      'id' => (int)$row['id'],
      'client' => (string)$row['client'],
      'type' => (string)$row['type'],
      'invoice_id' => isset($row['invoice_id']) ? (int)$row['invoice_id'] : null,
      'invoice_number' => (string)($row['invoice_number'] ?? ''),
      'amount' => (float)$row['amount'],
      'date' => (string)$row['date'],
      'payment_method' => (string)($row['payment_method'] ?? ''),
      'description' => (string)($row['description'] ?? '')
    ];
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['items'=>$payload], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Transactions — LMG Creative Media</title>
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
  <?php $active_page='transactions'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Transactions'; $page_subtitle='Record income, expenses, invoices and payments'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="addTx" class="card p-3">
          <h6 class="mb-3">Add Transaction</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-12">
              <label class="form-label">Client</label>
              <input class="form-control" list="clientsDatalist" name="client_name" id="clientNameTx" placeholder="Type name to search or add new" required>
              <datalist id="clientsDatalist">
                <?php foreach($clientsArr as $c): ?>
                  <option value="<?= htmlspecialchars($c['full_name']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <input type="hidden" name="client_id" id="clientIdTx">
            </div>
            <div class="col-6"><input class="form-control" type="number" step="0.01" name="amount" placeholder="Amount" required></div>
            <div class="col-6">
              <select class="form-select" name="type">
                <option value="income">Income</option>
                <option value="expense">Expense</option>
                <option value="invoice">Invoice</option>
                <option value="payment">Payment</option>
              </select>
            </div>
            <div class="col-12" id="invoiceLinkRow" style="display:none">
              <label class="form-label">Invoice # (for Payment)</label>
              <input class="form-control" list="invoicesDatalist" name="invoice_number" id="invoiceNumberTx" placeholder="Type invoice number to link">
              <datalist id="invoicesDatalist">
                <?php
                  $invRes = $conn->query("SELECT id, invoice_number, client_id, total, status FROM invoices ORDER BY id DESC LIMIT 200");
                  while($iv = $invRes->fetch_assoc()):
                ?>
                  <option value="<?= htmlspecialchars($iv['invoice_number']) ?>"></option>
                <?php endwhile; ?>
              </datalist>
              <input type="hidden" name="invoice_id" id="invoiceIdTx">
            </div>
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
            <div class="col-12"><button class="btn btn-primary w-100">Save</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="listTx" class="card p-3">
          <h6 class="mb-3">Recent Transactions</h6>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable">
              <thead>
                <tr class="text-muted small">
                  <th>#</th>
                  <th>Client</th>
                  <th>Type</th>
                  <th>Invoice #</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Method</th>
                  <th>Description</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($t = $tx->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$t['id'] ?></td>
                  <td><?= htmlspecialchars($t['client']) ?></td>
                  <td class="text-capitalize"><?= htmlspecialchars($t['type']) ?></td>
                  <td class="text-muted small">
                    <?php if (!empty($t['invoice_number'])): ?>
                      <a class="link-light" href="invoice_view.php?id=<?= (int)$t['invoice_id'] ?>"><?= htmlspecialchars($t['invoice_number']) ?></a>
                    <?php endif; ?>
                  </td>
                  <td><?= fmtCurrency((float)$t['amount']) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($t['date']) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($t['payment_method'] ?? '') ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                  <td>
                    <?php if (in_array($t['type'], ['income','payment'])): ?>
                      <a class="btn btn-sm btn-black" href="receipt_view.php?id=<?= (int)$t['id'] ?>"><i class="bi bi-printer"></i> Receipt</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-light btn-edit"
                      data-id="<?= (int)$t['id'] ?>"
                      data-client="<?= htmlspecialchars($t['client'] ?? '', ENT_QUOTES) ?>"
                      data-type="<?= htmlspecialchars($t['type'], ENT_QUOTES) ?>"
                      data-invoice-id="<?= (int)($t['invoice_id'] ?? 0) ?>"
                      data-invoice-number="<?= htmlspecialchars($t['invoice_number'] ?? '', ENT_QUOTES) ?>"
                      data-amount="<?= htmlspecialchars($t['amount']) ?>"
                      data-date="<?= htmlspecialchars($t['date']) ?>"
                      data-method="<?= htmlspecialchars($t['payment_method'] ?? '', ENT_QUOTES) ?>"
                      data-desc="<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES) ?>"
                      aria-label="Edit transaction"><i class="bi bi-pencil-square"></i></button>
                    <form method="POST" class="d-inline-block ms-1" onsubmit="return confirm('Delete this transaction?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button class="btn btn-sm btn-danger" aria-label="Delete transaction"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
              </table>
          </div>
        </div>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<!-- Edit Transaction Modal -->
<div class="modal fade" id="editTxModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" id="editTxForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="editTxId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Transaction</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Client</label>
            <input class="form-control" id="editClient" readonly>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Amount</label>
              <input class="form-control" type="number" step="0.01" name="amount" id="editAmount" required>
            </div>
            <div class="col-6">
              <label class="form-label">Type</label>
              <select class="form-select" name="type" id="editType">
                <option value="income">Income</option>
                <option value="expense">Expense</option>
                <option value="invoice">Invoice</option>
                <option value="payment">Payment</option>
              </select>
            </div>
            <div class="col-12" id="editInvoiceRow">
              <label class="form-label">Invoice # (for Payment)</label>
              <input class="form-control" list="invoicesDatalist" name="invoice_number" id="editInvoiceNumber" placeholder="Type invoice number to link">
              <input type="hidden" name="invoice_id" id="editInvoiceId">
            </div>
            <div class="col-6">
              <label class="form-label">Method</label>
              <select class="form-select" name="payment_method" id="editMethod">
                <option value="">Payment Method</option>
                <option value="cash">Cash</option>
                <option value="mobile">Mobile</option>
                <option value="card">Card</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Date</label>
              <input class="form-control" type="date" name="date" id="editDate">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" id="editDesc"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function(){
    const map = new Map(<?php echo json_encode(array_map(fn($c)=>[$c['full_name'],(int)$c['id']], $clientsArr), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>);
    const nameInput = document.getElementById('clientNameTx');
    const idInput = document.getElementById('clientIdTx');
    const typeSelect = document.querySelector('select[name="type"]');
    const invoiceRow = document.getElementById('invoiceLinkRow');
    const invNumberInput = document.getElementById('invoiceNumberTx');
    const invIdInput = document.getElementById('invoiceIdTx');
    const invMap = new Map(<?php
      $invMapArr = [];
      $invRes2 = $conn->query("SELECT id, invoice_number FROM invoices ORDER BY id DESC LIMIT 500");
      while($iv2 = $invRes2->fetch_assoc()) { $invMapArr[] = [$iv2['invoice_number'], (int)$iv2['id']]; }
      echo json_encode($invMapArr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    ?>);
    function syncId(){
      const name = (nameInput.value||'').trim();
      const id = map.get(name) || '';
      idInput.value = id;
    }
    function syncInvoice(){
      const n = (invNumberInput?.value||'').trim();
      const id = invMap.get(n) || '';
      if (invIdInput) invIdInput.value = id;
    }
    function toggleInvoiceRow(){
      invoiceRow.style.display = (typeSelect.value === 'payment') ? '' : 'none';
    }
    nameInput.addEventListener('change', syncId);
    nameInput.addEventListener('input', syncId);
    invNumberInput?.addEventListener('change', syncInvoice);
    invNumberInput?.addEventListener('input', syncInvoice);
    typeSelect?.addEventListener('change', toggleInvoiceRow);
    toggleInvoiceRow();
  })();
</script>
<script>
  jQuery(function($){
    $('table.datatable').each(function(){
      const el = this;
      if ($.fn.DataTable.isDataTable(el)) return;
        $(el).DataTable({
          destroy: true,
          pageLength: 10,
          lengthMenu: [10,25,50,100],
          order: [],
          stateSave: true,
          language: { searchPlaceholder: 'Search...' }
        });
    });
  });
</script>
<script>
  (function(){
    const editModalEl = document.getElementById('editTxModal');
    const bsModal = new bootstrap.Modal(editModalEl);
    const fId = document.getElementById('editTxId');
    const fClient = document.getElementById('editClient');
    const fAmount = document.getElementById('editAmount');
    const fType = document.getElementById('editType');
    const fInvoiceNumber = document.getElementById('editInvoiceNumber');
    const fInvoiceId = document.getElementById('editInvoiceId');
    const fMethod = document.getElementById('editMethod');
    const fDate = document.getElementById('editDate');
    const fDesc = document.getElementById('editDesc');
    const invoiceRow = document.getElementById('editInvoiceRow');

    document.addEventListener('click', function(e){
      const btn = e.target.closest && e.target.closest('.btn-edit');
      if (!btn) return;
      const dataset = btn.dataset || {};
      fId.value = dataset.id || '';
      fClient.value = dataset.client || '';
      fAmount.value = dataset.amount || '';
      fType.value = dataset.type || 'income';
      fInvoiceNumber.value = dataset.invoiceNumber || '';
      fInvoiceId.value = dataset.invoiceId || '';
      fMethod.value = dataset.method || '';
      fDate.value = dataset.date || '';
      fDesc.value = dataset.desc || '';
      invoiceRow.style.display = (fType.value === 'payment') ? '' : 'none';
      bsModal.show();
    });

    // toggle invoice row when type changes inside modal
    fType.addEventListener('change', function(){
      invoiceRow.style.display = (this.value === 'payment') ? '' : 'none';
    });
  })();
</script>
<script src="js/ui.js"></script>
</body>
</html>
