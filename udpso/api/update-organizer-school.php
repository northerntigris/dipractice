<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

use PHPMailer\PHPMailer\PHPMailer;

require_once '../PHPMailer.php';
require_once '../SMTP.php';
require_once '../Exception.php';

session_start();

function genPassword($len = 10) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pwd = '';
    for ($i = 0; $i < $len; $i++) {
        $pwd .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $pwd;
}

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'organizer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $schoolId = (int)($_POST['school_id'] ?? 0);
    if ($schoolId <= 0) {
        throw new RuntimeException('Не передан идентификатор школы');
    }

    $requiredFields = [
        'full_name', 'short_name', 'inn', 'ogrn', 'ogrn_date',
        'address', 'region', 'director_fio', 'director_inn', 'director_position',
        'contact_phone', 'contact_email'
    ];

    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            throw new RuntimeException("Не заполнено обязательное поле: $field");
        }
    }

    if (!filter_var($_POST['contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Некорректный email адрес');
    }

    $checkStmt = $pdo->prepare("\n      SELECT s.id\n      FROM approved_schools s\n      WHERE s.id = :school_id\n        AND s.approved_by = :organizer_id\n      LIMIT 1\n    ");
    $checkStmt->execute([
        ':school_id' => $schoolId,
        ':organizer_id' => (int)$_SESSION['user_id']
    ]);

    if (!$checkStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("\n      UPDATE approved_schools\n      SET\n        full_name = :full_name,\n        short_name = :short_name,\n        inn = :inn,\n        ogrn = :ogrn,\n        ogrn_date = :ogrn_date,\n        address = :address,\n        region = :region,\n        director_fio = :director_fio,\n        director_inn = :director_inn,\n        director_position = :director_position,\n        contact_phone = :contact_phone,\n        contact_email = :contact_email\n      WHERE id = :school_id\n    ");

    $updateStmt->execute([
        ':full_name' => $_POST['full_name'],
        ':short_name' => $_POST['short_name'],
        ':inn' => $_POST['inn'],
        ':ogrn' => $_POST['ogrn'],
        ':ogrn_date' => $_POST['ogrn_date'],
        ':address' => $_POST['address'],
        ':region' => $_POST['region'],
        ':director_fio' => $_POST['director_fio'],
        ':director_inn' => $_POST['director_inn'],
        ':director_position' => $_POST['director_position'],
        ':contact_phone' => $_POST['contact_phone'],
        ':contact_email' => $_POST['contact_email'],
        ':school_id' => $schoolId
    ]);

    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS organizer_school_documents (\n        id BIGSERIAL PRIMARY KEY,\n        school_id BIGINT NOT NULL REFERENCES approved_schools(id) ON DELETE CASCADE,\n        original_name TEXT NOT NULL,\n        stored_name TEXT NOT NULL,\n        file_size BIGINT NOT NULL DEFAULT 0,\n        uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()\n      )\n    ");

    if (!empty($_FILES['verification_documents'])) {
        $uploadDir = __DIR__ . '/../uploads/organizer-school-documents';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $files = $_FILES['verification_documents'];
        $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];
        $count = is_array($files['name']) ? count($files['name']) : 1;

        $docStmt = $pdo->prepare("\n          INSERT INTO organizer_school_documents (school_id, original_name, stored_name, file_size, uploaded_at)\n          VALUES (:school_id, :original_name, :stored_name, :file_size, NOW())\n        ");

        for ($i = 0; $i < $count; $i++) {
            $error = is_array($files['error']) ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Ошибка загрузки файла (код ' . $error . ')');
            }

            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $origName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $size = is_array($files['size']) ? (int)($files['size'][$i] ?? 0) : (int)($files['size'] ?? 0);

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array($ext, $allowedExt, true)) {
                throw new RuntimeException('Недопустимый формат файла: ' . $origName);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($origName));
            $storedName = uniqid('school_doc_', true) . '_' . $safeName;
            $destPath = $uploadDir . '/' . $storedName;

            if (!move_uploaded_file($tmpName, $destPath)) {
                throw new RuntimeException('Не удалось сохранить файл: ' . $origName);
            }

            $docStmt->execute([
                ':school_id' => $schoolId,
                ':original_name' => $origName,
                ':stored_name' => $storedName,
                ':file_size' => $size
            ]);
        }
    }

    $passwordSent = false;
    $generatePassword = (string)($_POST['generate_new_password'] ?? '0') === '1';

    if ($generatePassword) {
        $schoolUserStmt = $pdo->prepare("\n          SELECT id, username\n          FROM users\n          WHERE school_id = :school_id AND role = 'school'\n          ORDER BY id ASC\n          LIMIT 1\n        ");
        $schoolUserStmt->execute([':school_id' => $schoolId]);
        $schoolUser = $schoolUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$schoolUser) {
            throw new RuntimeException('Не найден пользователь школы для обновления пароля');
        }

        $plainPassword = genPassword(10);
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

        $updateUserStmt = $pdo->prepare("\n          UPDATE users\n          SET password = :password, must_change_password = TRUE, email = :email\n          WHERE id = :user_id\n        ");
        $updateUserStmt->execute([
            ':password' => $passwordHash,
            ':email' => $_POST['contact_email'],
            ':user_id' => (int)$schoolUser['id']
        ]);

        $orgStmt = $pdo->prepare("\n          SELECT u.full_name AS organizer_fio, ao.full_name AS organizer_org\n          FROM users u\n          LEFT JOIN approved_organizations ao ON ao.id = u.organization_id\n          WHERE u.id = :user_id\n          LIMIT 1\n        ");
        $orgStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $orgInfo = $orgStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $organizerFio = $orgInfo['organizer_fio'] ?? 'Организатор';
        $organizerOrg = $orgInfo['organizer_org'] ?? 'Организация не указана';

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.yandex.ru';
        $mail->SMTPAuth = true;
        $mail->Username = 'northerntigris@yandex.ru';
        $mail->Password = 'xjzfqmgiwhfwwber';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->setFrom('northerntigris@yandex.ru', 'Платформа школьных олимпиад');
        $mail->addAddress($_POST['contact_email'], $_POST['director_fio'] ?? $_POST['full_name']);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Изменение пароля для входа на платформу школьных олимпиад';
        $mail->Body = "
            <h2>Пароль для входа обновлён</h2>
            <p>Для образовательного учреждения <b>{$_POST['full_name']}</b> сформирован новый пароль.</p>
            <p><b>Обновление выполнил:</b> {$organizerFio} ({$organizerOrg}).</p>
            <p><b>Логин:</b> {$schoolUser['username']}<br>
            <b>Новый пароль:</b> {$plainPassword}</p>
            <p>После входа в систему необходимо сменить пароль.</p>
        ";

        $mail->send();
        $passwordSent = true;
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'password_sent' => $passwordSent], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
