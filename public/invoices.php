<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/invoice.php";

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $message = 'Invalid request token';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
      try {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $invoice_date = (string)($_POST['invoice_date'] ?? date('Y-m-d'));
        $due_date = (string)($_POST['due_date'] ?? '');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $requestKey = (string)($_POST['request_key'] ?? '');
        $rawItems = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!preg_match('/^[a-f0-9]{32}$/', $requestKey)) throw new InvalidArgumentException('Reload the invoice form before submitting.');
        if ($client_id <= 0 || !invoice_valid_date($invoice_date) || !invoice_valid_date($due_date, true)) throw new InvalidArgumentException('Select a valid client and invoice dates.');
        $calculation = invoice_calculate(is_array($rawItems) ? $rawItems : [], $_POST['tax'] ?? '0');
        $conn->begin_transaction();
        $retry = $conn->prepare('SELECT id FROM invoices WHERE request_key=? LIMIT 1');
        $retry->bind_param('s', $requestKey);
        $retry->execute();
        $alreadyCreated = $retry->get_result()->fetch_assoc();
        $retry->close();
        if ($alreadyCreated) throw new InvalidArgumentException('This invoice has already been submitted.');
        $check = $conn->prepare('SELECT id FROM clients WHERE id=? LIMIT 1');
        $check->bind_param('i', $client_id);
        $check->execute();
        if (!$check->get_result()->fetch_row()) throw new InvalidArgumentException('Select a valid client.');
        $check->close();
        $newInvoiceId = invoice_insert($conn, ['client_id'=>$client_id,'invoice_date'=>$invoice_date,'due_date'=>$due_date,'notes'=>$notes,'request_key'=>$requestKey], $calculation);
        $invoice_number = 'INV-'.str_replace('-', '', $invoice_date).'-'.str_pad((string)$newInvoiceId, 4, '0', STR_PAD_LEFT);
        log_action($conn, 'invoice_create', $invoice_number, [
          'entity_id'=>(string)$newInvoiceId,
          'new_values'=>['invoice_number'=>$invoice_number,'client_id'=>$client_id,'invoice_date'=>$invoice_date,'due_date'=>$due_date ?: null,'subtotal'=>$calculation['subtotal'],'tax'=>$calculation['tax'],'total'=>$calculation['total'],'status'=>'unpaid','item_count'=>count($calculation['items'])]
        ]);
        $conn->commit();
        $message = 'Invoice created';
      } catch (InvalidArgumentException $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $message = $e->getMessage();
      } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $message = 'Invoice could not be saved. Please try again.';
        error_log('Invoice creation failed: '.$e->getMessage());
      }
    } elseif ($action === 'update_status') {
      $message = 'Invoice status is calculated from recorded payments and cannot be changed manually.';
    }
  }
}
$clients = $conn->query("SELECT id, full_name FROM clients ORDER BY full_name ASC");
$invoices = $conn->query("SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.total,
                          CASE WHEN COALESCE(SUM(CASE WHEN t.type='payment' THEN t.amount ELSE 0 END),0)<=0 THEN 'unpaid'
                               WHEN COALESCE(SUM(CASE WHEN t.type='payment' THEN t.amount ELSE 0 END),0)<COALESCE(i.total,0) THEN 'partial'
                               ELSE 'paid' END AS status,
                          c.full_name AS client
                          FROM invoices i LEFT JOIN clients c ON c.id=i.client_id
                          LEFT JOIN transactions t ON t.invoice_id=i.id
                          GROUP BY i.id, i.invoice_number, i.invoice_date, i.due_date, i.total, c.full_name
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
