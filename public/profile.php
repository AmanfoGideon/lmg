<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/log.php";

$uid = (int)$_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Invalid request token';
    } else {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $beforeStmt = $conn->prepare("SELECT full_name,email,phone FROM users WHERE id=? LIMIT 1");
    $beforeStmt->bind_param('i', $uid);
    $beforeStmt->execute();
    $beforeStmt->bind_result($oldName, $oldEmail, $oldPhone);
    $hasUser = $beforeStmt->fetch();
    $beforeStmt->close();
    $conn->begin_transaction();

    if ($pass !== '') {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, password=? WHERE id=?");
        $stmt->bind_param('ssssi', $full_name, $email, $phone, $hash, $uid);
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?");
        $stmt->bind_param('sssi', $full_name, $email, $phone, $uid);
    }
    if ($stmt->execute()) {
        $message = 'Profile updated';
        audit_event($conn, [
            'action' => 'user.profile.updated', 'module' => 'users', 'entity_type' => 'user', 'entity_id' => (string)$uid,
            'description' => 'User profile details updated.',
            'old_values' => $hasUser ? ['full_name'=>$oldName,'email'=>$oldEmail,'phone'=>$oldPhone] : null,
            'new_values' => ['full_name'=>$full_name,'email'=>$email,'phone'=>$phone]
        ]);
        if ($pass !== '') {
            audit_event($conn, [
                'action' => 'auth.password.changed', 'module' => 'authentication',
                'entity_type' => 'user', 'entity_id' => (string)$uid,
                'description' => 'User changed account password.'
            ]);
        }
        $conn->commit();
        $_SESSION['full_name'] = $full_name;
    } else {
        $conn->rollback();
        $message = 'Error updating profile';
    }
    $stmt->close();
    }
}

$stmt = $conn->prepare("SELECT full_name, username, email, phone, role FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$stmt->bind_result($full_name, $username, $email, $phone, $role);
$stmt->fetch();
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Profile — LMG Creative Media</title>
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
  <?php $active_page='profile'; include "../includes/layout/sidebar.php"; ?>

  <main class="main flex-grow-1 p-4">
    <?php $page_title='My Profile'; $page_subtitle='Update your details'; $header_actions=''; include "../includes/layout/header.php"; ?>

    <?php if ($message): ?>
      <div class="alert alert-info py-2"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card p-3">
      <form method="POST" class="row g-3">
        <?= csrf_input() ?>
        <div class="col-md-6">
          <label class="form-label">Full Name</label>
          <input class="form-control" name="full_name" value="<?= htmlspecialchars($full_name ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Username</label>
          <input class="form-control" value="<?= htmlspecialchars($username ?? '') ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" name="email" value="<?= htmlspecialchars($email ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone" value="<?= htmlspecialchars($phone ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">New Password</label>
          <input class="form-control" type="password" name="password" placeholder="Leave blank to keep current">
        </div>
        <div class="col-md-6">
          <label class="form-label">Role</label>
          <input class="form-control" value="<?= htmlspecialchars($role ?? '') ?>" disabled>
        </div>
        <div class="col-12">
          <button class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>

    <footer class="mt-4 text-center text-muted small">© <?= date('Y') ?> LMG Creative Media</footer>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/ui.js"></script>
</body>
</html>
