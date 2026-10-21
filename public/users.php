<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";

$role = $_SESSION['role'] ?? 'staff';
if ($role !== 'admin') {
    audit_event($conn, [
        'action' => 'security.users_access.denied', 'module' => 'users', 'status' => 'denied',
        'description' => 'A user without administrator access tried to open user management.'
    ]);
    header('Location: dashboard.php');
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Invalid request token';
    } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $roleP = $_POST['role'] === 'admin' ? 'admin' : 'staff';
        $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
        $branchVal = trim($_POST['branch'] ?? '');
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, status) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss', $full_name, $username, $password, $roleP, $status);
        $message = $stmt->execute() ? 'User created' : 'Error creating user';
        $newUserId = (int)$stmt->insert_id;
        $stmt->close();
        if ($message === 'User created' && $branchVal !== '' && $conn->query("SHOW COLUMNS FROM users LIKE 'branch'")->num_rows > 0) {
            $uidNew = $newUserId;
            $up = $conn->prepare("UPDATE users SET branch=? WHERE id=?");
            $up->bind_param('si', $branchVal, $uidNew);
            $up->execute();
            $up->close();
        }
        if ($message === 'User created') {
            log_action($conn, 'user_create', $username, [
                'entity_id' => (string)$newUserId,
                'new_values' => ['full_name'=>$full_name,'username'=>$username,'role'=>$roleP,'status'=>$status,'branch'=>$branchVal]
            ]);
            $conn->commit();
        } else { $conn->rollback(); }
    }
    if ($action === 'update_status') {
        $uid = (int)$_POST['id'];
        $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
        $beforeStmt = $conn->prepare("SELECT full_name, username, role, status FROM users WHERE id=? LIMIT 1");
        $beforeStmt->bind_param('i', $uid);
        $beforeStmt->execute();
        $beforeResult = $beforeStmt->get_result();
        $beforeUser = $beforeResult->fetch_assoc() ?: null;
        $beforeStmt->close();
        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param('si', $status, $uid);
        $message = $stmt->execute() ? 'Status updated' : 'Error updating status';
        if ($message === 'Status updated' && $beforeUser) {
            log_action($conn, 'user_status', (string)$uid.'->'.$status, [
                'entity_id' => (string)$uid,
                'old_values' => $beforeUser,
                'new_values' => ['status'=>$status]
            ]);
            $conn->commit();
        } else { $conn->rollback(); }
        $stmt->close();
    }
    if ($action === 'delete') {
        $uid = (int)$_POST['id'];
        if ($uid !== (int)($_SESSION['user_id'])) {
            $beforeStmt = $conn->prepare("SELECT full_name, username, role, status FROM users WHERE id=? LIMIT 1");
            $beforeStmt->bind_param('i', $uid);
            $beforeStmt->execute();
            $beforeResult = $beforeStmt->get_result();
            $beforeUser = $beforeResult->fetch_assoc() ?: null;
            $beforeStmt->close();
            $conn->begin_transaction();
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param('i', $uid);
            $message = $stmt->execute() ? 'User deleted' : 'Error deleting user';
            if ($message === 'User deleted' && $beforeUser) {
                log_action($conn, 'user_delete', (string)$uid, ['entity_id'=>(string)$uid,'old_values'=>$beforeUser]);
                $conn->commit();
            } else { $conn->rollback(); }
            $stmt->close();
        } else {
            $message = 'Cannot delete current user';
            audit_event($conn, [
                'action' => 'security.user_delete.denied', 'module' => 'users', 'status' => 'denied',
                'entity_type' => 'user', 'entity_id' => (string)$uid,
                'description' => 'Administrator tried to delete their own account.'
            ]);
        }
    }
    }
}

$hasBranch = $conn->query("SHOW COLUMNS FROM users LIKE 'branch'")->num_rows > 0;
$users = $conn->query("SELECT id, full_name, username, role, status, ".($hasBranch?'branch, ':'')."created_at FROM users ORDER BY created_at DESC");
$userName = htmlspecialchars($_SESSION['full_name']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Manage Users — LMG Creative Media</title>
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
  <?php $active_page='users'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='Manage Users'; $page_subtitle='Admin-only'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card p-3">
          <h6 class="mb-3">Create User</h6>
          <form method="POST" class="row g-2">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-12"><input class="form-control" name="full_name" placeholder="Full name" required></div>
            <div class="col-md-6"><input class="form-control" name="username" placeholder="Username" required></div>
            <div class="col-md-6"><input class="form-control" type="password" name="password" placeholder="Password" required></div>
            <div class="col-md-6">
              <select class="form-select" name="role">
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="col-md-6">
              <select class="form-select" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <?php if ($hasBranch): ?>
            <div class="col-md-6"><input class="form-control" name="branch" placeholder="Branch"></div>
            <?php endif; ?>
            <div class="col-12"><button class="btn btn-primary w-100">Add User</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card p-3">
          <h6 class="mb-3">All Users</h6>
          <div class="table-responsive">
            <table class="table table-borderless table-sm table-striped table-hover table-brand datatable">
              <thead>
                <tr class="text-muted small">
                  <th>#</th>
                  <th>Name</th>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Status</th>
                  <?php if ($hasBranch): ?><th>Branch</th><?php endif; ?>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php while($u = $users->fetch_assoc()): ?>
                <tr>
                  <td><?= (int)$u['id'] ?></td>
                  <td><?= htmlspecialchars($u['full_name']) ?></td>
                  <td><?= htmlspecialchars($u['username']) ?></td>
                  <td><span class="badge bg-<?= $u['role']==='admin'?'danger':'secondary' ?>"><?= $u['role'] ?></span></td>
                  <td>
                    <form method="POST" class="d-flex gap-2 align-items-center">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <select name="status" class="form-select form-select-sm" style="width:auto">
                        <option value="active" <?= $u['status']==='active'?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>>Inactive</option>
                      </select>
                      <button class="btn btn-sm btn-outline-primary">Save</button>
                    </form>
                  </td>
                  <?php if ($hasBranch): ?><td class="text-muted small"><?= htmlspecialchars($u['branch'] ?? '') ?></td><?php endif; ?>
                  <td>
                    <form method="POST" onsubmit="return confirm('Delete this user?')">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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
<script src="js/ui.js"></script>
</body>
</html>
