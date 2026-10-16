<?php
declare(strict_types=1);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_name('LMGSESSID');
session_start();
if (!isset($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once "includes/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request token.";
    } else {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    $stmt = $conn->prepare("SELECT id, full_name, password, role, status FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($id, $full_name, $hashed_password, $role, $status);
        $stmt->fetch();

        if ($status !== "active") {
            $error = "Your account is disabled. Please contact admin.";
        } elseif ($hashed_password !== null && password_verify($password, $hashed_password)) {

            $_SESSION["user_id"] = $id;
            $_SESSION["full_name"] = $full_name;
            $_SESSION["role"] = $role;
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            $_SESSION["currency_symbol"] = env_var('CURRENCY_SYMBOL', $_SESSION["currency_symbol"] ?? '₵') ?? '₵';

            header("Location: public/dashboard.php");
            exit();

        } else {
            $error = "Incorrect password.";
        }

    } else {
        $error = "User not found.";
    }

    $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LMG Creative Media - Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="public/css/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    /* Critical CSS for PHP Login */
    :root {
        --bg-dark: #0a0f14;
        --accent: #FFD700;
        --text-main: #e6eef5;
    }
    body { background: var(--bg-dark) !important; color: var(--text-main) !important; margin: 0; font-family: 'Inter', sans-serif; min-height: 100vh; }
    .login-container { opacity: 0; transition: opacity 0.5s ease; }
    body.loaded .login-container { opacity: 1; }
    #bgCanvas { position: fixed; inset: 0; z-index: 1; pointer-events: none; }
</style>
<script>
    if('serviceWorker' in navigator) {
        navigator.serviceWorker.register('public/sw.js');
    }
    window.addEventListener('load', () => { document.body.classList.add('loaded'); });
</script>
</head>
<body>

<!-- Background Canvas -->
<canvas id="bgCanvas"></canvas>

<!-- Login Container -->
<div class="login-container d-flex flex-column justify-content-center align-items-center">

    <!-- Logo -->
    <img src="public/images/logo.png" class="logo animated-logo" alt="LMG Creative Media">

    <!-- Headline -->
    <h1 class="text-mtn-yellow fw-bold animated-text">LMG Creative Media</h1>
    <p class="text-light fs-5 text-center px-4 animated-text">
        Creativity meets technology. Manage your clients, invoices, and transactions with style.
    </p>
    <button id="btnInstallApp" class="btn btn-outline-light mb-3" style="display:none">Install App</button>

    <!-- Login Card Wrapper -->
    <div class="card-wrapper position-relative">

        <!-- Animated background glow behind card -->
        <div class="card-bg"></div>
        
        <!-- Login Card -->
        <div class="card login-card shadow-lg p-4" id="loginCard">
            <h3 class="text-center mb-4 text-black">Sign In</h3>
            <?php
        //$pass = "admin123"; // For demo purposes only
        //$password = password_hash($pass, PASSWORD_DEFAULT);
        //echo $password;  
       // ?>
            <?php if ($error): ?>
                <div class="alert alert-danger text-center py-2"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-floating mb-3">
                    <input type="text" name="username" class="form-control" id="username" required>
                    <label for="username">Username</label>
                </div>

                <div class="form-floating mb-3">
                    <input type="password" name="password" class="form-control" id="password" required>
                    <label for="password">Password</label>
                </div>

                <button type="submit" class="btn btn-mtn-yellow w-100 fw-bold">Login</button>

                <p class="text-center mt-3 mb-0 text-muted">© <?php echo date("Y"); ?> LMG Creative Media</p>

            </form>

        </div>
    </div>
</div>

<div class="container mt-4">
  <div class="row g-2">
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/clients.php"><i class="bi bi-people"></i> Clients</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/products.php"><i class="bi bi-box-seam"></i> Products</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/transactions.php"><i class="bi bi-wallet2"></i> Transactions</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/invoice_create.php"><i class="bi bi-plus-square"></i> New Invoice</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/invoices.php"><i class="bi bi-receipt"></i> Invoices</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/sms.php"><i class="bi bi-chat-dots"></i> SMS</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/users.php"><i class="bi bi-people-gear"></i> Users</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/profile.php"><i class="bi bi-person-circle"></i> Profile</a></div>
    <div class="col-md-3 col-sm-6"><a class="btn btn-black w-100" href="public/backup.php"><i class="bi bi-database-lock"></i> Backup</a></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="public/js/app.js"></script>
<script src="public/js/ui.js"></script>
</body>
</html>
