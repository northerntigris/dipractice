<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($input['email'] ?? ''));
$code = trim((string)($input['code'] ?? ''));
$newPassword = (string)($input['new_password'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Введите корректный email'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{6}$/', $code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Введите 6-значный код'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Пароль должен быть не короче 8 символов'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    $codeStmt = $pdo->prepare("\n        SELECT id, user_id\n        FROM password_reset_codes\n        WHERE lower(email) = lower(:email)\n          AND code = :code\n          AND used = false\n          AND expires_at >= NOW()\n        ORDER BY id DESC\n        LIMIT 1\n        FOR UPDATE\n    ");
    $codeStmt->execute([
        ':email' => $email,
        ':code' => $code,
    ]);
    $resetRow = $codeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Код недействителен или истек'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $updateUser = $pdo->prepare("UPDATE users SET password = :password WHERE id = :user_id");
    $updateUser->execute([
        ':password' => $hash,
        ':user_id' => (int)$resetRow['user_id'],
    ]);

    $markUsed = $pdo->prepare("UPDATE password_reset_codes SET used = true, used_at = NOW() WHERE id = :id");
    $markUsed->execute([':id' => (int)$resetRow['id']]);

    $pdo->commit();

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка обновления пароля'], JSON_UNESCAPED_UNICODE);
}
