<?php
require_once '../config.php';

session_start();

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'organizer') {
        http_response_code(403);
        echo 'Доступ запрещен';
        exit;
    }

    $documentId = (int)($_GET['id'] ?? 0);
    if ($documentId <= 0) {
        http_response_code(400);
        echo 'Некорректный идентификатор документа';
        exit;
    }


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

    $stmt = $pdo->prepare("\n      SELECT d.original_name, d.stored_name\n      FROM organizer_school_documents d\n      JOIN approved_schools s ON s.id = d.school_id\n      WHERE d.id = :document_id\n        AND s.approved_by = :organizer_id\n      LIMIT 1\n    ");
    $stmt->execute([
        ':document_id' => $documentId,
        ':organizer_id' => (int)$_SESSION['user_id']
    ]);

    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        http_response_code(404);
        echo 'Документ не найден';
        exit;
    }

    $filePath = __DIR__ . '/../uploads/organizer-school-documents/' . basename($doc['stored_name']);
    if (!is_file($filePath)) {
        http_response_code(404);
        echo 'Файл не найден';
        exit;
    }

    $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name']) . '"');

    readfile($filePath);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ошибка при выдаче документа';
}
?>
