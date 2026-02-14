<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer.php';
require_once __DIR__ . '/../SMTP.php';
require_once __DIR__ . '/../Exception.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($input['email'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Введите корректный email'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE lower(email) = lower(?) LIMIT 1");
    $userStmt->execute([$email]);
    $userId = (int)$userStmt->fetchColumn();

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Пользователь с таким email не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS password_reset_codes (\n            id bigserial PRIMARY KEY,\n            user_id bigint NOT NULL,\n            email text NOT NULL,\n            code text NOT NULL,\n            created_at timestamp without time zone DEFAULT NOW(),\n            expires_at timestamp without time zone NOT NULL,\n            used boolean DEFAULT false,\n            used_at timestamp without time zone\n        )\n    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_reset_codes_email ON password_reset_codes (email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_reset_codes_user ON password_reset_codes (user_id)");

    $code = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');

    $insert = $pdo->prepare("\n        INSERT INTO password_reset_codes (user_id, email, code, expires_at)\n        VALUES (?, ?, ?, ?)\n    ");
    $insert->execute([$userId, $email, $code, $expiresAt]);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.yandex.ru';
    $mail->SMTPAuth = true;
    $mail->Username = 'northerntigris@yandex.ru';
    $mail->Password = 'xjzfqmgiwhfwwber';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom('northerntigris@yandex.ru', 'Платформа школьных олимпиад');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Восстановление пароля';
    $mail->Body = "<p>Код для восстановления доступа: <strong>{$code}</strong></p><p>Код действует 15 минут.</p>";

    $mail->send();

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Не удалось отправить письмо'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка запроса восстановления'], JSON_UNESCAPED_UNICODE);
}
