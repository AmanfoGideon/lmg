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
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $min_stock = (int)($_POST['min_stock'] ?? 0);
    if ($name !== '') {
      $conn->begin_transaction();
      $stmt = $conn->prepare("INSERT INTO products (name, sku, price, stock, min_stock) VALUES (?,?,?,?,?)");
      $stmt->bind_param('ssdii', $name, $sku, $price, $stock, $min_stock);
      $message = $stmt->execute() ? 'Product added' : 'Error adding product';
      if ($message === 'Product added') {
        log_action($conn, 'product_create', $name, [
          'entity_id'=>(string)$stmt->insert_id,
          'new_values'=>['name'=>$name,'sku'=>$sku,'price'=>$price,'stock'=>$stock,'min_stock'=>$min_stock]
        ]);
        $conn->commit();
      } else { $conn->rollback(); }
      $stmt->close();
    } else {
      $message = 'Provide a name';
    }
  }
  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $min_stock = (int)($_POST['min_stock'] ?? 0);
    if ($id > 0 && $name !== '') {
      $beforeStmt = $conn->prepare("SELECT name,sku,price,stock,min_stock FROM products WHERE id=? LIMIT 1");
      $beforeStmt->bind_param('i', $id);
      $beforeStmt->execute();
      $beforeResult = $beforeStmt->get_result();
      $beforeProduct = $beforeResult->fetch_assoc() ?: null;
      $beforeStmt->close();
      $conn->begin_transaction();
      $stmt = $conn->prepare("UPDATE products SET name=?, sku=?, price=?, stock=?, min_stock=? WHERE id=?");
      $stmt->bind_param('ssdiii', $name, $sku, $price, $stock, $min_stock, $id);
      $message = $stmt->execute() ? 'Product updated' : 'Error updating product';
      if ($message === 'Product updated' && $beforeProduct) {
        log_action($conn, 'product_update', (string)$id, [
          'entity_id'=>(string)$id,
          'old_values'=>$beforeProduct,
          'new_values'=>['name'=>$name,'sku'=>$sku,'price'=>$price,'stock'=>$stock,'min_stock'=>$min_stock]
        ]);
        $conn->commit();
      } else { $conn->rollback(); }
      $stmt->close();
    } else {
      $message = 'Invalid product';
    }
  }
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $beforeStmt = $conn->prepare("SELECT name,sku,price,stock,min_stock FROM products WHERE id=? LIMIT 1");
      $beforeStmt->bind_param('i', $id);
      $beforeStmt->execute();
      $beforeResult = $beforeStmt->get_result();
      $beforeProduct = $beforeResult->fetch_assoc() ?: null;
      $beforeStmt->close();
      $conn->begin_transaction();
      $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
      $stmt->bind_param('i', $id);
      $message = $stmt->execute() ? 'Product deleted' : 'Error deleting product';
      if ($message === 'Product deleted' && $beforeProduct) {
        log_action($conn, 'product_delete', (string)$id, ['entity_id'=>(string)$id,'old_values'=>$beforeProduct]);
        $conn->commit();
      } else { $conn->rollback(); }
      $stmt->close();
    }
  }
  }
}

$products = $conn->query("SELECT id, name, sku, price, stock, min_stock, created_at FROM products ORDER BY created_at DESC, id DESC");
$lowStockCount = (int)$conn->query("SELECT COUNT(*) AS c FROM products WHERE stock <= min_stock AND min_stock > 0")->fetch_assoc()['c'];
$userName = htmlspecialchars($_SESSION['full_name']);
$role = $_SESSION['role'] ?? 'staff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Products — LMG Creative Media</title>
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
  <?php $active_page='products'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Products'; $page_subtitle='Manage inventory and stock levels'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2" role="alert" aria-live="polite"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card p-3">
          <h6 class="mb-3">Add Product</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-12"><input class="form-control" name="name" placeholder="Product name" required></div>
            <div class="col-md-6"><input class="form-control" name="sku" placeholder="SKU"></div>
            <div class="col-md-6"><input class="form-control" type="number" step="0.01" name="price" placeholder="Price" required></div>
            <div class="col-md-6"><input class="form-control" type="number" name="stock" placeholder="Stock" required></div>
            <div class="col-md-6"><input class="form-control" type="number" name="min_stock" placeholder="Min stock (alert)" value="0"></div>
            <div class="col-12"><button class="btn btn-primary w-100">Add</button></div>
          </form>
        </div>
        <div class="card p-3 mt-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-title">Low Stock Alerts</div>
              <div class="stat-sub">Products at or below minimum</div>
            </div>
            <div class="stat-value"><?= (int)$lowStockCount ?></div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card p-3">
          <h6 class="mb-3">All Products</h6>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand align-middle datatable">
              <thead>
                <tr class="text-muted small">
                  <th>#</th>
                  <th>Name</th>
                  <th>SKU</th>
                  <th>Price</th>
                  <th>Stock</th>
                  <th>Min</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php while($p = $products->fetch_assoc()): ?>
                <tr>
                  <form method="POST" class="dcontents">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <td><?= (int)$p['id'] ?></td>
                    <td><input class="form-control form-control-sm" name="name" value="<?= htmlspecialchars($p['name']) ?>"></td>
                    <td><input class="form-control form-control-sm" name="sku" value="<?= htmlspecialchars($p['sku'] ?? '') ?>"></td>
                    <td><input class="form-control form-control-sm" type="number" step="0.01" name="price" value="<?= number_format((float)$p['price'], 2, '.', '') ?>"></td>
                    <td><input class="form-control form-control-sm" type="number" name="stock" value="<?= (int)$p['stock'] ?>"></td>
                    <td><input class="form-control form-control-sm" type="number" name="min_stock" value="<?= (int)$p['min_stock'] ?>"></td>
                    <td class="d-flex gap-2">
                      <button class="btn btn-sm btn-outline-primary">Save</button>
                  </form>
                      <form method="POST" onsubmit="return confirm('Delete product?')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
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
