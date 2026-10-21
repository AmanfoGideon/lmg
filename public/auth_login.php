<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_name('LMGSESSID');
session_start();
require_once "../includes/db.php";
require_once "../includes/log.php";

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

$username = substr(trim((string)($data["username"] ?? "")), 0, 100);
$password = $data["password"] ?? "";

$stmt = $conn->prepare("SELECT id, full_name, username, password, role, status FROM users WHERE username=? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($id, $full_name, $user, $pass_hash, $role, $accountStatus);
$authenticated = false;

if ($stmt->fetch()) $authenticated = $accountStatus === 'active' && password_verify((string)$password, $pass_hash);
$stmt->close();

try {
    if ($authenticated) {
        session_regenerate_id(true);
        $_SESSION["user_id"] = (int)$id;
        $_SESSION["full_name"] = $full_name;
        $_SESSION["role"] = $role;
        audit_event($conn, [
            'action' => 'auth.login.success', 'module' => 'authentication',
            'entity_type' => 'user', 'entity_id' => (string)$id,
            'description' => 'User signed in successfully.'
        ]);
        echo json_encode(["success" => true, "message" => "Login successful."]);
        exit;
    }
    audit_event($conn, [
        'action' => 'auth.login.failed', 'module' => 'authentication', 'status' => 'failure',
        'actor_name_snapshot' => $username,
        'description' => 'Sign-in attempt failed.'
    ]);
} catch (Throwable $e) {
    error_log('Authentication audit write failed: '.$e->getMessage());
    if ($authenticated) {
        unset($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role']);
        session_regenerate_id(true);
    }
    http_response_code(503);
    echo json_encode(["success" => false, "message" => "Authentication is temporarily unavailable."]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Invalid username or password."
]);
