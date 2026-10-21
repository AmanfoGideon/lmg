<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/invoice.php";

$invoiceId = filter_var($_POST['invoice_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$invoiceId) {
  http_response_code(400);
  exit('Invalid invoice.');
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!csrf_verify()) throw new InvalidArgumentException('Invalid request token.');
    $invoiceDate = (string)($_POST['invoice_date'] ?? '');
    $dueDate = (string)($_POST['due_date'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    if ($clientId <= 0 || !invoice_valid_date($invoiceDate) || !invoice_valid_date($dueDate, true)) {
      throw new InvalidArgumentException('Select a valid client and invoice dates.');
    }
    $calculation = invoice_calculate(is_array($_POST['items'] ?? null) ? $_POST['items'] : [], $_POST['tax'] ?? '0');
    invoice_update($conn, (int)$invoiceId, [
      'client_id'=>$clientId,
      'invoice_date'=>$invoiceDate,
      'due_date'=>$dueDate,
      'notes'=>trim((string)($_POST['notes'] ?? '')),
    ], $calculation, ($_POST['confirm_total_change'] ?? '') === '1');
    header('Location: invoice_view.php?id='.(int)$invoiceId.'&updated=1');
    exit;
  } catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
  } catch (Throwable $e) {
    error_log('Invoice update failed: '.$e->getMessage());
    $message = 'Invoice could not be updated. Please try again.';
  }
}

$find = $conn->prepare('SELECT id, invoice_number, client_id, invoice_date, due_date, tax, total, notes, cancelled_at FROM invoices WHERE id=?');
$find->bind_param('i', $invoiceId);
$find->execute();
$invoice = $find->get_result()->fetch_assoc();
$find->close();
if (!$invoice) {
  http_response_code(404);
  exit('Invoice not found.');
}
$editBlocked = $invoice['cancelled_at'] !== null;
$clients = $conn->query('SELECT id, full_name FROM clients ORDER BY full_name');
$itemsStmt = $conn->prepare('SELECT item_name, quantity, price FROM invoice_items WHERE invoice_id=? ORDER BY id');
$itemsStmt->bind_param('i', $invoiceId);
$itemsStmt->execute();
$itemRows = $itemsStmt->get_result();
$items = [];
while ($item = $itemRows->fetch_assoc()) $items[] = $item;
$itemsStmt->close();
$userName = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> - LMG</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='invoices'; include "../includes/layout/sidebar.php"; ?>
  <main class="main flex-grow-1 p-4">
    <?php $page_title='Edit Invoice'; $page_subtitle=$invoice['invoice_number']; $header_actions=''; include "../includes/layout/header.php"; ?>
    <?php if ($message): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($editBlocked): ?>
      <div class="alert alert-warning" role="alert">This invoice was cancelled and is read-only to preserve its financial history.</div>
      <a class="btn btn-outline-light" href="invoice_view.php?id=<?= (int)$invoiceId ?>">View invoice</a>
    <?php else: ?>
    <form method="POST" class="card p-3" id="editInvoiceForm" data-original-total-cents="<?= (int)(invoice_money_cents((string)$invoice['total']) ?? 0) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="invoice_id" value="<?= (int)$invoiceId ?>">
      <input type="hidden" name="confirm_total_change" id="confirmTotalChange" value="0">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="client_id">Client</label><select class="form-select" id="client_id" name="client_id" required><?php while($client=$clients->fetch_assoc()): ?><option value="<?= (int)$client['id'] ?>" <?= (int)$client['id']===(int)$invoice['client_id']?'selected':'' ?>><?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endwhile; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="invoice_date">Invoice date</label><input class="form-control" id="invoice_date" name="invoice_date" type="date" value="<?= htmlspecialchars($invoice['invoice_date'], ENT_QUOTES, 'UTF-8') ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="due_date">Due date</label><input class="form-control" id="due_date" name="due_date" type="date" value="<?= htmlspecialchars($invoice['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>
      <div class="table-responsive mt-3"><table class="table table-brand"><thead><tr><th>Description</th><th>Quantity</th><th>Unit price</th><th></th></tr></thead><tbody id="editInvoiceItems">
        <?php foreach($items as $index=>$item): ?><tr><td><input class="form-control" name="items[<?= (int)$index ?>][name]" maxlength="255" value="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>" required></td><td><input class="form-control" name="items[<?= (int)$index ?>][qty]" type="number" min="1" step="1" value="<?= (int)$item['quantity'] ?>" required></td><td><input class="form-control" name="items[<?= (int)$index ?>][price]" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)$item['price'], ENT_QUOTES, 'UTF-8') ?>" required></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-item" aria-label="Remove invoice item">Remove</button></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <button type="button" class="btn btn-sm btn-outline-light align-self-start" id="addInvoiceItem">Add item</button>
      <div class="row g-3 mt-1"><div class="col-md-3"><label class="form-label" for="tax">Tax</label><input class="form-control" id="tax" name="tax" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string)$invoice['tax'], ENT_QUOTES, 'UTF-8') ?>" required></div><div class="col-md-9"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars($invoice['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></div></div>
      <div class="d-flex gap-2 mt-3"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-outline-light" href="invoice_view.php?id=<?= (int)$invoiceId ?>">Cancel</a></div>
    </form>
    <?php endif; ?>
  </main>
</div>
<script>
(() => {
  const rows = document.getElementById('editInvoiceItems');
  if (!rows) return;
  let nextIndex = rows.children.length;
  function bindRemove(button) { button.addEventListener('click', () => { if (rows.children.length > 1) button.closest('tr').remove(); }); }
  rows.querySelectorAll('.remove-item').forEach(bindRemove);
  document.getElementById('addInvoiceItem').addEventListener('click', () => {
    const row = document.createElement('tr');
    row.innerHTML = `<td><input class="form-control" name="items[${nextIndex}][name]" maxlength="255" required></td><td><input class="form-control" name="items[${nextIndex}][qty]" type="number" min="1" step="1" value="1" required></td><td><input class="form-control" name="items[${nextIndex}][price]" type="number" min="0" step="0.01" value="0.00" required></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-item" aria-label="Remove invoice item">Remove</button></td>`;
    bindRemove(row.querySelector('.remove-item'));
    rows.append(row);
    nextIndex++;
  });
  document.getElementById('editInvoiceForm').addEventListener('submit', (event) => {
    if (!rows.children.length) { event.preventDefault(); alert('Add at least one invoice item.'); return; }
    const form = event.currentTarget;
    const toCents = value => Math.round(Number(value || 0) * 100);
    const subtotalCents = [...rows.querySelectorAll('tr')].reduce((sum, row) => {
      return sum + Number(row.querySelector('[name$="[qty]"]').value || 0) * toCents(row.querySelector('[name$="[price]"]').value);
    }, 0);
    const nextTotalCents = subtotalCents + toCents(form.querySelector('[name="tax"]').value);
    const originalTotalCents = Number(form.dataset.originalTotalCents || 0);
    const confirmation = document.getElementById('confirmTotalChange');
    if (nextTotalCents !== originalTotalCents) {
      if (!window.confirm('This edit changes the invoice total. Review the revised amount owed and payment balance before continuing.')) {
        event.preventDefault();
        return;
      }
      confirmation.value = '1';
    }
    const button = event.currentTarget.querySelector('[type="submit"]');
    button.disabled = true;
    button.textContent = 'Saving...';
  });
})();
</script>
</body>
</html>
