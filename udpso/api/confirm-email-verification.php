<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($input['email'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Некорректный email'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Введите код подтверждения'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];

$dupStmt = $pdo->prepare("SELECT 1 FROM users WHERE lower(email) = lower(?) AND id <> ? LIMIT 1");
$dupStmt->execute([$email, $userId]);
if ($dupStmt->fetchColumn()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Этот email уже используется другим пользователем'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS email_verification_codes (
        id bigserial PRIMARY KEY,
        user_id bigint NOT NULL,
        email text NOT NULL,
        code text NOT NULL,
        created_at timestamp without time zone DEFAULT NOW(),
        expires_at timestamp without time zone NOT NULL,
        used boolean DEFAULT false,
        used_at timestamp without time zone
    )
");

$stmt = $pdo->prepare("
    SELECT id
    FROM email_verification_codes
    WHERE user_id = :user_id
      AND email = :email
      AND code = :code
      AND used = false
      AND expires_at > NOW()
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([
    ':user_id' => $userId,
    ':email' => $email,
    ':code' => $code
]);
$codeId = $stmt->fetchColumn();

if (!$codeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный или просроченный код'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    $upd = $pdo->prepare("UPDATE users SET email = :email WHERE id = :user_id");
    $upd->execute([
        ':email' => $email,
        ':user_id' => $userId
    ]);

    $markUsed = $pdo->prepare("
        UPDATE email_verification_codes
        SET used = true, used_at = NOW()
        WHERE user_id = :user_id AND email = :email AND used = false
    ");
    $markUsed->execute([
        ':user_id' => $userId,
        ':email' => $email
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Не удалось подтвердить email'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
