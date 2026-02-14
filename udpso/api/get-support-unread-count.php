<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $role = $_SESSION['user_role'];
    if (!in_array($role, ['admin', 'moderator'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_chats (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        guest_name TEXT,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        last_message_at TIMESTAMPTZ
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id SERIAL PRIMARY KEY,
        chat_id INTEGER NOT NULL REFERENCES support_chats(id) ON DELETE CASCADE,
        sender_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        sender_role TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN NOT NULL DEFAULT false,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("ALTER TABLE support_chats ADD COLUMN IF NOT EXISTS guest_name TEXT");
    $pdo->exec("ALTER TABLE support_messages ADD COLUMN IF NOT EXISTS is_read BOOLEAN NOT NULL DEFAULT false");
    $pdo->exec("UPDATE support_messages SET is_read = false WHERE is_read IS NULL");

    $stmt = $pdo->query("
        SELECT COUNT(*) AS unread_count
        FROM support_messages
        WHERE sender_role NOT IN ('admin', 'moderator')
          AND is_read = false
    ");
    $count = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'count' => $count], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки счётчика'], JSON_UNESCAPED_UNICODE);
}
