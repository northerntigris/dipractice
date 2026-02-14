<?php
header('Content-Type: application/json');
require_once '../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../PHPMailer.php';
require_once '../SMTP.php';
require_once '../Exception.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'organizer') {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

function genPassword($len = 10) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pwd = '';
    for ($i=0; $i<$len; $i++) {
        $pwd .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return $pwd;
}

function genUsername($shortName, $email) {
    $base = strtolower(trim($shortName));
    $base = preg_replace('/[^a-z0-9_]+/i', '_', $base);
    if ($base === '' || strlen($base) < 4) {
        $base = strtolower(explode('@', $email)[0] ?? 'school');
        $base = preg_replace('/[^a-z0-9_]+/i', '_', $base);
        if ($base === '' || strlen($base) < 4) $base = 'school';
    }
    return 'sch_' . $base;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

try {
    // обязательные поля — как в register-school.php
    $requiredFields = [
        'full_name', 'short_name', 'inn', 'ogrn', 'ogrn_date',
        'address', 'region', 'director_fio', 'director_inn', 'director_position',
        'contact_phone', 'contact_email'
    ];

    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            throw new Exception("Не заполнено обязательное поле: $field");
        }
    }

    if (!filter_var($input['contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Некорректный email адрес");
    }

    // Данные организатора (ФИО) + его организация (по organization_id -> approved_organizations)
    $orgStmt = $pdo->prepare("
        SELECT u.full_name AS organizer_fio, u.organization_id, s.full_name AS organizer_org
        FROM users u
        LEFT JOIN approved_organizations s ON s.id = u.organization_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $orgStmt->execute([$_SESSION['user_id']]);
    $org = $orgStmt->fetch(PDO::FETCH_ASSOC);

    $organizerFio = $org['organizer_fio'] ?: 'Организатор';
    $organizerOrg = $org['organizer_org'] ?: 'Организация не указана';

    $organizationId = (int)($org['organization_id'] ?? 0);
    if ($organizationId <= 0) {
        throw new Exception("У организатора не указана организация");
    }

    // генерируем логин/пароль для школы
    $usernameBase = genUsername($input['short_name'], $input['contact_email']);

    // делаем username уникальным
    $username = $usernameBase;
    $i = 1;
    $check = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    while (true) {
        $check->execute([$username]);
        if (!$check->fetchColumn()) break;
        $i++;
        $username = $usernameBase . $i;
    }

    $plainPassword = genPassword(10);
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS organizer_school_documents (
        id BIGSERIAL PRIMARY KEY,
        school_id BIGINT NOT NULL REFERENCES approved_schools(id) ON DELETE CASCADE,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
      )
    ");

    // 1) создаём пользователя новой роли
    $uStmt = $pdo->prepare("
        INSERT INTO users (username, password, role, full_name, email, created_at)
        VALUES (?, ?, 'school', ?, ?, NOW())
        RETURNING id
    ");
    $uStmt->execute([
        $username,
        $passwordHash,
        $input['full_name'],
        $input['contact_email']
    ]);
    $schoolUserId = (int)$uStmt->fetchColumn();

    // 2) добавляем школу сразу в approved_schools (это уже НЕ заявка)
    $sStmt = $pdo->prepare("
        INSERT INTO approved_schools (
            full_name, short_name, inn, ogrn, ogrn_date,
            address, region, director_fio, director_inn, director_position,
            contact_phone, contact_email, approved_at, approved_by, user_id, organization_id
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?
        )
        RETURNING id
    ");
    $sStmt->execute([
        $input['full_name'],
        $input['short_name'],
        $input['inn'],
        $input['ogrn'],
        $input['ogrn_date'],
        $input['address'],
        $input['region'],
        $input['director_fio'],
        $input['director_inn'],
        $input['director_position'],
        $input['contact_phone'],
        $input['contact_email'],
        $_SESSION['user_id'],
        $schoolUserId,
        $organizationId
    ]);
    $approvedSchoolId = (int)$sStmt->fetchColumn();

    // 3) проставляем users.school_id = approved_schools.id
    $upd = $pdo->prepare("UPDATE users SET school_id = ? WHERE id = ?");
    $upd->execute([$approvedSchoolId, $schoolUserId]);

    $activityTitle = 'Зарегистрировано образовательное учреждение «' . $input['short_name'] . '»';
    $activityStmt = $pdo->prepare("
        INSERT INTO activities (user_id, type, title, created_at)
        VALUES (:user_id, 'school_registered', :title, NOW())
    ");
    $activityStmt->execute([
        'user_id' => $_SESSION['user_id'],
        'title' => $activityTitle
    ]);

    // 4) отправляем письмо — SMTP настройки берём 1:1 как у тебя в process-application.php
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.yandex.ru';
    $mail->SMTPAuth = true;
    $mail->Username = 'northerntigris@yandex.ru';
    $mail->Password = 'xjzfqmgiwhfwwber';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;

    $mail->setFrom('northerntigris@yandex.ru', 'Платформа школьных олимпиад');
    $mail->addAddress($input['contact_email'], $input['director_fio']);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Школа зарегистрирована на цифровой платформе школьных олимпиад';

    $mail->Body = "
        <h2>Школа зарегистрирована</h2>
        <p>Образовательное учреждение <b>{$input['full_name']}</b> зарегистрировано на цифровой платформе школьных олимпиад.</p>
        <p><b>Регистрацию выполнил организатор:</b> {$organizerFio} ({$organizerOrg}).</p>

        <h3>Данные для входа в систему</h3>
        <p><b>Логин:</b> {$username}<br>
        <b>Пароль:</b> {$plainPassword}</p>

        <p>Рекомендуем сменить пароль после первого входа.</p>
    ";

    if (!empty($_FILES['verification_documents'])) {
        $files = $_FILES['verification_documents'];
        $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];
        $count = is_array($files['name']) ? count($files['name']) : 1;
        $uploadDir = __DIR__ . '/../uploads/organizer-school-documents';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $docStmt = $pdo->prepare("
          INSERT INTO organizer_school_documents (school_id, original_name, stored_name, file_size, uploaded_at)
          VALUES (:school_id, :original_name, :stored_name, :file_size, NOW())
        ");

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

            $mail->addAttachment($tmpName, $origName);

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($origName));
            $storedName = uniqid('school_doc_', true) . '_' . $safeName;
            $destPath = $uploadDir . '/' . $storedName;
            if (!move_uploaded_file($tmpName, $destPath)) {
                throw new RuntimeException('Не удалось сохранить файл: ' . $origName);
            }

            $docStmt->execute([
                ':school_id' => $approvedSchoolId,
                ':original_name' => $origName,
                ':stored_name' => $storedName,
                ':file_size' => $size
            ]);
        }
    }

    $mail->send();

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
