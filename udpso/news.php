<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo->exec("ALTER TABLE news ADD COLUMN IF NOT EXISTS author_role text");
    $pdo->exec("ALTER TABLE news ADD COLUMN IF NOT EXISTS author_id bigint");
    $pdo->exec("UPDATE news SET author_role = 'admin' WHERE author_role IS NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_files (
        id BIGSERIAL PRIMARY KEY,
        news_id BIGINT REFERENCES news(id) ON DELETE CASCADE,
        file_name TEXT,
        mime_type TEXT,
        file_data BYTEA,
        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    switch ($method) {
        case 'GET':
            // Получение списка новостей - доступно всем
            $region = isset($_GET['region']) ? trim((string)$_GET['region']) : '';

            $where = 'WHERE 1 = 0';
            $params = [];
            if ($region === 'all') {
                $where = '';
            } elseif ($region !== '') {
                $where = 'WHERE s.region = :region';
                $params[':region'] = $region;
            }

            $stmt = $pdo->prepare("
                SELECT
                    n.*,
                    COALESCE(
                        (
                            SELECT json_agg(json_build_object('id', nf.id, 'name', nf.file_name))
                            FROM news_files nf
                            WHERE nf.news_id = n.id
                        ),
                        '[]'::json
                    ) AS files,
                    CASE
                        WHEN n.author_role = 'admin' THEN 'Администратор системы'
                        WHEN n.author_role = 'organizer' THEN COALESCE(org.full_name, 'Организатор')
                        WHEN n.author_role = 'school' THEN COALESCE(s.short_name, s.full_name, 'Школа')
                        ELSE 'Администратор системы'
                    END AS author_display,
                    s.region AS author_region
                FROM news n
                LEFT JOIN approved_organizations org ON org.user_id = n.author_id
                LEFT JOIN approved_schools s ON s.user_id = n.author_id
                {$where}
                ORDER BY n.created_at DESC
            ");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'news' => $stmt->fetchAll()]);
            break;
            
        case 'POST':
            // Создание или обновление новости
            if (!isset($_SESSION['user_id'])) {
                die(json_encode(['success' => false, 'error' => 'Требуется авторизация']));
            }
            
            if (!in_array($_SESSION['user_role'], ['admin', 'moderator', 'organizer', 'school', 'school_coordinator'])) {
                die(json_encode(['success' => false, 'error' => 'Недостаточно прав']));
            }
            
            $isMultipart = isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'multipart/form-data');
            if ($isMultipart) {
                $id = $_POST['id'] ?? null;
                $title = $_POST['title'] ?? '';
                $content = $_POST['content'] ?? '';
                $date = $_POST['date'] ?? date('Y-m-d H:i:s');
            } else {
                $input = json_decode(file_get_contents('php://input'), true);
                $id = $input['id'] ?? null;
                $title = $input['title'] ?? '';
                $content = $input['content'] ?? '';
                $date = $input['date'] ?? date('Y-m-d H:i:s');
            }
            
            $role = $_SESSION['user_role'];
            $authorRole = in_array($role, ['admin', 'moderator'], true) ? 'admin' : ($role === 'organizer' ? 'organizer' : 'school');
            $authorId = (int)$_SESSION['user_id'];

            if ($id) {
                $stmt = $pdo->prepare("UPDATE news SET title = ?, content = ?, created_at = ? WHERE id = ?");
                $stmt->execute([$title, $content, $date, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO news (title, content, created_at, author_role, author_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $content, $date, $authorRole, $authorId]);
                $id = $pdo->lastInsertId();
            }

            if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
                $files = $_FILES['files'];
                $fileInsert = $pdo->prepare("
                    INSERT INTO news_files (news_id, file_name, mime_type, file_data)
                    VALUES (:news_id, :file_name, :mime_type, :file_data)
                ");

                foreach ($files['name'] as $index => $name) {
                    if ($files['error'][$index] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $tmpPath = $files['tmp_name'][$index];
                    if (!is_uploaded_file($tmpPath)) {
                        continue;
                    }

                    $fileInsert->bindValue(':news_id', $id, PDO::PARAM_INT);
                    $fileInsert->bindValue(':file_name', $name, PDO::PARAM_STR);
                    $fileInsert->bindValue(':mime_type', $files['type'][$index] ?? 'application/octet-stream', PDO::PARAM_STR);
                    $fileStream = fopen($tmpPath, 'rb');
                    $fileInsert->bindValue(':file_data', $fileStream, PDO::PARAM_LOB);
                    $fileInsert->execute();
                }
            }
            
            echo json_encode(['success' => true, 'id' => $id]);
            break;
            
        case 'DELETE':
            // Удаление новости - только для админов/модераторов/организаторов/школ
            if (!isset($_SESSION['user_id'])) {
                die(json_encode(['success' => false, 'error' => 'Требуется авторизация']));
            }
            
            if (!in_array($_SESSION['user_role'], ['admin', 'moderator', 'organizer', 'school', 'school_coordinator'])) {
                die(json_encode(['success' => false, 'error' => 'Недостаточно прав']));
            }
            
            $id = $_GET['id'];
            $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    }
} catch(PDOException $e) {
    error_log("NEWS.PHP DB ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
