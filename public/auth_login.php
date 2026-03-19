<?php
session_start();
require_once "../includes/db.php";

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

$username = $data["username"] ?? "";
$password = $data["password"] ?? "";

$stmt = $conn->prepare("SELECT id, full_name, username, password, role FROM users WHERE username=? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows == 1) {
    $stmt->bind_result($id, $full_name, $user, $pass_hash, $role);
    $stmt->fetch();

    if (password_verify($password, $pass_hash)) {

        $_SESSION["user_id"] = $id;
        $_SESSION["full_name"] = $full_name;
        $_SESSION["role"] = $role;

        echo json_encode([
            "success" => true,
            "message" => "Login successful."
        ]);
        exit;
    }
}

echo json_encode([
    "success" => false,
    "message" => "Invalid username or password."
]);
