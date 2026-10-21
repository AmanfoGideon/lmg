<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/suppliers.php";

// AJAX endpoint for loading outstanding bills
if (isset($_GET['get_bills']) && isset($_GET['supplier_id'])) {
  header('Content-Type: application/json');
  $supplier_id = (int)$_GET['supplier_id'];
  $bills = supplier_bill_list($conn, $supplier_id, 'unpaid', '', 100, 0);
  $outstanding_bills = [];
  foreach ($bills as $bill) {
    $allocations = supplier_bill_get_allocations($conn, (int)$bill['id']);
    $paid = array_sum(array_column($allocations, 'amount'));
    $balance = (float)$bill['total'] - $paid;
    if ($balance > 0) {
      $outstanding_bills[] = [
        'id' => (int)$bill['id'],
        'bill_number' => $bill['bill_number'],
        'total' => (float)$bill['total'],
        'balance' => $balance
      ];
    }
  }
  echo json_encode($outstanding_bills);
  exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
      try {
        $payment = [
          'supplier_id' => $_POST['supplier_id'] ?? 0,
          'payment_number' => $_POST['payment_number'] ?? '',
          'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
          'amount' => $_POST['amount'] ?? 0,
          'payment_method' => $_POST['payment_method'] ?? 'cash',
          'reference_number' => $_POST['reference_number'] ?? '',
          'description' => $_POST['description'] ?? '',
          'notes' => $_POST['notes'] ?? ''
        ];
        supplier_payment_create($conn, $payment);
        $message = 'Payment created successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Payment could not be created. Please try again.';
        error_log('Payment creation failed: ' . $e->getMessage());
      }
    } elseif ($action === 'update') {
      try {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $payment = [
          'supplier_id' => $_POST['supplier_id'] ?? 0,
          'payment_number' => $_POST['payment_number'] ?? '',
          'payment_date' => $_POST['payment_date'] ?? '',
          'amount' => $_POST['amount'] ?? 0,
          'payment_method' => $_POST['payment_method'] ?? 'cash',
          'reference_number' => $_POST['reference_number'] ?? '',
          'description' => $_POST['description'] ?? '',
          'notes' => $_POST['notes'] ?? ''
        ];
        supplier_payment_update($conn, $payment_id, $payment);
        $message = 'Payment updated successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Payment could not be updated. Please try again.';
        error_log('Payment update failed: ' . $e->getMessage());
      }
    } elseif ($action === 'cancel') {
      try {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['cancellation_reason'] ?? '');
        supplier_payment_cancel($conn, $payment_id, $reason);
        $message = 'Payment cancelled successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Payment could not be cancelled. Please try again.';
        error_log('Payment cancellation failed: ' . $e->getMessage());
      }
    } elseif ($action === 'allocate') {
      try {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        $amount = (float)($_POST['allocation_amount'] ?? 0);
        supplier_payment_allocate($conn, $payment_id, $bill_id, $amount);
        $message = 'Payment allocated successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Payment could not be allocated. Please try again.';
        error_log('Payment allocation failed: ' . $e->getMessage());
      }
    } elseif ($action === 'remove_allocation') {
      try {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        supplier_payment_remove_allocation($conn, $payment_id, $bill_id);
        $message = 'Allocation removed successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Allocation could not be removed. Please try again.';
        error_log('Allocation removal failed: ' . $e->getMessage());
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

$payments = supplier_payment_list($conn, $supplier_filter > 0 ? $supplier_filter : null, $status_filter, $search, $limit, $offset);
$total_payments = supplier_payment_count($conn, $supplier_filter > 0 ? $supplier_filter : null, $status_filter, $search);
$total_pages = ceil($total_payments / $limit);

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
  <title>Supplier Payments — LMG Creative Media</title>
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
    <?php $page_title='Supplier Payments'; $page_subtitle='Manage supplier payments and allocations'; $header_actions='<a class="btn btn-outline-light me-2" href="suppliers.php">Suppliers</a><a class="btn btn-outline-light me-2" href="supplier_bills.php">Bills</a>'; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="paymentForm" class="card p-3">
          <h6 class="mb-3" id="formTitle">Add New Payment</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="payment_id" id="payment_id" value="">
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
              <label class="form-label">Payment Number</label>
              <input class="form-control" name="payment_number" id="payment_number" placeholder="Auto-generated if empty">
            </div>
            <div class="col-6">
              <label class="form-label">Payment Date *</label>
              <input class="form-control" type="date" name="payment_date" id="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label">Amount *</label>
              <input class="form-control" type="number" step="0.01" name="amount" id="amount" required>
            </div>
            <div class="col-6">
              <label class="form-label">Payment Method *</label>
              <select class="form-select" name="payment_method" id="payment_method" required>
                <option value="cash">Cash</option>
                <option value="mobile">Mobile</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Reference Number</label>
              <input class="form-control" name="reference_number" id="reference_number">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" id="description" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" id="notes" rows="2"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary flex-grow-1" type="submit" id="submitBtn">Add Payment</button>
              <button class="btn btn-outline-light" type="button" id="cancelBtn" style="display:none">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="paymentList" class="card p-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Supplier Payments</h6>
            <form method="GET" class="d-flex gap-2">
              <select class="form-select form-select-sm" name="supplier_id">
                <option value="">All Suppliers</option>
                <?php foreach($suppliersArr as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $supplier_filter === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['business_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="form-select form-select-sm" name="status">
                <option value="">All Status</option>
                <option value="unallocated" <?= $status_filter === 'unallocated' ? 'selected' : '' ?>>Unallocated</option>
                <option value="allocated" <?= $status_filter === 'allocated' ? 'selected' : '' ?>>Allocated</option>
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
                  <th>Payment #</th>
                  <th>Supplier</th>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Allocated</th>
                  <th>Balance</th>
                  <th>Method</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($payments)): ?>
                  <tr><td colspan="9" class="text-center text-muted">No payments found</td></tr>
                <?php else: ?>
                  <?php foreach($payments as $payment): ?>
                    <?php 
                      $allocations = supplier_payment_get_allocations($conn, (int)$payment['id']);
                      $allocated = array_sum(array_column($allocations, 'amount'));
                      $balance = (float)$payment['amount'] - $allocated;
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($payment['payment_number']) ?></td>
                      <td><?= htmlspecialchars($payment['business_name']) ?></td>
                      <td><?= htmlspecialchars($payment['payment_date']) ?></td>
                      <td><?= fmtCurrency((float)$payment['amount']) ?></td>
                      <td><?= fmtCurrency($allocated) ?></td>
                      <td class="<?= $balance > 0 ? 'text-warning' : '' ?>"><?= fmtCurrency($balance) ?></td>
                      <td><?= htmlspecialchars($payment['payment_method']) ?></td>
                      <td>
                        <span class="badge bg-<?= $payment['status'] === 'allocated' ? 'success' : ($payment['status'] === 'cancelled' ? 'secondary' : 'warning text-dark') ?>">
                          <?= htmlspecialchars($payment['status']) ?>
                        </span>
                      </td>
                      <td class="d-flex gap-2">
                        <?php if ($payment['status'] !== 'cancelled'): ?>
                          <button type="button" class="btn btn-sm btn-outline-light btn-edit" 
                            data-id="<?= (int)$payment['id'] ?>"
                            data-supplier_id="<?= (int)$payment['supplier_id'] ?>"
                            data-payment_number="<?= htmlspecialchars($payment['payment_number'], ENT_QUOTES) ?>"
                            data-payment_date="<?= htmlspecialchars($payment['payment_date'], ENT_QUOTES) ?>"
                            data-amount="<?= htmlspecialchars($payment['amount'], ENT_QUOTES) ?>"
                            data-payment_method="<?= htmlspecialchars($payment['payment_method'], ENT_QUOTES) ?>"
                            data-reference_number="<?= htmlspecialchars($payment['reference_number'] ?? '', ENT_QUOTES) ?>"
                            data-description="<?= htmlspecialchars($payment['description'] ?? '', ENT_QUOTES) ?>"
                            data-notes="<?= htmlspecialchars($payment['notes'] ?? '', ENT_QUOTES) ?>">
                            <i class="bi bi-pencil-square"></i>
                          </button>
                          <?php if ($balance > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-success btn-allocate" 
                              data-id="<?= (int)$payment['id'] ?>"
                              data-supplier-id="<?= (int)$payment['supplier_id'] ?>"
                              data-balance="<?= $balance ?>">
                              <i class="bi bi-link-45deg"></i>
                            </button>
                          <?php endif; ?>
                          <button type="button" class="btn btn-sm btn-outline-danger btn-cancel" 
                            data-id="<?= (int)$payment['id'] ?>"
                            data-payment-number="<?= htmlspecialchars($payment['payment_number'], ENT_QUOTES) ?>">
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

<!-- Allocate Payment Modal -->
<div class="modal fade" id="allocateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" id="allocateForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="allocate">
        <input type="hidden" name="payment_id" id="allocate_payment_id">
        <div class="modal-header">
          <h5 class="modal-title">Allocate Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Available Balance</label>
            <input class="form-control" type="text" id="available_balance" readonly>
          </div>
          <div class="mb-2">
            <label class="form-label">Select Bill</label>
            <select class="form-select" name="bill_id" id="allocate_bill_id" required>
              <option value="">Select Outstanding Bill</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Allocation Amount</label>
            <input class="form-control" type="number" step="0.01" name="allocation_amount" id="allocation_amount" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Allocate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('#paymentForm form');
  const formTitle = document.getElementById('formTitle');
  const submitBtn = document.getElementById('submitBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const paymentIdInput = document.getElementById('payment_id');
  const actionInput = document.querySelector('input[name="action"]');

  document.querySelectorAll('.btn-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const data = this.dataset;
      paymentIdInput.value = data.id;
      document.getElementById('supplier_id').value = data.supplier_id;
      document.getElementById('payment_number').value = data.payment_number;
      document.getElementById('payment_date').value = data.payment_date;
      document.getElementById('amount').value = data.amount;
      document.getElementById('payment_method').value = data.payment_method;
      document.getElementById('reference_number').value = data.reference_number;
      document.getElementById('description').value = data.description;
      document.getElementById('notes').value = data.notes;
      
      formTitle.textContent = 'Edit Payment';
      submitBtn.textContent = 'Update Payment';
      actionInput.value = 'update';
      cancelBtn.style.display = 'inline-block';
      
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  cancelBtn.addEventListener('click', function() {
    form.reset();
    paymentIdInput.value = '';
    formTitle.textContent = 'Add New Payment';
    submitBtn.textContent = 'Add Payment';
    actionInput.value = 'create';
    cancelBtn.style.display = 'none';
  });

  document.querySelectorAll('.btn-cancel').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const paymentNumber = this.dataset.paymentNumber;
      const reason = prompt('Enter cancellation reason:');
      if (reason === null || reason.trim() === '') {
        return;
      }
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = `
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="payment_id" value="${this.dataset.id}">
        <input type="hidden" name="cancellation_reason" value="${reason.trim()}">
      `;
      document.body.appendChild(form);
      form.submit();
    });
  });

  // Allocation modal
  const allocateModalEl = document.getElementById('allocateModal');
  const allocateBsModal = new bootstrap.Modal(allocateModalEl);
  const allocateForm = document.getElementById('allocateForm');

  document.querySelectorAll('.btn-allocate').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const paymentId = this.dataset.id;
      const supplierId = this.dataset.supplierId;
      const balance = parseFloat(this.dataset.balance);
      
      document.getElementById('allocate_payment_id').value = paymentId;
      document.getElementById('available_balance').value = balance.toFixed(2);
      document.getElementById('allocation_amount').value = balance.toFixed(2);
      
      // Load outstanding bills for this supplier
      fetch(`?get_bills=1&supplier_id=${supplierId}`)
        .then(response => response.json())
        .then(bills => {
          const select = document.getElementById('allocate_bill_id');
          select.innerHTML = '<option value="">Select Outstanding Bill</option>';
          bills.forEach(bill => {
            select.innerHTML += `<option value="${bill.id}">${bill.bill_number} - ${bill.total} (Balance: ${bill.balance})</option>`;
          });
        });
      
      allocateBsModal.show();
    });
  });
});
</script>
<script src="js/ui.js"></script>
</body>
</html>
