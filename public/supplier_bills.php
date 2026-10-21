<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/suppliers.php";

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
      try {
        $bill = [
          'supplier_id' => $_POST['supplier_id'] ?? 0,
          'bill_number' => $_POST['bill_number'] ?? '',
          'bill_date' => $_POST['bill_date'] ?? date('Y-m-d'),
          'due_date' => $_POST['due_date'] ?? '',
          'description' => $_POST['description'] ?? '',
          'category' => $_POST['category'] ?? '',
          'subtotal' => $_POST['subtotal'] ?? 0,
          'tax' => $_POST['tax'] ?? 0,
          'total' => $_POST['total'] ?? 0,
          'payment_terms' => $_POST['payment_terms'] ?? '',
          'notes' => $_POST['notes'] ?? ''
        ];
        supplier_bill_create($conn, $bill);
        $message = 'Bill created successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Bill could not be created. Please try again.';
        error_log('Bill creation failed: ' . $e->getMessage());
      }
    } elseif ($action === 'update') {
      try {
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        $bill = [
          'supplier_id' => $_POST['supplier_id'] ?? 0,
          'bill_number' => $_POST['bill_number'] ?? '',
          'bill_date' => $_POST['bill_date'] ?? '',
          'due_date' => $_POST['due_date'] ?? '',
          'description' => $_POST['description'] ?? '',
          'category' => $_POST['category'] ?? '',
          'subtotal' => $_POST['subtotal'] ?? 0,
          'tax' => $_POST['tax'] ?? 0,
          'total' => $_POST['total'] ?? 0,
          'payment_terms' => $_POST['payment_terms'] ?? '',
          'notes' => $_POST['notes'] ?? ''
        ];
        supplier_bill_update($conn, $bill_id, $bill);
        $message = 'Bill updated successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Bill could not be updated. Please try again.';
        error_log('Bill update failed: ' . $e->getMessage());
      }
    } elseif ($action === 'cancel') {
      try {
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        $reason = trim($_POST['cancellation_reason'] ?? '');
        supplier_bill_cancel($conn, $bill_id, $reason);
        $message = 'Bill cancelled successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Bill could not be cancelled. Please try again.';
        error_log('Bill cancellation failed: ' . $e->getMessage());
      }
    }
  }
}

$supplier_filter = (int)($_GET['supplier_id'] ?? 0);
$status_filter = (string)($_GET['status'] ?? '');
$search = (string)($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$bills = supplier_bill_list($conn, $supplier_filter > 0 ? $supplier_filter : null, $status_filter, $search, $limit, $offset);
$total_bills = supplier_bill_count($conn, $supplier_filter > 0 ? $supplier_filter : null, $status_filter, $search);
$total_pages = ceil($total_bills / $limit);

$suppliers = $conn->query("SELECT id, business_name FROM suppliers WHERE status='active' ORDER BY business_name ASC");
$suppliersArr = [];
while ($sRow = $suppliers->fetch_assoc()) { $suppliersArr[] = $sRow; }

$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Supplier Bills — LMG Creative Media</title>
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
  <?php $active_page='suppliers'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Supplier Bills'; $page_subtitle='Manage supplier bills and accounts payable'; $header_actions='<a class="btn btn-outline-light me-2" href="suppliers.php">Suppliers</a>'; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="billForm" class="card p-3">
          <h6 class="mb-3" id="formTitle">Add New Bill</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="bill_id" id="bill_id" value="">
            <div class="col-12">
              <label class="form-label">Supplier *</label>
              <select class="form-select" name="supplier_id" id="supplier_id" required>
                <option value="">Select Supplier</option>
                <?php foreach($suppliersArr as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['business_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Bill Number</label>
              <input class="form-control" name="bill_number" id="bill_number" placeholder="Auto-generated if empty">
            </div>
            <div class="col-6">
              <label class="form-label">Bill Date *</label>
              <input class="form-control" type="date" name="bill_date" id="bill_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label">Due Date</label>
              <input class="form-control" type="date" name="due_date" id="due_date">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" id="description" rows="2"></textarea>
            </div>
            <div class="col-6">
              <label class="form-label">Category</label>
              <input class="form-control" name="category" id="category" placeholder="e.g., Office Supplies">
            </div>
            <div class="col-6">
              <label class="form-label">Payment Terms</label>
              <input class="form-control" name="payment_terms" id="payment_terms" placeholder="e.g., Net 30">
            </div>
            <div class="row g-2 col-12">
              <div class="col-4">
                <label class="form-label">Subtotal</label>
                <input class="form-control" type="number" step="0.01" name="subtotal" id="subtotal" value="0" required>
              </div>
              <div class="col-4">
                <label class="form-label">Tax</label>
                <input class="form-control" type="number" step="0.01" name="tax" id="tax" value="0" required>
              </div>
              <div class="col-4">
                <label class="form-label">Total</label>
                <input class="form-control" type="number" step="0.01" name="total" id="total" value="0" required readonly>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" id="notes" rows="2"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary flex-grow-1" type="submit" id="submitBtn">Add Bill</button>
              <button class="btn btn-outline-light" type="button" id="cancelBtn" style="display:none">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="billList" class="card p-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Supplier Bills</h6>
            <form method="GET" class="d-flex gap-2">
              <select class="form-select form-select-sm" name="supplier_id">
                <option value="">All Suppliers</option>
                <?php foreach($suppliersArr as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $supplier_filter === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['business_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="form-select form-select-sm" name="status">
                <option value="">All Status</option>
                <option value="unpaid" <?= $status_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              </select>
              <input class="form-control form-control-sm" type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
              <button class="btn btn-sm btn-outline-light" type="submit">Filter</button>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable">
              <thead>
                <tr class="text-muted small">
                  <th>Bill #</th>
>
                  <th>Supplier</th>
                  <th>Date</th>
                  <th>Due Date</th>
                  <th>Description</th>
                  <th>Total</th>
                  <th>Paid</th>
                  <th>Balance</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($bills)): ?>
                  <tr><td colspan="10" class="text-center text-muted">No bills found</td></tr>
                <?php else: ?>
                  <?php foreach($bills as $bill): ?>
                    <?php 
                      $allocations = supplier_bill_get_allocations($conn, (int)$bill['id']);
                      $paidCents = array_sum(array_map(static fn($allocation) => supplier_amount_cents($allocation['amount']), $allocations));
                      $balanceCents = supplier_amount_cents($bill['total']) - $paidCents;
                      $paid = $paidCents / 100;
                      $balance = $balanceCents / 100;
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($bill['bill_number']) ?></td>
                      <td><?= htmlspecialchars($bill['business_name']) ?></td>
                      <td><?= htmlspecialchars($bill['bill_date']) ?></td>
                      <td><?= htmlspecialchars($bill['due_date'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($bill['description'] ?? '—') ?></td>
                      <td><?= fmtCurrency((float)$bill['total']) ?></td>
                      <td><?= fmtCurrency($paid) ?></td>
                      <td class="<?= $balance > 0 ? 'text-danger' : '' ?>"><?= fmtCurrency($balance) ?></td>
                      <td>
                        <span class="badge bg-<?= $bill['status'] === 'paid' ? 'success' : ($bill['status'] === 'partial' ? 'warning text-dark' : ($bill['status'] === 'overdue' ? 'danger' : ($bill['status'] === 'cancelled' ? 'secondary' : 'secondary'))) ?>">
                          <?= htmlspecialchars($bill['status']) ?>
                        </span>
                      </td>
                      <td class="d-flex gap-2">
                        <?php if ($bill['status'] !== 'cancelled'): ?>
                          <button type="button" class="btn btn-sm btn-outline-light btn-edit" 
                            data-id="<?= (int)$bill['id'] ?>"
                            data-supplier_id="<?= (int)$bill['supplier_id'] ?>"
                            data-bill_number="<?= htmlspecialchars($bill['bill_number'], ENT_QUOTES) ?>"
                            data-bill_date="<?= htmlspecialchars($bill['bill_date'], ENT_QUOTES) ?>"
                            data-due_date="<?= htmlspecialchars($bill['due_date'] ?? '', ENT_QUOTES) ?>"
                            data-description="<?= htmlspecialchars($bill['description'] ?? '', ENT_QUOTES) ?>"
                            data-category="<?= htmlspecialchars($bill['category'] ?? '', ENT_QUOTES) ?>"
                            data-subtotal="<?= htmlspecialchars($bill['subtotal'], ENT_QUOTES) ?>"
                            data-tax="<?= htmlspecialchars($bill['tax'], ENT_QUOTES) ?>"
                            data-total="<?= htmlspecialchars($bill['total'], ENT_QUOTES) ?>"
                            data-payment_terms="<?= htmlspecialchars($bill['payment_terms'] ?? '', ENT_QUOTES) ?>"
                            data-notes="<?= htmlspecialchars($bill['notes'] ?? '', ENT_QUOTES) ?>">
                            <i class="bi bi-pencil-square"></i>
                          </button>
                          <button type="button" class="btn btn-sm btn-outline-danger btn-cancel" 
                            data-id="<?= (int)$bill['id'] ?>"
                            data-bill-number="<?= htmlspecialchars($bill['bill_number'], ENT_QUOTES) ?>">
                            <i class="bi bi-x-circle"></i>
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
              <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                  <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?supplier_id=<?= $supplier_filter ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('#billForm form');
  const formTitle = document.getElementById('formTitle');
  const submitBtn = document.getElementById('submitBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const billIdInput = document.getElementById('bill_id');
  const actionInput = document.querySelector('input[name="action"]');
  const subtotalInput = document.getElementById('subtotal');
  const taxInput = document.getElementById('tax');
  const totalInput = document.getElementById('total');

  // Auto-calculate total
  function calculateTotal() {
    const subtotal = parseFloat(subtotalInput.value) || 0;
    const tax = parseFloat(taxInput.value) || 0;
    totalInput.value = (subtotal + tax).toFixed(2);
  }

  subtotalInput.addEventListener('input', calculateTotal);
  taxInput.addEventListener('input', calculateTotal);

  document.querySelectorAll('.btn-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const data = this.dataset;
      billIdInput.value = data.id;
      document.getElementById('supplier_id').value = data.supplier_id;
      document.getElementById('bill_number').value = data.bill_number;
      document.getElementById('bill_date').value = data.bill_date;
      document.getElementById('due_date').value = data.due_date;
      document.getElementById('description').value = data.description;
      document.getElementById('category').value = data.category;
      document.getElementById('subtotal').value = data.subtotal;
      document.getElementById('tax').value = data.tax;
      document.getElementById('total').value = data.total;
      document.getElementById('payment_terms').value = data.payment_terms;
      document.getElementById('notes').value = data.notes;
      
      formTitle.textContent = 'Edit Bill';
      submitBtn.textContent = 'Update Bill';
      actionInput.value = 'update';
      cancelBtn.style.display = 'inline-block';
      
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  cancelBtn.addEventListener('click', function() {
    form.reset();
    billIdInput.value = '';
    formTitle.textContent = 'Add New Bill';
    submitBtn.textContent = 'Add Bill';
    actionInput.value = 'create';
    cancelBtn.style.display = 'none';
    totalInput.value = '0';
  });

  document.querySelectorAll('.btn-cancel').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const billNumber = this.dataset.billNumber;
      const reason = prompt('Enter cancellation reason:');
      if (reason === null || reason.trim() === '') {
        return;
      }
      const form = document.createElement('form');
      form.method = 'POST';
      const csrf = document.querySelector('input[name="_csrf"]');
      [["_csrf", csrf ? csrf.value : ""], ["action", "cancel"], ["bill_id", this.dataset.id], ["cancellation_reason", reason.trim()]].forEach(function(pair) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = pair[0];
        input.value = pair[1];
        form.appendChild(input);
      });
      document.body.appendChild(form);
      form.submit();
    });
  });
});
</script>
<script src="js/ui.js"></script>
</body>
</html>
