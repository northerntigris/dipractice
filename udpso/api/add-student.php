<?php
// api/add-student.php

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Данные могут приходить как JSON (fetch) или как обычный POST form-data
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $fullName = trim((string)($data['full_name'] ?? ''));
    $age      = isset($data['age']) ? (int)$data['age'] : null;
    $grade    = isset($data['grade']) ? (int)$data['grade'] : null;
    $olympiadId = isset($data['olympiad_id']) ? (int)$data['olympiad_id'] : null;

    $snils    = trim((string)($data['snils'] ?? ''));
    $email    = trim((string)($data['email'] ?? ''));

    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    // Школа обычно берётся от организатора (как у тебя по проекту)
    $schoolId = isset($_SESSION['school_id']) ? (int)$_SESSION['school_id'] : null;
    if ($schoolId === null || $schoolId <= 0) {
        $stmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $schoolId = (int)$stmt->fetchColumn();
    }

    if ($schoolId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не определена школа организатора'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация
    if ($fullName === '' || $grade === null || $age === null || !$olympiadId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

        // Для школы/координатора: нельзя добавлять, пока не заполнены дата/классы проведения
    $role = $_SESSION['user_role'] ?? '';
    if (in_array($role, ['school', 'school_coordinator'], true)) {
        $scheduledAt = null;
        $gradesStr = '';

        // 1) Прямое назначение по шаблону (school_olympiads)
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

        // 2) Уже созданная школьная олимпиада (олимпиада в таблице olympiads с school_id)
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

        // Парсим gradesStr -> допустимые классы
        $allowed = [];
        $clean = preg_replace('/\\s+/', '', $gradesStr);
        foreach (preg_split('/[;,]+/', $clean) as $part) {
            if ($part === '') continue;
            if (strpos($part, '-') !== false) {
                [$a, $b] = array_map('intval', explode('-', $part, 2));
                $from = min($a, $b);
                $to   = max($a, $b);
                for ($g = $from; $g <= $to; $g++) {
                    if ($g >= 1 && $g <= 11) $allowed[$g] = true;
                }
            } else {
                $g = (int)$part;
                if ($g >= 1 && $g <= 11) $allowed[$g] = true;
            }
        }

        if (!isset($allowed[(int)$grade])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Класс участника не входит в заданные классы олимпиады'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }


    $snilsDigits = preg_replace('/\\D+/', '', $snils);
    if ($snilsDigits !== '' && strlen($snilsDigits) !== 11) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'СНИЛС должен содержать 11 цифр'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingStudent = null;
    if ($snilsDigits !== '') {
        $stmtExisting = $pdo->prepare("SELECT id, username, role FROM users WHERE snils = ? LIMIT 1");
        $stmtExisting->execute([$snilsDigits]);
        $existingBySnils = $stmtExisting->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($existingBySnils && ($existingBySnils['role'] ?? '') !== 'student') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Пользователь с таким СНИЛС уже зарегистрирован с другой ролью'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $existingStudent = $existingBySnils;
    }

    if (!$existingStudent) {
        if ($username === '') {
            $username = 'student_' . time() . '_' . random_int(1000, 9999);
        }

        if ($password === '') {
            $password = bin2hex(random_bytes(4));
        }
    }

    // Хеш пароля
    $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;

    $pdo->beginTransaction();

    $studentId = null;
    $existingStudentId = $existingStudent ? (int)$existingStudent['id'] : null;

    if ($existingStudentId) {
        $studentId = $existingStudentId;
        if ($username === '') {
            $username = (string)($existingStudent['username'] ?? '');
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE users
            SET full_name = ?, age = ?, grade = ?, snils = ?, email = ?, school_id = COALESCE(?, school_id)
            WHERE id = ?
        ");
        $stmtUpdate->execute([
            $fullName,
            $age,
            $grade,
            ($snilsDigits !== '' ? $snilsDigits : null),
            ($email !== '' ? $email : null),
            $schoolId,
            $studentId
        ]);
    } else {
        // Вставка пользователя-ученика с RETURNING
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, role, full_name, age, grade, snils, email, school_id)
            VALUES (:username, :password, 'student', :full_name, :age, :grade, :snils, :email, :school_id)
            RETURNING id
        ");

        $stmt->execute([
            ':username'  => $username,
            ':password'  => $passwordHash,
            ':full_name' => $fullName,
            ':age'       => $age,
            ':grade'     => $grade,
            ':snils'     => ($snilsDigits !== '' ? $snilsDigits : null),
            ':email'     => ($email !== '' ? $email : null),
            ':school_id' => $schoolId
        ]);

        $studentId = (int)$stmt->fetchColumn();
    }

    $hasSchoolColumn = false;
    $columnStmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'olympiad_participants'
          AND column_name = 'school_id'
        LIMIT 1
    ");
    $columnStmt->execute();
    $hasSchoolColumn = (bool)$columnStmt->fetchColumn();

    $checkParticipant = $pdo->prepare("
        SELECT 1 FROM olympiad_participants WHERE olympiad_id = ? AND student_id = ? LIMIT 1
    ");
    $checkParticipant->execute([$olympiadId, $studentId]);
    $participantExists = (bool)$checkParticipant->fetchColumn();

    if (!$participantExists) {
        if ($hasSchoolColumn) {
            $participantStmt = $pdo->prepare("
                INSERT INTO olympiad_participants (olympiad_id, student_id, score, school_id)
                VALUES (:olympiad_id, :student_id, NULL, :school_id)
            ");
            $participantStmt->execute([
                ':olympiad_id' => $olympiadId,
                ':student_id' => $studentId,
                ':school_id' => $schoolId
            ]);
        } else {
            $participantStmt = $pdo->prepare("
                INSERT INTO olympiad_participants (olympiad_id, student_id, score)
                VALUES (:olympiad_id, :student_id, NULL)
            ");
            $participantStmt->execute([
                ':olympiad_id' => $olympiadId,
                ':student_id' => $studentId
            ]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'student_id' => $studentId,
        'existing_user' => (bool)$existingStudentId
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Частая ошибка при UNIQUE(username)
    // Postgres: SQLSTATE 23505 unique_violation
    $code = (int)http_response_code();
    if ($code < 400) $code = 500;

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка добавления ученика',
        // 'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>
