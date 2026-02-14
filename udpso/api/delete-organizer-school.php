<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

session_start();

try {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'organizer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = json_decode(file_get_contents('php://input'), true);
    $schoolId = (int)($raw['school_id'] ?? 0);

    if ($schoolId <= 0) {
        throw new RuntimeException('Не передан идентификатор школы');
    }

    $checkStmt = $pdo->prepare("\n      SELECT id\n      FROM approved_schools\n      WHERE id = :school_id\n        AND approved_by = :organizer_id\n      LIMIT 1\n    ");
    $checkStmt->execute([
        ':school_id' => $schoolId,
        ':organizer_id' => (int)$_SESSION['user_id']
    ]);

    if (!$checkStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Школа не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS organizer_school_documents (\n        id BIGSERIAL PRIMARY KEY,\n        school_id BIGINT NOT NULL REFERENCES approved_schools(id) ON DELETE CASCADE,\n        original_name TEXT NOT NULL,\n        stored_name TEXT NOT NULL,\n        file_size BIGINT NOT NULL DEFAULT 0,\n        uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()\n      )\n    ");

    $docStmt = $pdo->prepare("SELECT stored_name FROM organizer_school_documents WHERE school_id = :school_id");
    $docStmt->execute([':school_id' => $schoolId]);
    $storedNames = $docStmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->beginTransaction();

    $deleteSchool = $pdo->prepare("DELETE FROM approved_schools WHERE id = :school_id");
    $deleteSchool->execute([':school_id' => $schoolId]);

    $pdo->commit();

    if (!empty($storedNames)) {
        $uploadDir = __DIR__ . '/../uploads/organizer-school-documents';
        foreach ($storedNames as $storedName) {
            $path = $uploadDir . '/' . basename($storedName);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
