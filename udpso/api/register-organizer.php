<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../PHPMailer.php';
require_once '../SMTP.php';
require_once '../Exception.php';
require_once __DIR__ . '/../config.php';

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Метод не поддерживается'], 405);
}

// Поля из join-platform-modal.js
$full_name         = trim((string)($_POST['full_name'] ?? ''));
$address           = trim((string)($_POST['address'] ?? ''));
$ogrn              = trim((string)($_POST['ogrn'] ?? ''));
$registration_date = trim((string)($_POST['registration_date'] ?? ''));
$director_fio      = trim((string)($_POST['director_fio'] ?? ''));
$director_inn      = trim((string)($_POST['director_inn'] ?? ''));
$director_position = trim((string)($_POST['director_position'] ?? ''));
$contact_email = trim((string)($_POST['contact_email'] ?? ''));
$contact_phone = trim((string)($_POST['contact_phone'] ?? ''));


$errors = [];
if ($full_name === '') $errors[] = 'Укажите полное наименование';
if ($address === '') $errors[] = 'Укажите адрес';
if ($ogrn === '') $errors[] = 'Укажите ОГРН';
if ($registration_date === '') $errors[] = 'Укажите дату регистрации';
if ($director_fio === '') $errors[] = 'Укажите ФИО руководителя';
if ($director_inn === '') $errors[] = 'Укажите ИНН руководителя';
if ($director_position === '') $errors[] = 'Укажите должность руководителя';

if ($ogrn !== '' && !preg_match('/^\d{13}$/', $ogrn)) {
    $errors[] = 'ОГРН должен состоять из 13 цифр';
}
if ($director_inn !== '' && !preg_match('/^\d{10}|\d{12}$/', $director_inn)) {
    // Если хочешь строго 12 цифр — поменяй на /^\d{12}$/
    $errors[] = 'ИНН руководителя должен состоять из 10 или 12 цифр';
}
if ($registration_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $registration_date)) {
    $errors[] = 'Дата регистрации должна быть в формате ГГГГ-ММ-ДД';
}
if ($contact_email === '' || !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Укажите корректный email для связи';
}
if ($contact_phone === '') {
    $errors[] = 'Укажите контактный телефон';
}

if ($errors) {
    json_response(['success' => false, 'message' => implode('. ', $errors)], 400);
}

try {
    $pdo->beginTransaction();

    // 1) создаём заявку
    $stmt = $pdo->prepare("
        INSERT INTO organizer_registrations
            (full_name, address, ogrn, registration_date, director_fio, director_inn, director_position, contact_email, contact_phone)
        VALUES
            (:full_name, :address, :ogrn, :registration_date, :director_fio, :director_inn, :director_position, :contact_email, :contact_phone)
        RETURNING id
    ");
    $stmt->execute([
        ':full_name' => $full_name,
        ':address' => $address,
        ':ogrn' => $ogrn,
        ':registration_date' => $registration_date,
        ':director_fio' => $director_fio,
        ':director_inn' => $director_inn,
        ':director_position' => $director_position,
        ':contact_email' => $contact_email,
        ':contact_phone' => $contact_phone,
    ]);

    $registration_id = (int)$stmt->fetchColumn();

    // 2) сохраняем документы (если есть)
    $uploadDir = __DIR__ . '/../uploads/organizer-verification-documents';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    // Форма должна отправлять verification_documents[] (multiple)
    if (isset($_FILES['verification_documents'])) {
        $files = $_FILES['verification_documents'];

        // Если отправлен один файл без []
        $isMulti = is_array($files['name']);

        $count = $isMulti ? count($files['name']) : 1;
        for ($i = 0; $i < $count; $i++) {
            $error = $isMulti ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Ошибка загрузки файла (код ' . $error . ')');
            }

            $tmpName = $isMulti ? $files['tmp_name'][$i] : $files['tmp_name'];
            $origName = $isMulti ? $files['name'][$i] : $files['name'];
            $size = (int)($isMulti ? $files['size'][$i] : $files['size']);

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            // Разрешения можно расширить/ужесточить
            $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];
            if ($ext !== '' && !in_array($ext, $allowedExt, true)) {
                throw new RuntimeException('Недопустимый формат файла: ' . $origName);
            }

            $rand = bin2hex(random_bytes(8));
            $storedName = "orgreg_{$registration_id}_{$rand}" . ($ext ? ".{$ext}" : "");
            $destPath = $uploadDir . '/' . $storedName;

            if (!move_uploaded_file($tmpName, $destPath)) {
                throw new RuntimeException('Не удалось сохранить файл: ' . $origName);
            }

            $stmtFile = $pdo->prepare("
                INSERT INTO organizer_registration_files
                    (registration_id, original_name, stored_name, file_size)
                VALUES
                    (:registration_id, :original_name, :stored_name, :file_size)
            ");
            $stmtFile->execute([
                ':registration_id' => $registration_id,
                ':original_name' => $origName,
                ':stored_name' => $storedName,
                ':file_size' => $size,
            ]);
        }
    }

    $pdo->commit();

    // Отправка подтверждения на email заявителя
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.yandex.ru';
        $mail->SMTPAuth = true;
        $mail->Username = 'northerntigris@yandex.ru';
        $mail->Password = 'xjzfqmgiwhfwwber';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->setFrom('northerntigris@yandex.ru', 'Платформа школьных олимпиад');
        $mail->addAddress($contact_email);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $mail->Subject = 'Заявка на присоединение к платформе получена';
        $mail->Body = "
            <h2>Заявка получена</h2>
            <p>Мы получили вашу заявку на присоединение к платформе школьных олимпиад.</p>

            <h3>Данные заявки</h3>
            <p>
                <b>Организация:</b> {$full_name}<br>
                <b>ОГРН:</b> {$ogrn}<br>
                <b>Руководитель:</b> {$director_fio}, {$director_position}<br>
                <b>Телефон:</b> {$contact_phone}<br>
                <b>Email:</b> {$contact_email}
            </p>

            <p>Статус заявки: <b>На рассмотрении</b>.</p>
        ";

        $mail->send();
    } catch (Throwable $mailErr) {
        // письмо не должно ломать регистрацию заявки
        // можно логировать $mailErr->getMessage() при желании
    }


    json_response([
        'success' => true,
        'message' => 'Заявка отправлена',
        'registration_id' => $registration_id
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()], 500);
}
?>