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

    $nullableStmt = $pdo->prepare("
        SELECT is_nullable
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'support_chats'
          AND column_name = 'user_id'
        LIMIT 1
    ");
    $nullableStmt->execute();
    if ($nullableStmt->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE support_chats ALTER COLUMN user_id DROP NOT NULL");
    }

    $nullableStmt = $pdo->prepare("
        SELECT is_nullable
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'support_messages'
          AND column_name = 'sender_id'
        LIMIT 1
    ");
    $nullableStmt->execute();
    if ($nullableStmt->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE support_messages ALTER COLUMN sender_id DROP NOT NULL");
    }

    $pdo->exec("UPDATE support_messages SET is_read = false WHERE is_read IS NULL");

    $stmt = $pdo->query("
        SELECT c.id, c.user_id, COALESCE(u.full_name, c.guest_name) AS full_name, c.last_message_at
        FROM support_chats c
        LEFT JOIN users u ON u.id = c.user_id
        ORDER BY c.last_message_at DESC NULLS LAST, c.id DESC
    ");
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'chats' => $chats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки чатов поддержки'], JSON_UNESCAPED_UNICODE);
}
