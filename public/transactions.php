<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/invoice.php";

$message = '';
$transactionRequestKey = bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
  $action = $_POST['action'] ?? '';
  if (isset($_POST['request_key'])) $transactionRequestKey = (string)$_POST['request_key'];
  if ($action === 'create') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $client_name = trim($_POST['client_name'] ?? '');
    $type = $_POST['type'] ?? 'income';
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if (!($type === 'payment' && $invoice_id > 0) && $client_id <= 0 && $client_name !== '') {
      $find = $conn->prepare("SELECT id FROM clients WHERE full_name=? LIMIT 1");
      $find->bind_param('s', $client_name);
      $find->execute();
      $find->bind_result($foundId);
      if ($find->fetch()) { $client_id = (int)$foundId; }
      $find->close();
      if ($client_id <= 0) {
        $conn->begin_transaction();
        $ins = $conn->prepare("INSERT INTO clients (full_name, phone, email, address) VALUES (?,?,?,?)");
        $empty = '';
        $ins->bind_param('ssss', $client_name, $empty, $empty, $empty);
        if ($ins->execute()) {
          $client_id = (int)$ins->insert_id;
          log_action($conn, 'client_create', $client_name, [
            'entity_id' => (string)$client_id,
            'new_values' => ['full_name'=>$client_name]
          ]);
          $conn->commit();
        } else { $conn->rollback(); }
        $ins->close();
      }
    }
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? null;
    $description = trim($_POST['description'] ?? '');
    $date = $_POST['date'] ?: date('Y-m-d');
    if (!in_array($type, ['income','expense','invoice','payment'])) { $type = 'income'; }
    if ($type === 'payment' && $invoice_id > 0) {
      try {
        $result = invoice_record_payment($conn, $invoice_id, (string)($_POST['amount'] ?? ''), $payment_method, $description, (string)$date, $transactionRequestKey);
        $message = $result['duplicate'] ? 'Payment already recorded' : 'Payment added';
        $transactionRequestKey = bin2hex(random_bytes(16));
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Payment could not be recorded. Please try again.';
        error_log('Invoice payment failed: '.$e->getMessage());
      }
    } elseif ($client_id > 0) {
      if ($payment_method && !in_array($payment_method, ['cash','mobile','card'])) { $payment_method = null; }
      $conn->begin_transaction();
      $stmt = $conn->prepare("INSERT INTO transactions (client_id, amount, type, payment_method, description, date) VALUES (?,?,?,?,?,?)");
      $stmt->bind_param('idssss', $client_id, $amount, $type, $payment_method, $description, $date);
      $message = $stmt->execute() ? 'Transaction added' : 'Error adding transaction';
      if ($message === 'Transaction added') {
        log_action($conn, 'transaction_create', (string)$client_id.'|'.$type.'|'.$amount, [
          'action' => 'transaction.'.($type === 'income' ? 'income' : ($type === 'expense' ? 'expense' : $type)).'.created',
          'entity_id' => (string)$stmt->insert_id,
          'new_values' => ['client_id'=>$client_id,'amount'=>$amount,'type'=>$type,'payment_method'=>$payment_method,'description'=>$description,'date'=>$date]
        ]);
        $conn->commit();
      } else {
        $conn->rollback();
      }
      $stmt->close();
    } else {
      $message = 'Specify a client by name';
    }
    } elseif ($action === 'delete') {
      $txId = (int)($_POST['id'] ?? 0);
      if ($txId > 0) {
        $q = $conn->prepare("SELECT id, client_id, type, amount, date, payment_method, description, invoice_id FROM transactions WHERE id=? LIMIT 1");
        $q->bind_param('i', $txId);
        $q->execute();
        $q->bind_result($foundId, $foundClientId, $foundType, $foundAmount, $foundDate, $foundMethod, $foundDescription, $foundInv);
        if ($q->fetch()) {
          $q->close();
          $conn->begin_transaction();
          $del = $conn->prepare("DELETE FROM transactions WHERE id=?");
          $del->bind_param('i', $txId);
          if ($del->execute() && $del->affected_rows > 0) {
            $message = 'Transaction deleted';
            log_action($conn, 'transaction_delete', (string)$txId, [
              'entity_id' => (string)$txId,
              'old_values' => ['client_id'=>$foundClientId,'amount'=>$foundAmount,'type'=>$foundType,'date'=>$foundDate,'payment_method'=>$foundMethod,'description'=>$foundDescription,'invoice_id'=>$foundInv]
            ]);
            // Recalculate invoice status if this was an invoice payment
            if ($foundType === 'payment' && $foundInv !== null) {
              $invoiceStatus = invoice_recalculate_status($conn, (int)$foundInv);
              if ($invoiceStatus['status'] !== $invoiceStatus['old_status']) {
                audit_event($conn, [
                  'action'=>'invoice.status.updated','module'=>'invoices','entity_type'=>'invoice','entity_id'=>(string)$foundInv,
                  'description'=>'Invoice payment status recalculated after payment deletion.',
                  'old_values'=>['status'=>$invoiceStatus['old_status']],
                  'new_values'=>['status'=>$invoiceStatus['status'],'paid_total'=>invoice_cents_decimal($invoiceStatus['paid_cents']),'balance'=>invoice_cents_decimal($invoiceStatus['balance_cents'])]
                ]);
              }
            }
            $conn->commit();
          } else {
            $conn->rollback();
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
        $oldStmt = $conn->prepare("SELECT client_id, amount, type, date, payment_method, description, invoice_id FROM transactions WHERE id=? LIMIT 1");
        $oldStmt->bind_param('i', $txId);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $oldTx = $oldResult->fetch_assoc() ?: null;
        $oldStmt->close();
        $conn->begin_transaction();
        $amount = (float)($_POST['amount'] ?? 0);
        $type = $_POST['type'] ?? 'income';
        $payment_method = $_POST['payment_method'] ?? null;
        $description = trim($_POST['description'] ?? '');
        $date = $_POST['date'] ?: date('Y-m-d');
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        if (!in_array($type, ['income','expense','invoice','payment'])) { $type = 'income'; }
        $upd = $conn->prepare("UPDATE transactions SET amount=?, type=?, payment_method=?, description=?, date=?, invoice_id=? WHERE id=?");
        $upd->bind_param('dssssii', $amount, $type, $payment_method, $description, $date, $invoice_id, $txId);
        if ($upd->execute() && $upd->affected_rows > 0) {
          $message = 'Transaction updated';
          if ($oldTx) {
            log_action($conn, 'transaction_update', (string)$txId.'|'.$type.'|'.$amount, [
              'entity_id' => (string)$txId,
              'old_values' => $oldTx,
              'new_values' => ['client_id'=>(int)$oldTx['client_id'],'amount'=>$amount,'type'=>$type,'date'=>$date,'payment_method'=>$payment_method,'description'=>$description,'invoice_id'=>$invoice_id ?: null]
            ]);
          }
          // Recalculate invoice status if this was or is an invoice payment
          $oldInvoiceId = $oldTx ? (int)($oldTx['invoice_id'] ?? 0) : 0;
          if ($oldInvoiceId > 0 && $oldTx['type'] === 'payment') {
            $oldInvoiceStatus = invoice_recalculate_status($conn, $oldInvoiceId);
            if ($oldInvoiceStatus['status'] !== $oldInvoiceStatus['old_status']) {
              audit_event($conn, [
                'action'=>'invoice.status.updated','module'=>'invoices','entity_type'=>'invoice','entity_id'=>(string)$oldInvoiceId,
                'description'=>'Invoice payment status recalculated after transaction update.',
                'old_values'=>['status'=>$oldInvoiceStatus['old_status']],
                'new_values'=>['status'=>$oldInvoiceStatus['status'],'paid_total'=>invoice_cents_decimal($oldInvoiceStatus['paid_cents']),'balance'=>invoice_cents_decimal($oldInvoiceStatus['balance_cents'])]
              ]);
            }
          }
          if ($invoice_id > 0 && $type === 'payment' && $invoice_id !== $oldInvoiceId) {
            $newInvoiceStatus = invoice_recalculate_status($conn, $invoice_id);
            if ($newInvoiceStatus['status'] !== $newInvoiceStatus['old_status']) {
              audit_event($conn, [
                'action'=>'invoice.status.updated','module'=>'invoices','entity_type'=>'invoice','entity_id'=>(string)$invoice_id,
                'description'=>'Invoice payment status recalculated after transaction update.',
                'old_values'=>['status'=>$newInvoiceStatus['old_status']],
                'new_values'=>['status'=>$newInvoiceStatus['status'],'paid_total'=>invoice_cents_decimal($newInvoiceStatus['paid_cents']),'balance'=>invoice_cents_decimal($newInvoiceStatus['balance_cents'])]
              ]);
            }
          }
          $conn->commit();
        } else {
          $conn->rollback();
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
$txLimit = (isset($_GET['format']) && $_GET['format'] === 'json') ? ' LIMIT 100' : '';
$tx = $conn->query("SELECT t.id, t.type, t.amount, t.date, t.payment_method, t.description, t.invoice_id, c.full_name AS client, i.invoice_number
                    FROM transactions t
                    LEFT JOIN clients c ON c.id=t.client_id
                    LEFT JOIN invoices i ON i.id=t.invoice_id
                    ORDER BY t.date DESC, t.id DESC".$txLimit);
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
  <style>
    .transaction-type-filters .btn {
      transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    @media (prefers-reduced-motion: reduce) {
      .transaction-type-filters .btn { transition: none; }
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
            <input type="hidden" name="request_key" value="<?= htmlspecialchars($transactionRequestKey, ENT_QUOTES) ?>">
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
                  $invRes = $conn->query("SELECT id, invoice_number, client_id, total, status FROM invoices WHERE cancelled_at IS NULL ORDER BY id DESC LIMIT 200");
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
          <div class="transaction-type-filters d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filter transactions by type">
            <button type="button" class="btn btn-sm btn-outline-light active" data-transaction-filter="all" aria-pressed="true">All Transactions</button>
            <button type="button" class="btn btn-sm btn-outline-success" data-transaction-filter="income" aria-pressed="false"><i class="bi bi-arrow-down-left me-1" aria-hidden="true"></i>Income</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-transaction-filter="expense" aria-pressed="false"><i class="bi bi-arrow-up-right me-1" aria-hidden="true"></i>Expenses</button>
          </div>
          <div class="table-responsive">
            <table id="transactionsTable" class="table table-borderless table-sm table-striped table-hover table-brand datatable">
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
                    <form method="POST" class="d-inline-block ms-1 tx-delete-form" data-type="<?= htmlspecialchars($t['type'], ENT_QUOTES) ?>" data-invoice-id="<?= (int)($t['invoice_id'] ?? 0) ?>" data-invoice-number="<?= htmlspecialchars($t['invoice_number'] ?? '', ENT_QUOTES) ?>">
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
      $invRes2 = $conn->query("SELECT id, invoice_number FROM invoices WHERE cancelled_at IS NULL ORDER BY id DESC LIMIT 500");
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
        const table = $(el).DataTable({
          destroy: true,
          pageLength: 10,
          lengthMenu: [10,25,50,100],
          order: [],
          stateSave: true,
          language: { searchPlaceholder: 'Search...', emptyTable: 'No transactions found.', zeroRecords: 'No transactions found.' }
        });

        const filterButtons = document.querySelectorAll('[data-transaction-filter]');
        const emptyMessages = {
          all: 'No transactions found.',
          income: 'No income transactions found.',
          expense: 'No expense transactions found.'
        };
        function setTransactionFilter(filter) {
          const isTypedFilter = filter !== 'all';
          table.column(2).search(isTypedFilter ? '^' + filter + '$' : '', isTypedFilter, false);
          filterButtons.forEach(function(button) {
            const active = button.dataset.transactionFilter === filter;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
          });
          table.settings()[0].oLanguage.sEmptyTable = emptyMessages[filter];
          table.settings()[0].oLanguage.sZeroRecords = emptyMessages[filter];
          table.draw();
        }
        filterButtons.forEach(function(button) {
          button.addEventListener('click', function() {
            setTransactionFilter(button.dataset.transactionFilter);
          });
        });

        // Reflect any type filter restored by DataTables' existing saved table state.
        const savedTypeSearch = table.column(2).search();
        const savedFilter = savedTypeSearch === '^income$' ? 'income'
          : savedTypeSearch === '^expense$' ? 'expense'
            : 'all';
        filterButtons.forEach(function(button) {
          const active = button.dataset.transactionFilter === savedFilter;
          button.classList.toggle('active', active);
          button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        table.settings()[0].oLanguage.sEmptyTable = emptyMessages[savedFilter];
        table.settings()[0].oLanguage.sZeroRecords = emptyMessages[savedFilter];
        table.draw(false);
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

  // Handle transaction delete confirmations with appropriate warnings
  document.querySelectorAll('.tx-delete-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
      const type = form.dataset.type;
      const invoiceId = form.dataset.invoiceId;
      const invoiceNumber = form.dataset.invoiceNumber;
      let message = 'Delete this transaction? This cannot be undone.';
      if (type === 'payment' && invoiceId && invoiceId !== '0') {
        message = `Delete payment for invoice ${invoiceNumber}? This will affect the invoice balance and payment status. This cannot be undone.`;
      }
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });
</script>
<?php if (isset($_GET['edit_transaction']) && filter_var($_GET['edit_transaction'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])): ?>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (!window.jQuery || !jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable('#transactionsTable')) return;
    const wantedId = <?= (int)$_GET['edit_transaction'] ?>;
    const table = jQuery('#transactionsTable').DataTable();
    table.search('').columns().search('').draw();
    const row = table.rows().nodes().toArray().find(function(node) {
      const button = node.querySelector('.btn-edit');
      return button && Number(button.dataset.id) === wantedId;
    });
    row?.querySelector('.btn-edit')?.click();
  });
</script>
<?php endif; ?>
<script src="js/ui.js"></script>
</body>
</html>
