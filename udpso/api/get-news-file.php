<?php
require_once __DIR__ . '/../config.php';

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        exit('Bad request');
    }

    $stmt = $pdo->prepare("SELECT file_name, mime_type, file_data FROM news_files WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        http_response_code(404);
        exit('Not found');
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
