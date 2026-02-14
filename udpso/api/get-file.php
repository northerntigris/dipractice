<?php
// api/get-file.php
require_once __DIR__ . '/../config.php';
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        exit('Unauthorized');
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $type = $_GET['type'] ?? '';

    if ($id <= 0 || !in_array($type, ['task', 'work', 'appeal', 'appeal_response'], true)) {
        http_response_code(400);
        exit('Bad request');
    }

    if ($type === 'task') {
        $stmt = $pdo->prepare("SELECT file_name, mime_type, file_data FROM olympiad_task_files WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
    } elseif ($type === 'appeal') {
        $stmt = $pdo->prepare("SELECT a.olympiad_id, af.file_name, af.mime_type, af.file_data, a.student_id
            FROM appeal_files af
            JOIN appeals a ON a.id = af.appeal_id
            WHERE af.id = ? LIMIT 1");
        $stmt->execute([$id]);
    } elseif ($type === 'appeal_response') {
        $stmt = $pdo->prepare("SELECT a.olympiad_id, arf.file_name, arf.mime_type, arf.file_data, a.student_id
            FROM appeal_response_files arf
            JOIN appeals a ON a.id = arf.appeal_id
            WHERE arf.id = ? LIMIT 1");
        $stmt->execute([$id]);
    } else {
        $hasWorkPublished = (bool)$pdo->query("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema='public' AND table_name='participant_work_files' AND column_name='is_published'
            LIMIT 1
        ")->fetchColumn();

        $workSelect = $hasWorkPublished
            ? "SELECT olympiad_id, file_name, mime_type, file_data, is_published FROM participant_work_files WHERE id = ? LIMIT 1"
            : "SELECT olympiad_id, file_name, mime_type, file_data, NULL::boolean AS is_published FROM participant_work_files WHERE id = ? LIMIT 1";

        $stmt = $pdo->prepare($workSelect);
        $stmt->execute([$id]);
    }

    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        http_response_code(404);
        exit('Not found');
    }

    if ($type === 'work' && array_key_exists('is_published', $file) && $file['is_published'] === false) {
        $juryCheck = $pdo->prepare("
            SELECT 1
            FROM olympiad_jury oj
            JOIN jury_members jm ON oj.jury_member_id = jm.id
            WHERE oj.olympiad_id = ? AND jm.user_id = ?
            LIMIT 1
        ");
        $juryCheck->execute([(int)$file['olympiad_id'], (int)$_SESSION['user_id']]);
        if (!$juryCheck->fetchColumn()) {
            http_response_code(403);
            exit('Forbidden');
        }
    }


    if (($type === 'appeal' || $type === 'appeal_response') && isset($file['olympiad_id'])) {
        $userId = (int)$_SESSION['user_id'];
        $isStudentOwner = isset($file['student_id']) && (int)$file['student_id'] === $userId;

        $juryCheck = $pdo->prepare("SELECT 1
            FROM olympiad_jury oj
            JOIN jury_members jm ON jm.id = oj.jury_member_id
            WHERE jm.user_id = :user_id
              AND oj.olympiad_id IN (
                SELECT id FROM olympiads WHERE id = :olympiad_id OR template_id = :olympiad_id
                UNION
                SELECT template_id FROM olympiads WHERE id = :olympiad_id AND template_id IS NOT NULL
              )
            LIMIT 1");
        $juryCheck->execute([':user_id' => $userId, ':olympiad_id' => (int)$file['olympiad_id']]);
        $isJury = (bool)$juryCheck->fetchColumn();

        $schoolCheck = $pdo->prepare("SELECT 1
            FROM users owner_u
            JOIN users current_u ON current_u.id = :user_id
            WHERE owner_u.id = :student_id
              AND current_u.role IN ('school', 'school_coordinator')
              AND owner_u.school_id = current_u.school_id
            LIMIT 1");
        $schoolCheck->execute([':user_id' => $userId, ':student_id' => (int)$file['student_id']]);
        $isSchool = (bool)$schoolCheck->fetchColumn();

        if (!$isStudentOwner && !$isJury && !$isSchool) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    $fileName = $file['file_name'] ?: 'file';
    $mimeType = $file['mime_type'] ?: 'application/octet-stream';
    $fileData = $file['file_data'];

    if (is_resource($fileData)) {
        $fileData = stream_get_contents($fileData);
    } elseif (is_string($fileData)) {
        if (str_starts_with($fileData, '\\x')) {
            $fileData = hex2bin(substr($fileData, 2));
        } elseif (function_exists('pg_unescape_bytea')) {
            $fileData = pg_unescape_bytea($fileData);
        }
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    if (is_string($fileData)) {
        header('Content-Length: ' . strlen($fileData));
    }
    echo $fileData;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Server error');
}
?>
