<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../PHPMailer.php';
require_once '../SMTP.php';
require_once '../Exception.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
session_start();


try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'school') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $fullName = trim((string)($data['full_name'] ?? ''));
    $position = trim((string)($data['position'] ?? ''));
    $snils = trim((string)($data['snils'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));

    if ($fullName === '' || $position === '' || $snils === '' || $email === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Некорректный email'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $snilsDigits = preg_replace('/\\D+/', '', $snils);
    if (strlen($snilsDigits) !== 11) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'СНИЛС должен содержать 11 цифр'], JSON_UNESCAPED_UNICODE);
        exit;
    }



    $schoolStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
    $schoolStmt->execute([$_SESSION['user_id']]);
    $schoolId = $schoolStmt->fetchColumn();
    if (!$schoolId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Данные школы + кто создал координатора ---
    $schoolInfoStmt = $pdo->prepare("SELECT full_name, short_name FROM approved_schools WHERE id = ?");
    $schoolInfoStmt->execute([$schoolId]);
    $schoolInfo = $schoolInfoStmt->fetch(PDO::FETCH_ASSOC);

    $actorName = 'Представитель школы';
    $actorPosition = 'Сотрудник школы';

    $actorStmt = $pdo->prepare("SELECT role, COALESCE(full_name, username) AS actor_name FROM users WHERE id = ? LIMIT 1");
    $actorStmt->execute([$_SESSION['user_id']]);
    $actor = $actorStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if (($actor['role'] ?? '') === 'school') {
        $directorStmt = $pdo->prepare("SELECT director_fio, director_position FROM approved_schools WHERE id = ? LIMIT 1");
        $directorStmt->execute([$schoolId]);
        $director = $directorStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $actorName = trim((string)($director['director_fio'] ?? '')) ?: (string)($actor['actor_name'] ?? 'Представитель школы');
        $actorPosition = trim((string)($director['director_position'] ?? '')) ?: 'Руководитель образовательного учреждения';
    } else {
        $actorName = (string)($actor['actor_name'] ?? 'Представитель школы');
        $actorPosition = 'Школьный координатор';
    }

    $schoolTitle = 'ID школы: ' . $schoolId;
    if ($schoolInfo) {
        $short = trim((string)($schoolInfo['short_name'] ?? ''));
        $full  = trim((string)($schoolInfo['full_name'] ?? ''));
        if ($short !== '' && $full !== '') {
            $schoolTitle = $short . ' (' . $full . ')';
        } elseif ($full !== '') {
            $schoolTitle = $full;
        } elseif ($short !== '') {
            $schoolTitle = $short;
        }
    }


    $username = 'coord_' . time() . '_' . random_int(1000, 9999);
    $password = bin2hex(random_bytes(4));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role, full_name, email, snils, school_id)
        VALUES (:username, :password, 'school_coordinator', :full_name, :email, :snils, :school_id)
        RETURNING id
    ");
    $stmt->execute([
        ':username' => $username,
        ':password' => $passwordHash,
        ':full_name' => $fullName,
        ':email' => $email,
        ':snils' => $snilsDigits,
        ':school_id' => $schoolId
    ]);

    $coordinatorId = (int)$stmt->fetchColumn();

    $activityTitle = 'Добавлен координатор: ' . $fullName . ' (' . $position . ')';
    $activityStmt = $pdo->prepare("
        INSERT INTO activities (user_id, type, title, created_at)
        VALUES (:user_id, 'user_created', :title, NOW())
    ");
    $activityStmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':title' => $activityTitle
    ]);

    // --- Отправка письма координатору ---
    $emailSent = false;
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
        $mail->addAddress($email, $fullName);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Регистрация координатора школы на платформе школьных олимпиад';

        $safeFullName  = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePosition  = htmlspecialchars($position, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSchool    = htmlspecialchars($schoolTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActor     = htmlspecialchars($actorName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActorPosition = htmlspecialchars($actorPosition, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLogin     = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePassword  = htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $mail->Body = "
            <h2>Учетная запись координатора создана</h2>
            <p>Здравствуйте, <b>{$safeFullName}</b>!</p>

            <p>В системе сформирована учетная запись <b>координатора школы</b>.</p>

            <p><b>Школа:</b> {$safeSchool}<br>
            <b>Должность:</b> {$safePosition}<br>
            <b>Регистрацию выполнил:</b> {$safeActorPosition}, {$safeActor}</p>

            <h3>Данные для входа</h3>
            <p><b>Логин:</b> {$safeLogin}<br>
            <b>Пароль:</b> {$safePassword}</p>

            <p style='margin-top:16px'>
                Рекомендуем сменить пароль после первого входа.<br>
                Если вы не ожидали это письмо — просто проигнорируйте его.
            </p>
        ";

        $mail->send();
        $emailSent = true;
    } catch (Throwable $mailErr) {
        // письмо не критично: аккаунт создан, но emailSent останется false
    }


    echo json_encode([
        'success' => true,
        'user_id' => $coordinatorId,
        'login' => $username,
        'email_sent' => $emailSent
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка добавления координатора'], JSON_UNESCAPED_UNICODE);
}
