<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

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

    if ($userId) {
        $stmt = $pdo->prepare("SELECT id FROM support_chats WHERE user_id = ? ORDER BY id LIMIT 1");
        $stmt->execute([$userId]);
        $chatId = $stmt->fetchColumn();

        if (!$chatId) {
            $insert = $pdo->prepare("INSERT INTO support_chats (user_id) VALUES (?) RETURNING id");
            $insert->execute([$userId]);
            $chatId = $insert->fetchColumn();
        }
    } else {
        if (!isset($_SESSION['support_guest_name'])) {
            $_SESSION['support_guest_name'] = 'Гость ' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $guestName = $_SESSION['support_guest_name'];
        $chatId = $_SESSION['support_guest_chat_id'] ?? null;

        if ($chatId) {
            $check = $pdo->prepare("SELECT 1 FROM support_chats WHERE id = ? AND guest_name = ? LIMIT 1");
            $check->execute([(int)$chatId, $guestName]);
            if (!$check->fetchColumn()) {
                $chatId = null;
            }
        }

        if (!$chatId) {
            $insert = $pdo->prepare("INSERT INTO support_chats (guest_name) VALUES (?) RETURNING id");
            $insert->execute([$guestName]);
            $chatId = $insert->fetchColumn();
            $_SESSION['support_guest_chat_id'] = (int)$chatId;
        }
    }

    echo json_encode(['success' => true, 'chat_id' => (int)$chatId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки чата поддержки'], JSON_UNESCAPED_UNICODE);
}
