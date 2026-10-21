<?php
session_name('LMGSESSID');
session_start();
$actor = [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'full_name' => (string)($_SESSION['full_name'] ?? ''),
    'role' => (string)($_SESSION['role'] ?? '')
];
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Destroy the login session first so an audit storage outage cannot keep the user signed in.
$_SESSION['user_id'] = $actor['user_id'];
$_SESSION['full_name'] = $actor['full_name'];
$_SESSION['role'] = $actor['role'];
$auditFailed = false;
try {
    require_once "../includes/db.php";
    require_once "../includes/log.php";
    audit_event($conn, [
        'action' => 'auth.logout', 'module' => 'authentication',
        'description' => 'User signed out.'
    ]);
} catch (Throwable $e) {
    error_log('Unable to write logout audit event: '.$e->getMessage());
    $auditFailed = true;
}
$_SESSION = [];
if ($auditFailed) {
    http_response_code(503);
    exit('You are signed out, but the audit event could not be recorded.');
}
header("Location: index.php");
exit;
?>
