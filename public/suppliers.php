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
        $supplier = [
          'business_name' => $_POST['business_name'] ?? '',
          'contact_person' => $_POST['contact_person'] ?? '',
          'phone' => $_POST['phone'] ?? '',
          'email' => $_POST['email'] ?? '',
          'address' => $_POST['address'] ?? '',
          'category' => $_POST['category'] ?? '',
          'tax_id' => $_POST['tax_id'] ?? '',
          'notes' => $_POST['notes'] ?? '',
          'status' => 'active'
        ];
        supplier_create($conn, $supplier);
        $message = 'Supplier created successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Supplier could not be created. Please try again.';
        error_log('Supplier creation failed: ' . $e->getMessage());
      }
    } elseif ($action === 'update') {
      try {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $supplier = [
          'business_name' => $_POST['business_name'] ?? '',
          'contact_person' => $_POST['contact_person'] ?? '',
          'phone' => $_POST['phone'] ?? '',
          'email' => $_POST['email'] ?? '',
          'address' => $_POST['address'] ?? '',
          'category' => $_POST['category'] ?? '',
          'tax_id' => $_POST['tax_id'] ?? '',
          'notes' => $_POST['notes'] ?? '',
          'status' => $_POST['status'] ?? 'active'
        ];
        supplier_update($conn, $supplier_id, $supplier);
        $message = 'Supplier updated successfully';
      } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
      } catch (Throwable $e) {
        $message = 'Supplier could not be updated. Please try again.';
        error_log('Supplier update failed: ' . $e->getMessage());
      }
    } elseif ($action === 'delete') {
      try {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        if ($supplier_id <= 0) throw new InvalidArgumentException('Invalid supplier ID.');
        
        // Check for financial history
        $check = $conn->prepare("SELECT
          (SELECT COUNT(*) FROM supplier_bills WHERE supplier_id=?) +
          (SELECT COUNT(*) FROM supplier_payments WHERE supplier_id=?) +
          (SELECT COUNT(*) FROM transactions WHERE supplier_id=?) AS count");
        $check->bind_param('iii', $supplier_id, $supplier_id, $supplier_id);
        $check->execute();
        $bill_count = (int)$check->get_result()->fetch_assoc()['count'];
        $check->close();
        
        if ($bill_count > 0) {
          throw new InvalidArgumentException('Cannot delete supplier with financial history. Deactivate instead.');
        }
        
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id=?");
        $stmt->bind_param('i', $supplier_id);
        $stmt->execute();
        $stmt->close();
        
        log_action($conn, 'supplier_delete', (string)$supplier_id, [
          'entity_id' => (string)$supplier_id
        ]);
        $conn->commit();
        $message = 'Supplier deleted successfully';
      } catch (InvalidArgumentException $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $message = $e->getMessage();
      } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $message = 'Supplier could not be deleted. Please try again.';
        error_log('Supplier deletion failed: ' . $e->getMessage());
      }
    }
  }
}

$search = is_string($_GET['search'] ?? null) ? mb_substr(trim($_GET['search']), 0, 120) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$total_suppliers = supplier_count($conn, $search);
$total_pages = max(1, (int)ceil($total_suppliers / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$suppliers = supplier_list($conn, $search, $limit, $offset);

$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Suppliers — LMG Creative Media</title>
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
    <?php $page_title='Suppliers'; $page_subtitle='Manage suppliers and accounts payable'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="supplierForm" class="card p-3">
          <h6 class="mb-3" id="formTitle">Add New Supplier</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="supplier_id" id="supplier_id" value="">
            <div class="col-12">
              <label class="form-label">Business Name *</label>
              <input class="form-control" name="business_name" id="business_name" required>
            </div>
            <div class="col-6">
              <label class="form-label">Contact Person</label>
              <input class="form-control" name="contact_person" id="contact_person">
            </div>
            <div class="col-6">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone" id="phone">
            </div>
            <div class="col-12">
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" id="email">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea class="form-control" name="address" id="address" rows="2"></textarea>
            </div>
            <div class="col-6">
              <label class="form-label">Category</label>
              <input class="form-control" name="category" id="category" placeholder="e.g., IT Services, Office Supplies">
            </div>
            <div class="col-6">
              <label class="form-label">Tax ID</label>
              <input class="form-control" name="tax_id" id="tax_id">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" id="notes" rows="2"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary flex-grow-1" type="submit" id="submitBtn">Add Supplier</button>
              <button class="btn btn-outline-light" type="button" id="cancelBtn" style="display:none">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="supplierList" class="card p-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Suppliers</h6>
            <form method="GET" class="d-flex gap-2">
              <input class="form-control form-control-sm" type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
              <button class="btn btn-sm btn-outline-light" type="submit">Search</button>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand">
              <thead>
                <tr class="text-muted small">
                  <th>Business Name</th>
                  <th>Contact Person</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th title="Posted expense transactions linked to this supplier">Total Amount Spent</th>
                  <th>Bill Purchases</th>
                  <th>Paid</th>
                  <th>Balance</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($suppliers)): ?>
                  <tr><td colspan="10" class="text-center text-muted">No suppliers found</td></tr>
                <?php else: ?>
                  <?php foreach($suppliers as $supplier): ?>
                    <tr>
                      <td><?= htmlspecialchars($supplier['business_name']) ?></td>
                      <td><?= htmlspecialchars($supplier['contact_person'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($supplier['phone'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($supplier['email'] ?? '—') ?></td>
                      <td><?= fmtCurrency((float)$supplier['total_spent']) ?></td>
                      <td><?= fmtCurrency((float)$supplier['total_purchases']) ?></td>
                      <td><?= fmtCurrency((float)$supplier['total_paid']) ?></td>
                      <td class="<?= (float)$supplier['outstanding_balance'] > 0 ? 'text-danger' : '' ?>"><?= fmtCurrency((float)$supplier['outstanding_balance']) ?></td>
                      <td>
                        <span class="badge bg-<?= $supplier['status'] === 'active' ? 'success' : 'secondary' ?>">
                          <?= htmlspecialchars($supplier['status']) ?>
                        </span>
                      </td>
                      <td class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-info" href="supplier_statement.php?supplier_id=<?= (int)$supplier['id'] ?>" title="Supplier statement"><i class="bi bi-journal-text"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-light btn-edit" 
                          data-id="<?= (int)$supplier['id'] ?>"
                          data-business_name="<?= htmlspecialchars($supplier['business_name'], ENT_QUOTES) ?>"
                          data-contact_person="<?= htmlspecialchars($supplier['contact_person'] ?? '', ENT_QUOTES) ?>"
                          data-phone="<?= htmlspecialchars($supplier['phone'] ?? '', ENT_QUOTES) ?>"
                          data-email="<?= htmlspecialchars($supplier['email'] ?? '', ENT_QUOTES) ?>"
                          data-address="<?= htmlspecialchars($supplier['address'] ?? '', ENT_QUOTES) ?>"
                          data-category="<?= htmlspecialchars($supplier['category'] ?? '', ENT_QUOTES) ?>"
                          data-tax_id="<?= htmlspecialchars($supplier['tax_id'] ?? '', ENT_QUOTES) ?>"
                          data-notes="<?= htmlspecialchars($supplier['notes'] ?? '', ENT_QUOTES) ?>"
                          data-status="<?= htmlspecialchars($supplier['status'], ENT_QUOTES) ?>">
                          <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="POST" class="d-inline-block" onsubmit="return confirm('Delete this supplier?');">
                          <?= csrf_input() ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="supplier_id" value="<?= (int)$supplier['id'] ?>">
                          <button class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                        </form>
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
                    <a class="page-link" href="?search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
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
  const form = document.querySelector('#supplierForm form');
  const formTitle = document.getElementById('formTitle');
  const submitBtn = document.getElementById('submitBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const supplierIdInput = document.getElementById('supplier_id');
  const actionInput = document.querySelector('input[name="action"]');

  document.querySelectorAll('.btn-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const data = this.dataset;
      supplierIdInput.value = data.id;
      document.getElementById('business_name').value = data.business_name;
      document.getElementById('contact_person').value = data.contact_person;
      document.getElementById('phone').value = data.phone;
      document.getElementById('email').value = data.email;
      document.getElementById('address').value = data.address;
      document.getElementById('category').value = data.category;
      document.getElementById('tax_id').value = data.tax_id;
      document.getElementById('notes').value = data.notes;
      
      formTitle.textContent = 'Edit Supplier';
      submitBtn.textContent = 'Update Supplier';
      actionInput.value = 'update';
      cancelBtn.style.display = 'inline-block';
      
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  cancelBtn.addEventListener('click', function() {
    form.reset();
    supplierIdInput.value = '';
    formTitle.textContent = 'Add New Supplier';
    submitBtn.textContent = 'Add Supplier';
    actionInput.value = 'create';
    cancelBtn.style.display = 'none';
  });
});
</script>
<script src="js/ui.js"></script>
</body>
</html>
