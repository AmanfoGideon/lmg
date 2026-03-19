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
    $client_id = (int)$_POST['client_id'];
    $invoice_date = $_POST['invoice_date'] ?: date('Y-m-d');
    $due_date = $_POST['due_date'] ?: null;
    $subtotal = (float)$_POST['subtotal'];
    $tax = (float)$_POST['tax'];
    $total = (float)$_POST['total'];
    $notes = trim($_POST['notes']);
    $nextId = (int)$conn->query("SELECT COALESCE(MAX(id),0)+1 AS next FROM invoices")->fetch_assoc()['next'];
    $invoice_number = "INV-" . date("Ymd", strtotime($invoice_date)) . "-" . str_pad($nextId, 4, "0", STR_PAD_LEFT);
    $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, client_id, invoice_date, due_date, subtotal, tax, total, notes, status) VALUES (?,?,?,?,?,?,?,?, 'unpaid')");
    $stmt->bind_param('sisssdds', $invoice_number, $client_id, $invoice_date, $due_date, $subtotal, $tax, $total, $notes);
    $message = $stmt->execute() ? 'Invoice created' : 'Error creating invoice';
    $stmt->close();
    log_action($conn, 'invoice_create', $invoice_number.'|'.$client_id);
  }
  if ($action === 'update_status') {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    if (!in_array($status, ['unpaid','partial','paid'])) { $status = 'unpaid'; }
    $stmt = $conn->prepare("UPDATE invoices SET status=? WHERE id=?");
    $stmt->bind_param('si', $status, $id);
    $message = $stmt->execute() ? 'Status updated' : 'Error updating status';
    $stmt->close();
    log_action($conn, 'invoice_status', (string)$id.'|'.$status);
  }
  }
}

$clients = $conn->query("SELECT id, full_name FROM clients ORDER BY full_name ASC");
$invoices = $conn->query("SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total, i.status, c.full_name AS client
                          FROM invoices i LEFT JOIN clients c ON c.id=i.client_id
                          ORDER BY i.invoice_date DESC, i.id DESC LIMIT 100");
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
$payload = [];
if (isset($_GET['format']) && $_GET['format'] === 'json') {
  while ($row = $invoices->fetch_assoc()) {
    $payload[] = [
      'id' => (int)$row['id'],
      'invoice_number' => (string)$row['invoice_number'],
      'client' => (string)$row['client'],
      'invoice_date' => (string)$row['invoice_date'],
      'due_date' => (string)$row['due_date'],
      'total' => (float)$row['total'],
      'status' => (string)$row['status'],
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
  <title>Invoices — LMG Creative Media</title>
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
    <?php $page_title='Invoices'; $page_subtitle='Create and manage invoices'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div id="quickActions" class="card p-3">
          <h6 class="mb-3">Quick Actions</h6>
          <a class="btn btn-black w-100" href="invoice_create.php"><i class="bi bi-plus-lg"></i> New Invoice</a>
        </div>
      </div>
      <div class="col-lg-8">
        <div id="recentInvoices" class="card p-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Recent Invoices</h6>
            <a class="btn btn-sm btn-outline-light" href="invoice_create.php"><i class="bi bi-plus-lg"></i> New</a>
          </div>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable">
              <thead>
                <tr class="text-muted small">
                  <th>Invoice #</th>
                  <th>Client</th>
                  <th>Total</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($i = $invoices->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($i['invoice_number']) ?></td>
                  <td><?= htmlspecialchars($i['client']) ?></td>
                  <td><?= fmtCurrency((float)$i['total']) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($i['invoice_date']) ?></td>
                  <td>
                    <span class="badge bg-<?= $i['status']==='paid'?'success':($i['status']==='partial'?'warning text-dark':'secondary') ?>"><?= htmlspecialchars($i['status']) ?></span>
                  </td>
                  <td class="d-flex gap-2 align-items-center">
                    <form method="POST" class="d-flex gap-2 align-items-center">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                      <select name="status" class="form-select form-select-sm" style="width:auto">
                        <option value="unpaid" <?= $i['status']==='unpaid'?'selected':'' ?>>Unpaid</option>
                        <option value="partial" <?= $i['status']==='partial'?'selected':'' ?>>Partial</option>
                        <option value="paid" <?= $i['status']==='paid'?'selected':'' ?>>Paid</option>
                      </select>
                      <button class="btn btn-sm btn-outline-primary">Save</button>
                    </form>
                    <a class="btn btn-sm btn-outline-light" href="invoice_view.php?id=<?= (int)$i['id'] ?>">View</a>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  jQuery(function($){
    $('table.datatable').each(function(){
      const el = this;
      if ($.fn.DataTable.isDataTable(el)) return;
      $(el).DataTable({
        pageLength: 10,
        lengthMenu: [10,25,50,100],
        order: [],
        stateSave: true,
        language: { searchPlaceholder: 'Search...' }
      });
    });
  });
</script>
<script src="js/ui.js"></script>
</body>
</html>
