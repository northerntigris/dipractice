<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: text/html; charset=utf-8');
session_start();

try {
    $isView = isset($_GET['view']) && $_GET['view'] === '1';
    $olympiadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $requestedSchoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

    if ($olympiadId <= 0) {
        http_response_code(400);
        echo 'Некорректный идентификатор олимпиады';
        exit;
    }

    $pdo->exec("ALTER TABLE olympiads ADD COLUMN IF NOT EXISTS results_published boolean");
    $pdo->exec("UPDATE olympiads SET results_published = true WHERE results_published IS NULL");
    $pdo->exec("ALTER TABLE olympiads ALTER COLUMN results_published SET DEFAULT false");

    $pdo->exec("CREATE TABLE IF NOT EXISTS appeals (
        id SERIAL PRIMARY KEY,
        olympiad_id INTEGER NOT NULL REFERENCES olympiads(id) ON DELETE CASCADE,
        student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        description TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        response_comment TEXT,
        response_score numeric,
        responder_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        response_created_at TIMESTAMPTZ,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("ALTER TABLE appeals ADD COLUMN IF NOT EXISTS status text");
    $pdo->exec("UPDATE appeals SET status = 'pending' WHERE status IS NULL");
    $pdo->exec("ALTER TABLE appeals ALTER COLUMN status SET DEFAULT 'pending'");

    $school = null;
    if (!$isView) {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo 'Unauthorized';
            exit;
        }

        $role = $_SESSION['user_role'] ?? '';
        if (!in_array($role, ['school', 'school_coordinator'], true)) {
            http_response_code(403);
            echo 'Недостаточно прав';
            exit;
        }

        $schoolIdStmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
        $schoolIdStmt->execute([(int)$_SESSION['user_id']]);
        $schoolId = (int)$schoolIdStmt->fetchColumn();
        if ($schoolId <= 0) {
            http_response_code(403);
            echo 'Школа не найдена';
            exit;
        }

        $schoolStmt = $pdo->prepare("SELECT id, short_name, full_name FROM approved_schools WHERE id = ? LIMIT 1");
        $schoolStmt->execute([$schoolId]);
        $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
        if (!$school) {
            http_response_code(403);
            echo 'Школа не найдена';
            exit;
        }
    }

    $filterSchoolId = $isView ? ($requestedSchoolId > 0 ? $requestedSchoolId : null) : (int)$school['id'];
    $schoolJoinFilterSql = '';
    if ($filterSchoolId !== null && $filterSchoolId > 0) {
        $schoolJoinFilterSql = ' AND so.school_id = :filter_school_id';
    }

    $olStmt = $pdo->prepare("
        SELECT
            o.id,
            o.subject,
            o.datetime,
            o.grades,
            o.status,
            o.results_published,
            o.organization_id,
            o.school_id AS olympiad_school_id,
            so.scheduled_at AS school_scheduled_at,
            so.grades AS school_grades,
            so.status AS school_status,
            org.full_name AS organizer_name
        FROM olympiads o
        LEFT JOIN school_olympiads so
          ON so.olympiad_id = o.id" . $schoolJoinFilterSql . "
        LEFT JOIN approved_organizations org
          ON org.id = o.organization_id
        WHERE o.id = :olympiad_id
        LIMIT 1
    ");

    if ($filterSchoolId !== null && $filterSchoolId > 0) {
        $olStmt->bindValue(':filter_school_id', $filterSchoolId, PDO::PARAM_INT);
    }
    $olStmt->bindValue(':olympiad_id', $olympiadId, PDO::PARAM_INT);
    $olStmt->execute();
    $olympiad = $olStmt->fetch(PDO::FETCH_ASSOC);

    if (!$olympiad) {
        http_response_code(404);
        echo 'Олимпиада не найдена';
        exit;
    }

    $status = $olympiad['school_status'] ?: $olympiad['status'];
    if (!in_array($status, ['completed', 'archived'], true)) {
        http_response_code(400);
        echo 'Олимпиада ещё не завершена';
        exit;
    }

    if (empty($olympiad['results_published'])) {
        http_response_code(400);
        echo 'Результаты ещё не опубликованы жюри';
        exit;
    }

    $pendingAppealsStmt = $pdo->prepare("
        SELECT 1
        FROM appeals a
        WHERE a.olympiad_id IN (
            SELECT id FROM olympiads WHERE id = :olympiad_id OR template_id = :olympiad_id
        )
          AND COALESCE(a.status, 'pending') = 'pending'
        LIMIT 1
    ");
    $pendingAppealsStmt->execute([':olympiad_id' => $olympiadId]);
    if ($pendingAppealsStmt->fetchColumn()) {
        http_response_code(400);
        echo 'Нельзя публиковать результаты: есть апелляции на рассмотрении жюри';
        exit;
    }

    if ($status === 'archived' && !$isView) {
        http_response_code(400);
        echo 'Олимпиада уже в архиве';
        exit;
    }

    $schoolIdsFilter = [];
    $schoolName = '—';

    if ($isView) {
        if ($requestedSchoolId > 0) {
            $schoolNameStmt = $pdo->prepare("SELECT id, short_name, full_name FROM approved_schools WHERE id = ? LIMIT 1");
            $schoolNameStmt->execute([$requestedSchoolId]);
            $viewSchool = $schoolNameStmt->fetch(PDO::FETCH_ASSOC);

            if ($viewSchool) {
                $schoolName = ($viewSchool['short_name'] ?? '') ?: (($viewSchool['full_name'] ?? '') ?: '—');
                $sid = (int)($viewSchool['id'] ?? 0);
                if ($sid > 0) $schoolIdsFilter[] = $sid;
                if ($sreg > 0 && $sreg !== $sid) $schoolIdsFilter[] = $sreg;
            }
        }

        if (!$schoolIdsFilter) {
            $defaultSchoolId = (int)$olympiad['olympiad_school_id'];
            if ($defaultSchoolId > 0) {
                $schoolNameStmt = $pdo->prepare("SELECT id, short_name, full_name FROM approved_schools WHERE id = ? LIMIT 1");
                $schoolNameStmt->execute([$defaultSchoolId]);
                $viewSchool = $schoolNameStmt->fetch(PDO::FETCH_ASSOC);
                if ($viewSchool) {
                    $schoolName = ($viewSchool['short_name'] ?? '') ?: (($viewSchool['full_name'] ?? '') ?: '—');
                    $sid = (int)($viewSchool['id'] ?? 0);
                    if ($sid > 0) $schoolIdsFilter[] = $sid;
                    if ($sreg > 0 && $sreg !== $sid) $schoolIdsFilter[] = $sreg;
                }
            }
        }

        if ($schoolName === '—' && !$schoolIdsFilter) {
            $schoolName = 'Все школы';
        }
    } else {
        $schoolName = ($school['short_name'] ?? '') ?: (($school['full_name'] ?? '') ?: '—');
        $sid = (int)($school['id'] ?? 0);
        if ($sid > 0) $schoolIdsFilter[] = $sid;
        if ($sreg > 0 && $sreg !== $sid) $schoolIdsFilter[] = $sreg;
    }

    $participantsSql = "
        SELECT
            u.full_name,
            p.score,
            RANK() OVER (ORDER BY p.score DESC NULLS LAST) AS place
        FROM olympiad_participants p
        JOIN users u ON p.student_id = u.id
        WHERE p.olympiad_id IN (
            SELECT id FROM olympiads WHERE id = :olympiad_id OR template_id = :olympiad_id
        )
    ";

    $participantsParams = [':olympiad_id' => $olympiadId];

    if ($schoolIdsFilter) {
        $schoolIdsFilter = array_values(array_unique(array_filter(array_map('intval', $schoolIdsFilter), static function ($id) {
            return $id > 0;
        })));

        if ($schoolIdsFilter) {
            $placeholders = [];
            foreach ($schoolIdsFilter as $index => $id) {
                $key = ':school_id_' . $index;
                $placeholders[] = $key;
                $participantsParams[$key] = $id;
            }
            $participantsSql .= ' AND u.school_id IN (' . implode(', ', $placeholders) . ')';
        }
    }

    $participantsSql .= ' ORDER BY place, u.full_name';

    $participantsStmt = $pdo->prepare($participantsSql);
    foreach ($participantsParams as $key => $value) {
        $participantsStmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $participantsStmt->execute();
    $participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$participants) {
        http_response_code(400);
        echo 'Нет участников для формирования отчета';
        exit;
    }

    $missingScores = array_filter($participants, static function ($participant) {
        return $participant['score'] === null;
    });
    if ($missingScores) {
        http_response_code(400);
        echo 'Для публикации заполните баллы всем участникам';
        exit;
    }

    $juryStmt = $pdo->prepare("
        SELECT u.full_name, oj.jury_role
        FROM olympiad_jury oj
        JOIN jury_members jm ON oj.jury_member_id = jm.id
        JOIN users u ON u.id = jm.user_id
        WHERE oj.olympiad_id = :olympiad_id
        ORDER BY oj.jury_role, u.full_name
    ");
    $juryStmt->execute([':olympiad_id' => $olympiadId]);
    $jury = $juryStmt->fetchAll(PDO::FETCH_ASSOC);

    $scheduledAt = $olympiad['school_scheduled_at'] ?: $olympiad['datetime'];
    $grades = $olympiad['school_grades'] ?: $olympiad['grades'];
    $organizerName = $olympiad['organizer_name'] ?: '—';

    $getInitial = static function ($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1);
        }
        return substr($value, 0, 1);
    };

    $formatName = static function ($name) use ($getInitial) {
        $parts = preg_split('/\s+/', trim((string)$name));
        if (!$parts) {
            return (string)$name;
        }
        $surname = array_shift($parts);
        $initials = '';
        foreach ($parts as $part) {
            $initials .= $getInitial($part) . '.';
        }
        return trim($surname . ' ' . $initials);
    };

    $formatDate = static function ($value) {
        if (!$value) {
            return '—';
        }
        $timestamp = strtotime((string)$value);
        if ($timestamp === false) {
            return (string)$value;
        }
        return date('d.m.Y H:i', $timestamp);
    };

    if (!$isView) {
        $pdo->beginTransaction();
        if (!empty($olympiad['olympiad_school_id'])) {
            $updateStatus = $pdo->prepare("UPDATE olympiads SET status = 'archived' WHERE id = ?");
            $updateStatus->execute([$olympiadId]);
        } else {
            $updateStatus = $pdo->prepare("UPDATE school_olympiads SET status = 'archived' WHERE olympiad_id = ? AND school_id = ?");
            $updateStatus->execute([$olympiadId, (int)$school['id']]);
        }
        $pdo->commit();
    }

    $participantsRows = '';
    foreach ($participants as $participant) {
        $participantsRows .= '<tr>'
            . '<td>' . htmlspecialchars($formatName($participant['full_name'])) . '</td>'
            . '<td style="text-align:center;">' . htmlspecialchars((string)$participant['score']) . '</td>'
            . '<td style="text-align:center;">' . htmlspecialchars((string)$participant['place']) . '</td>'
            . '</tr>';
    }

    $juryRows = '';
    foreach ($jury as $member) {
        $juryRows .= '<tr>'
            . '<td>' . htmlspecialchars($formatName($member['full_name'])) . '</td>'
            . '<td>' . htmlspecialchars($member['jury_role'] ?: '—') . '</td>'
            . '</tr>';
    }

    $toolbar = '';
    if ($isView) {
        $toolbar = '
        <div class="toolbar">
          <div>
            <div class="toolbar-title">PDF-отчёт</div>
            <div class="toolbar-subtitle">Откройте или сохраните документ для печати.</div>
          </div>
          <button type="button" class="toolbar-btn" onclick="window.print()">
            Сохранить как PDF
          </button>
        </div>';
    }

    echo '<!DOCTYPE html>
    <html lang="ru">
    <head>
      <meta charset="UTF-8">
      <title>Результаты олимпиады</title>
      <style>
        body { font-family: "Inter", "Arial", sans-serif; background: #f8fafc; margin: 0; padding: 32px; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; padding: 16px 20px; border-radius: 12px; background: #eff6ff; border: 1px solid #bfdbfe; }
        .toolbar-title { font-size: 16px; font-weight: 600; color: #1e3a8a; }
        .toolbar-subtitle { font-size: 13px; color: #475569; margin-top: 4px; }
        .toolbar-btn { background: #2563eb; color: #fff; border: none; border-radius: 10px; padding: 10px 16px; font-weight: 600; cursor: pointer; }
        .toolbar-btn:hover { background: #1d4ed8; }
        .sheet { background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08); }
        h1 { margin: 0 0 8px; font-size: 24px; color: #0f172a; }
        h2 { margin-top: 24px; margin-bottom: 8px; color: #0f172a; }
        .subtitle { color: #475569; margin-bottom: 24px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; }
        .info-card span { display: block; color: #64748b; font-size: 0.85rem; }
        .info-card strong { color: #0f172a; font-size: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px 12px; font-size: 0.95rem; }
        th { background: #f1f5f9; text-align: left; }
        .note { margin-top: 24px; color: #64748b; font-size: 0.85rem; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; border-radius: 0; } }
      </style>
    </head>
    <body>
      ' . $toolbar . '
      <div class="sheet">
        <h1>Результаты олимпиады</h1>
        <div class="subtitle">' . htmlspecialchars($olympiad['subject'] ?: '—') . '</div>

        <div class="info-grid">
          <div class="info-card"><span>Дата проведения</span><strong>' . htmlspecialchars($formatDate($scheduledAt)) . '</strong></div>
          <div class="info-card"><span>Классы</span><strong>' . htmlspecialchars($grades ?: '—') . '</strong></div>
          <div class="info-card"><span>Организатор</span><strong>' . htmlspecialchars($organizerName) . '</strong></div>
          <div class="info-card"><span>Проводящая школа</span><strong>' . htmlspecialchars($schoolName) . '</strong></div>
        </div>

        <h2>Список участников</h2>
        <table>
          <thead>
            <tr>
              <th>Участник</th>
              <th style="text-align:center;">Баллы</th>
              <th style="text-align:center;">Место</th>
            </tr>
          </thead>
          <tbody>
            ' . $participantsRows . '
          </tbody>
        </table>

        <h2>Состав жюри</h2>
        <table>
          <thead>
            <tr>
              <th>Член жюри</th>
              <th>Роль</th>
            </tr>
          </thead>
          <tbody>
            ' . ($juryRows ?: '<tr><td colspan="2">—</td></tr>') . '
          </tbody>
        </table>

        <div class="note">Документ сформирован автоматически. Для сохранения выберите «Печать» → «Сохранить как PDF».</div>
      </div>
      ' . ($isView ? '' : '<script>window.onload = () => { window.print(); };</script>') . '
    </body>
    </html>';
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('publish-school-results.php error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка формирования отчета';
}
