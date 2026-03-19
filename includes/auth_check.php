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
function csrf_token(): string {
  return $_SESSION['csrf_token'];
}
function csrf_input(): string {
  return '<input type="hidden" name="_csrf" value="'.htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8').'">';
}
function csrf_verify(): bool {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
  $t = $_POST['_csrf'] ?? '';
  return is_string($t) && hash_equals($_SESSION['csrf_token'], $t);
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net; img-src 'self' data: https:; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; connect-src 'self'");
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}
?>
