<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";
require_once "../includes/suppliers.php";

$start = (string)($_GET['start'] ?? date('Y-m-01'));
$end = (string)($_GET['end'] ?? date('Y-m-t'));
$search = trim((string)($_GET['search'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end) {
  $start = date('Y-m-01');
  $end = date('Y-m-t');
}
$searchLike = '%' . $search . '%';
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM suppliers WHERE business_name LIKE ? OR contact_person LIKE ?");
$countStmt->bind_param('ss', $searchLike, $searchLike);
$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$page = min($page, max(1, (int)ceil($totalRows / $limit)));
$offset = ($page - 1) * $limit;
$limitSql = (($_GET['format'] ?? '') === 'csv') ? '' : ' LIMIT ? OFFSET ?';
$sql = "SELECT s.id, s.business_name, s.status,
  (SELECT COALESCE(SUM(b.total), 0) FROM supplier_bills b WHERE b.supplier_id=s.id AND b.status<>'cancelled' AND b.bill_date BETWEEN ? AND ?) AS purchases,
  (SELECT COALESCE(SUM(p.amount), 0) FROM supplier_payments p WHERE p.supplier_id=s.id AND p.status<>'cancelled' AND p.payment_date BETWEEN ? AND ?) AS payments,
  (SELECT COALESCE(SUM(GREATEST(b.total-COALESCE(a.allocated,0),0)),0)
    FROM supplier_bills b LEFT JOIN (SELECT bill_id,SUM(amount) AS allocated FROM supplier_payment_allocations WHERE reversed_at IS NULL GROUP BY bill_id) a ON a.bill_id=b.id
    WHERE b.supplier_id=s.id AND b.status IN ('unpaid','partial','overdue')) AS outstanding,
  (SELECT COALESCE(SUM(GREATEST(b.total-COALESCE(a.allocated,0),0)),0)
    FROM supplier_bills b LEFT JOIN (SELECT bill_id,SUM(amount) AS allocated FROM supplier_payment_allocations WHERE reversed_at IS NULL GROUP BY bill_id) a ON a.bill_id=b.id
    WHERE b.supplier_id=s.id AND b.status IN ('unpaid','partial','overdue') AND b.due_date<CURRENT_DATE) AS overdue
  FROM suppliers s WHERE s.business_name LIKE ? OR s.contact_person LIKE ? ORDER BY s.business_name, s.id" . $limitSql;
$stmt = $conn->prepare($sql);
if ($limitSql !== '') $stmt->bind_param('ssssssii', $start, $end, $start, $end, $searchLike, $searchLike, $limit, $offset);
else $stmt->bind_param('ssssss', $start, $end, $start, $end, $searchLike, $searchLike);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (($_GET['format'] ?? '') === 'csv') {
  audit_event($conn, ['action' => 'supplier.report.exported', 'module' => 'suppliers', 'description' => 'Supplier report exported.', 'metadata' => ['start' => $start, 'end' => $end, 'search' => $search !== '']]);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="supplier-report-' . date('Ymd') . '.csv"');
  $output = fopen('php://output', 'w');
  fputcsv($output, ['Supplier', 'Status', 'Purchases in range', 'Payments in range', 'Outstanding now', 'Overdue now']);
  foreach ($rows as $row) fputcsv($output, [$row['business_name'], $row['status'], $row['purchases'], $row['payments'], $row['outstanding'], $row['overdue']]);
  fclose($output);
  exit;
}
$userName = htmlspecialchars($_SESSION['full_name'] ?? '');
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Supplier Reports — LMG Creative Media</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <style>@media print {.sidebar,.no-print,footer{display:none!important}.main{padding:0!important}.card{border:0!important}}</style>
</head>
<body>
<div class="app-shell d-flex">
  <?php $active_page='suppliers'; include "../includes/layout/sidebar.php"; ?>
  <main class="main flex-grow-1 p-4">
    <?php $page_title='Supplier Reports'; $page_subtitle='Purchases and payments by date range with current payables'; $header_actions='<a class="btn btn-outline-light me-2" href="suppliers.php">Supplier Accounts</a>'; include "../includes/layout/header.php"; ?>
    <div class="card p-3 mb-3">
      <form method="GET" class="row g-2 align-items-end no-print">
        <div class="col-sm-3"><label class="form-label">From</label><input class="form-control" type="date" name="start" value="<?= htmlspecialchars($start) ?>"></div>
        <div class="col-sm-3"><label class="form-label">To</label><input class="form-control" type="date" name="end" value="<?= htmlspecialchars($end) ?>"></div>
        <div class="col-sm-3"><label class="form-label">Supplier</label><input class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or contact"></div>
        <div class="col-sm-3 d-flex gap-2"><button class="btn btn-primary">Filter</button><a class="btn btn-outline-light" href="?start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&search=<?= urlencode($search) ?>&format=csv">Export CSV</a><button class="btn btn-outline-light" type="button" onclick="window.print()">Print</button></div>
      </form>
    </div>
    <div class="card p-3">
      <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><th>Supplier</th><th>Status</th><th class="text-end">Purchases</th><th class="text-end">Payments</th><th class="text-end">Outstanding</th><th class="text-end">Overdue</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">No supplier records found.</td></tr><?php endif; ?>
          <?php foreach ($rows as $row): ?><tr>
            <td><?= htmlspecialchars($row['business_name']) ?></td><td><?= htmlspecialchars($row['status']) ?></td>
            <td class="text-end"><?= fmtCurrency($row['purchases']) ?></td><td class="text-end"><?= fmtCurrency($row['payments']) ?></td>
            <td class="text-end"><?= fmtCurrency($row['outstanding']) ?></td><td class="text-end"><?= fmtCurrency($row['overdue']) ?></td>
            <td><a href="supplier_statement.php?supplier_id=<?= (int)$row['id'] ?>">Statement</a></td>
          </tr><?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($totalRows > $limit): ?><nav><ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($pageNumber = 1; $pageNumber <= (int)ceil($totalRows / $limit); $pageNumber++): ?>
          <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="?start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&search=<?= urlencode($search) ?>&page=<?= $pageNumber ?>"><?= $pageNumber ?></a></li>
        <?php endfor; ?>
      </ul></nav><?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
