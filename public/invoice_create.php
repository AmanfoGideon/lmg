<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/invoice.php";

$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
$message = '';
$requestKey = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $invoice_date = (string)($_POST['invoice_date'] ?? date('Y-m-d'));
    $due_date = (string)($_POST['due_date'] ?? '');
    $notes = trim((string)($_POST['notes'] ?? ''));
    $requestKey = (string)($_POST['request_key'] ?? '');
    $rawItems = json_decode((string)($_POST['items'] ?? '[]'), true);
    try {
      if (!invoice_valid_date($invoice_date) || !invoice_valid_date($due_date, true)) throw new InvalidArgumentException('Enter valid invoice and due dates.');
      if (!preg_match('/^[a-f0-9]{32}$/', $requestKey)) throw new InvalidArgumentException('Reload the page before submitting this invoice.');
      $calculation = invoice_calculate(is_array($rawItems) ? $rawItems : [], $_POST['tax'] ?? '0');
      if ($client_id <= 0 && $client_name === '') throw new InvalidArgumentException('Please provide a client and at least one item.');
      if (strlen($notes) > 65000) throw new InvalidArgumentException('Notes are too long.');

      $conn->begin_transaction();
      $existingInvoice = $conn->prepare('SELECT id FROM invoices WHERE request_key=? LIMIT 1');
      $existingInvoice->bind_param('s', $requestKey);
      $existingInvoice->execute();
      $existingRow = $existingInvoice->get_result()->fetch_assoc();
      $existingInvoice->close();
      if ($existingRow) {
        $conn->rollback();
        header('Location: invoice_view.php?id='.(int)$existingRow['id']);
        exit;
      }
      if ($client_id > 0) {
        $checkClient = $conn->prepare('SELECT id FROM clients WHERE id=? LIMIT 1');
        $checkClient->bind_param('i', $client_id);
        $checkClient->execute();
        $validClient = (bool)$checkClient->get_result()->fetch_row();
        $checkClient->close();
        if (!$validClient) throw new InvalidArgumentException('Select a valid client.');
      } else {
        $find = $conn->prepare('SELECT id FROM clients WHERE full_name=? ORDER BY id LIMIT 1');
        $find->bind_param('s', $client_name);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();
        if ($existing) {
          $client_id = (int)$existing['id'];
        } else {
          $empty = '';
          $ins = $conn->prepare('INSERT INTO clients (full_name, phone, email, address) VALUES (?,?,?,?)');
          $ins->bind_param('ssss', $client_name, $empty, $empty, $empty);
          $ins->execute();
          $client_id = (int)$ins->insert_id;
          $ins->close();
          log_action($conn, 'client_create', $client_name, ['entity_id'=>(string)$client_id,'new_values'=>['full_name'=>$client_name]]);
        }
      }
      $invoice_id = invoice_insert($conn, ['client_id'=>$client_id,'invoice_date'=>$invoice_date,'due_date'=>$due_date,'notes'=>$notes,'request_key'=>$requestKey], $calculation);
      $invoice_number = 'INV-'.str_replace('-', '', $invoice_date).'-'.str_pad((string)$invoice_id, 4, '0', STR_PAD_LEFT);
      log_action($conn, 'invoice_create', $invoice_number, [
        'entity_id'=>(string)$invoice_id,
        'new_values'=>['invoice_number'=>$invoice_number,'client_id'=>$client_id,'invoice_date'=>$invoice_date,'due_date'=>$due_date ?: null,'subtotal'=>$calculation['subtotal'],'tax'=>$calculation['tax'],'total'=>$calculation['total'],'status'=>'unpaid','item_count'=>count($calculation['items'])]
      ]);
      $conn->commit();
      header('Location: invoice_view.php?id='.$invoice_id);
      exit;
    } catch (InvalidArgumentException $e) {
      try { $conn->rollback(); } catch (Throwable $ignored) {}
      $message = $e->getMessage();
    } catch (Throwable $e) {
      try { $conn->rollback(); } catch (Throwable $ignored) {}
      if (preg_match('/^[a-f0-9]{32}$/', $requestKey)) {
        $retry = $conn->prepare('SELECT id FROM invoices WHERE request_key=? LIMIT 1');
        $retry->bind_param('s', $requestKey);
        $retry->execute();
        $retryRow = $retry->get_result()->fetch_assoc();
        $retry->close();
        if ($retryRow) { header('Location: invoice_view.php?id='.(int)$retryRow['id']); exit; }
      }
      $message = 'Invoice could not be saved. Please try again.';
      error_log('Invoice creation failed: '.$e->getMessage());
    }
  }
}
$clients = $conn->query("SELECT id, full_name FROM clients ORDER BY full_name ASC");
$clientsArr = [];
while ($cRow = $clients->fetch_assoc()) { $clientsArr[] = $cRow; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Create Invoice — LMG Creative Media</title>
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
  <?php $active_page='invoices'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Create Invoice'; $page_subtitle='Add client, items and totals'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card p-3 invoice-card">
      <form method="POST" id="invoiceForm">
        <?= csrf_input() ?>
        <input type="hidden" name="request_key" value="<?= htmlspecialchars($requestKey, ENT_QUOTES) ?>">
        <div class="row g-3">
          <div class="col-lg-4">
            <label class="form-label">Client</label>
            <input class="form-control" list="clientsCreateDatalist" name="client_name" id="clientNameInv" placeholder="Type name to search or add new" required>
            <datalist id="clientsCreateDatalist">
              <?php foreach($clientsArr as $c): ?>
                <option value="<?= htmlspecialchars($c['full_name']) ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <input type="hidden" name="client_id" id="clientIdInv">
          </div>
          <div class="col-lg-4">
            <label class="form-label">Invoice Date</label>
            <input class="form-control" type="date" name="invoice_date" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-lg-4">
            <label class="form-label">Due Date</label>
            <input class="form-control" type="date" name="due_date">
          </div>
        </div>

        <div class="table-responsive mt-3">
          <table class="table table-borderless table-sm table-striped table-hover table-brand align-middle" id="itemsTable">
            <thead>
              <tr class="text-muted small">
                <th style="width:45%">Item</th>
                <th style="width:15%">Qty</th>
                <th style="width:20%">Price</th>
                <th style="width:20%">Total</th>
                <th style="width:5%"></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <button type="button" class="btn btn-outline-light btn-sm" id="addItem"><i class="bi bi-plus-lg"></i> Add Item</button>

        <div class="row g-3 mt-3">
          <div class="col-lg-4">
            <label class="form-label">Subtotal</label>
            <input class="form-control" id="subtotal" name="subtotal_display" readonly>
          </div>
          <div class="col-lg-4">
            <label class="form-label">Tax</label>
            <input class="form-control" type="number" step="0.01" id="tax" name="tax" value="0">
          </div>
          <div class="col-lg-4">
            <label class="form-label">Total</label>
            <input class="form-control" id="total" name="total_display" readonly>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" placeholder="Optional notes"></textarea>
        </div>

        <input type="hidden" name="items" id="itemsJson">
        <div class="mt-3 d-flex gap-2">
          <a href="invoices.php" class="btn btn-outline-light">Cancel</a>
          <button class="btn btn-primary">Save Invoice</button>
        </div>
      </form>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script>
const CLIENT_MAP = new Map(<?php echo json_encode(array_map(fn($c)=>[$c['full_name'],(int)$c['id']], $clientsArr), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>);
const clientNameInv = document.getElementById('clientNameInv');
const clientIdInv = document.getElementById('clientIdInv');
function syncClientIdInv(){
  const name = (clientNameInv.value||'').trim();
  const id = CLIENT_MAP.get(name) || '';
  clientIdInv.value = id;
}
clientNameInv.addEventListener('change', syncClientIdInv);
clientNameInv.addEventListener('input', syncClientIdInv);
const tbody = document.querySelector('#itemsTable tbody');
const addItemBtn = document.getElementById('addItem');
const subtotalEl = document.getElementById('subtotal');
const taxEl = document.getElementById('tax');
const totalEl = document.getElementById('total');
const itemsJson = document.getElementById('itemsJson');

function addRow(name='', qty=1, price=0) {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="form-control" placeholder="Description" value="${name}"></td>
    <td><input class="form-control" type="number" min="1" value="${qty}"></td>
    <td><input class="form-control" type="number" step="0.01" min="0" value="${price}"></td>
    <td class="text-end fw-semibold">${window.currencySymbol}0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></td>
  `;
  tbody.appendChild(tr);
  const inputs = tr.querySelectorAll('input');
  const delBtn = tr.querySelector('button');
  function recalc() {
    const q = Number(inputs[1].value||0);
    const p = Number(inputs[2].value||0);
    const line = q*p;
    tr.children[3].textContent = `${window.currencySymbol}${line.toFixed(2)}`;
    computeTotals();
  }
  inputs.forEach(i=>i.addEventListener('input', recalc));
  delBtn.addEventListener('click', ()=>{ tr.remove(); computeTotals(); });
  recalc();
}

function computeTotals() {
  let subtotal = 0;
  const items = [];
  Array.from(tbody.children).forEach(tr=>{
    const name = tr.children[0].querySelector('input').value.trim();
    const qty = Number(tr.children[1].querySelector('input').value||0);
    const price = Number(tr.children[2].querySelector('input').value||0);
    if (name && qty>0) {
      subtotal += qty*price;
      items.push({name, qty, price});
    }
  });
  subtotalEl.value = `${window.currencySymbol}${subtotal.toFixed(2)}`;
  const tax = Number(taxEl.value||0);
  totalEl.value = `${window.currencySymbol}${(subtotal+tax).toFixed(2)}`;
  itemsJson.value = JSON.stringify(items);
}

addItemBtn.addEventListener('click', ()=>addRow());
taxEl.addEventListener('input', computeTotals);
addRow();

document.getElementById('invoiceForm').addEventListener('submit', (e)=>{
  const items = JSON.parse(itemsJson.value||'[]');
  if (items.length === 0) {
    e.preventDefault();
    alert('Add at least one item');
  }
});
</script>
<script src="js/ui.js"></script>
</body>
</html>
