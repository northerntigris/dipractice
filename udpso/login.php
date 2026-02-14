<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);

$identifier = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Введите логин/email и пароль'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = :identifier OR lower(email) = lower(:identifier) LIMIT 1");
$stmt->execute([':identifier' => $identifier]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    session_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];

    echo json_encode([
        'success' => true,
        'user_id' => $user['id'],
        'user_role' => $user['role']
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Неверный логин/email или пароль'
    ], JSON_UNESCAPED_UNICODE);
}
?>
