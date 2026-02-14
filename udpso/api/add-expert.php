<?php
// api/add-expert.php

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // При желании включи проверку ролей:
    // if (!in_array($_SESSION['user_role'] ?? '', ['admin','organizer'], true)) { ... }

    // JSON или POST
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $fullName   = trim((string)($data['full_name'] ?? ''));
    $email      = trim((string)($data['email'] ?? ''));
    $snils      = trim((string)($data['snils'] ?? ''));

    $username   = trim((string)($data['username'] ?? ''));
    $password   = (string)($data['password'] ?? '');

    // Данные члена жюри (jury_members)
    $organization       = trim((string)($data['organization'] ?? ''));
    $passportSeries     = trim((string)($data['passport_series'] ?? ''));
    $passportNumber     = trim((string)($data['passport_number'] ?? ''));
    $passportIssuedBy   = trim((string)($data['passport_issued_by'] ?? ''));
    $passportIssuedDate = trim((string)($data['passport_issued_date'] ?? '')); // YYYY-MM-DD
    $birthdate          = trim((string)($data['birthdate'] ?? ''));            // YYYY-MM-DD

    // (опционально) привязка к олимпиаде
    $olympiadId = isset($data['olympiad_id']) ? (int)$data['olympiad_id'] : null;
    $juryRole   = trim((string)($data['jury_role'] ?? 'член жюри'));

    // Валидация
    if (
        $fullName === '' ||
        $email === '' ||
        $snils === '' ||
        $organization === '' ||
        $passportSeries === '' ||
        $passportNumber === '' ||
        $passportIssuedBy === '' ||
        $passportIssuedDate === '' ||
        $birthdate === '' ||
        $juryRole === ''
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($olympiadId === null || $olympiadId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указана олимпиада'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $role = $_SESSION['user_role'] ?? '';
    $schoolId = null;
    if (in_array($role, ['school', 'school_coordinator'], true)) {
    // школа должна иметь сохранённые дату/классы для этой олимпиады
        $stmtSchool = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
        $stmtSchool->execute([$_SESSION['user_id']]);
        $schoolId = (int)$stmtSchool->fetchColumn();

        if ($schoolId > 0) {
            $scheduledAt = null;
            $gradesStr = '';

            $st = $pdo->prepare("
                SELECT scheduled_at, grades
                FROM school_olympiads
                WHERE school_id = :sid AND olympiad_id = :oid
                LIMIT 1
            ");
            $st->execute([':sid' => $schoolId, ':oid' => $olympiadId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($row) {
                $scheduledAt = $row['scheduled_at'] ?? null;
                $gradesStr = trim((string)($row['grades'] ?? ''));
            }

            if (!$scheduledAt || $gradesStr === '') {
                $olStmt = $pdo->prepare("
                    SELECT datetime, grades
                    FROM olympiads
                    WHERE id = :oid AND school_id = :sid
                    LIMIT 1
                ");
                $olStmt->execute([':oid' => $olympiadId, ':sid' => $schoolId]);
                $olRow = $olStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($olRow) {
                    $scheduledAt = $scheduledAt ?: ($olRow['datetime'] ?? null);
                    if ($gradesStr === '') {
                        $gradesStr = trim((string)($olRow['grades'] ?? ''));
                    }
                }
            }

            if (!$scheduledAt || $gradesStr === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Сначала заполните дату проведения и классы олимпиады'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
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

    if (!preg_match('/^\\d{4}$/', $passportSeries) || !preg_match('/^\\d{6}$/', $passportNumber)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Паспорт должен содержать серию из 4 цифр и номер из 6 цифр'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingUser = null;
    $stmtExisting = $pdo->prepare("SELECT id, username FROM users WHERE snils = ? LIMIT 1");
    $stmtExisting->execute([$snilsDigits]);
    $existingUser = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$existingUser) {
        if ($username === '') {
            $username = 'jury_' . time() . '_' . random_int(1000, 9999);
        }

        if ($password === '') {
            $password = bin2hex(random_bytes(4));
        }
    }

    $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;

    $pdo->beginTransaction();

    $userId = null;
    $existingUserId = $existingUser ? (int)$existingUser['id'] : null;

    if ($existingUserId) {
        $userId = $existingUserId;
        if ($username === '') {
            $username = (string)($existingUser['username'] ?? '');
        }

        $stmtUpdateUser = $pdo->prepare("
            UPDATE users
            SET full_name = ?, email = ?, snils = ?, school_id = COALESCE(?, school_id)
            WHERE id = ?
        ");
        $stmtUpdateUser->execute([
            $fullName,
            $email,
            $snilsDigits,
            ($schoolId > 0 ? $schoolId : null),
            $userId
        ]);
    } else {
        // 1) users: создаём пользователя и возвращаем id
        $stmtUser = $pdo->prepare("
            INSERT INTO users (username, password, role, full_name, email, snils, school_id)
            VALUES (:username, :password, 'expert', :full_name, :email, :snils, :school_id)
            RETURNING id
        ");
        $stmtUser->execute([
            ':username'  => $username,
            ':password'  => $passwordHash,
            ':full_name' => $fullName,
            ':email'     => $email,
            ':snils'     => $snilsDigits,
            ':school_id' => ($schoolId > 0 ? $schoolId : null),
        ]);

        $userId = (int)$stmtUser->fetchColumn();
    }

    // 2) jury_members: создаём или обновляем профиль жюри
    $stmtJuryExisting = $pdo->prepare("SELECT id FROM jury_members WHERE user_id = ? LIMIT 1");
    $stmtJuryExisting->execute([$userId]);
    $juryMemberId = (int)($stmtJuryExisting->fetchColumn() ?: 0);

    if ($juryMemberId > 0) {
        $stmtJuryUpdate = $pdo->prepare("
            UPDATE jury_members
            SET organization = ?, passport_series = ?, passport_number = ?,
                passport_issued_by = ?, passport_issued_date = ?, birthdate = ?
            WHERE id = ?
        ");
        $stmtJuryUpdate->execute([
            $organization,
            $passportSeries,
            $passportNumber,
            $passportIssuedBy,
            $passportIssuedDate,
            $birthdate,
            $juryMemberId
        ]);
    } else {
        $stmtJury = $pdo->prepare("
            INSERT INTO jury_members (
                user_id, organization,
                passport_series, passport_number,
                passport_issued_by, passport_issued_date,
                birthdate
            )
            VALUES (
                :user_id, :organization,
                :passport_series, :passport_number,
                :passport_issued_by, :passport_issued_date,
                :birthdate
            )
            RETURNING id
        ");

        $stmtJury->execute([
            ':user_id'             => $userId,
            ':organization'        => $organization,
            ':passport_series'     => $passportSeries,
            ':passport_number'     => $passportNumber,
            ':passport_issued_by'  => $passportIssuedBy,
            ':passport_issued_date'=> $passportIssuedDate,
            ':birthdate'           => $birthdate,
        ]);

        $juryMemberId = (int)$stmtJury->fetchColumn();
    }

    // 3) (опционально) связка с олимпиадой
    if ($olympiadId !== null && $olympiadId > 0) {
        $stmtLink = $pdo->prepare("
            INSERT INTO olympiad_jury (olympiad_id, jury_member_id, jury_role)
            VALUES (:olympiad_id, :jury_member_id, :jury_role)
            ON CONFLICT (olympiad_id, jury_member_id)
            DO UPDATE SET jury_role = EXCLUDED.jury_role
        ");
        $stmtLink->execute([
            ':olympiad_id'   => $olympiadId,
            ':jury_member_id'=> $juryMemberId,
            ':jury_role'     => ($juryRole !== '' ? $juryRole : 'член жюри'),
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'user_id'        => $userId,
        'jury_member_id' => $juryMemberId,
        'login'          => $username,
        'password'       => $existingUserId ? null : $password,
        'existing_user'  => (bool)$existingUserId
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Postgres unique violation: 23505 (например username UNIQUE)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Ошибка добавления члена жюри',
        // 'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>
