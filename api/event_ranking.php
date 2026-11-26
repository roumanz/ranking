<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí nebo je neplatný parametr event_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $configPath = __DIR__ . '/../config/db.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException('Chybí soubor config/db.php na serveru.');
    }

    $config = require $configPath;
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['dbname'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $fetchJson = static function (string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'o-tools-ranking/1.0',
        ]);
        $rawJson = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($rawJson === false) {
            throw new RuntimeException('Chyba komunikace s ORIS API: ' . $curlErr);
        }

        $decoded = json_decode($rawJson, true);
        return is_array($decoded) ? $decoded : [];
    };

    $normalizeLastDayOfMonth = static function (?string $rawDate): ?string {
        $val = trim((string) $rawDate);
        if ($val === '' || $val === '0000-00-00') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($val, 0, 10));
        if (!$dt) {
            return null;
        }
        return $dt->modify('last day of this month')->format('Y-m-d');
    };

    $mapGender = static function (?string $rawGender): ?string {
        $g = strtoupper(trim((string) $rawGender));
        if ($g === 'M') {
            return 'M';
        }
        if ($g === 'F' || $g === 'W') {
            return 'W';
        }
        return null;
    };

    // 1. Závod v DB (pokud existuje) + detail závodu z ORISu
    $evStmt = $pdo->prepare('SELECT * FROM oris_events WHERE oris_id = :oris_id LIMIT 1');
    $evStmt->execute([':oris_id' => $eventId]);
    $dbEvent = $evStmt->fetch() ?: null;

    $detailResp = $fetchJson(sprintf('https://oris.ceskyorientak.cz/API/?format=json&method=getEvent&id=%d', $eventId));
    $detailData = $detailResp['Data'] ?? [];
    if (!is_array($detailData) || $detailData === []) {
        throw new RuntimeException("Nepodařilo se načíst detail závodu #{$eventId} z ORISu.");
    }

    $eventName = (string) ($detailData['Name'] ?? ($dbEvent['name'] ?? ''));
    $eventDate = substr((string) ($detailData['Date'] ?? ($dbEvent['event_date'] ?? '')), 0, 10);

    $discRaw = $detailData['Discipline'] ?? ($dbEvent['discipline_name'] ?? '');
    $discText = mb_strtolower(trim(is_array($discRaw) ? json_encode($discRaw, JSON_UNESCAPED_UNICODE) : (string) $discRaw), 'UTF-8');
    $rankingType = (!empty($dbEvent['ranking_type']))
        ? (string) $dbEvent['ranking_type']
        : ((str_contains($discText, 'sprint') || str_contains($discText, 'temp')) ? 'sprint' : 'forest');

    $decisionDate = $normalizeLastDayOfMonth((string) ($detailData['RankingDecisionDate'] ?? ''))
        ?? ($dbEvent['ranking_decision_date'] ?? null)
        ?? $normalizeLastDayOfMonth($eventDate);

    // 2. Rankingové kategorie (Ranking = "1" nebo RankingKoef > 0)
    $rawClasses = $detailData['Classes'] ?? [];
    $rankingClasses = [];
    if (is_array($rawClasses)) {
        foreach ($rawClasses as $cls) {
            if (!is_array($cls)) {
                continue;
            }
            $classId = (string) ($cls['ID'] ?? '');
            if ($classId === '') {
                continue;
            }

            $rankFlag = trim((string) ($cls['Ranking'] ?? '0'));
            $coefRaw = str_replace(',', '.', trim((string) ($cls['RankingKoef'] ?? '0')));
            $coefVal = is_numeric($coefRaw) ? (float) $coefRaw : 0.0;

            if ($rankFlag !== '1' && $coefVal <= 0.0) {
                continue;
            }

            $classDef = is_array($cls['ClassDefinition'] ?? null) ? $cls['ClassDefinition'] : [];
            $gender = $mapGender((string) ($classDef['Gender'] ?? ''));

            $rankingClasses[$classId] = [
                'class_id' => $classId,
                'name' => (string) ($cls['Name'] ?? ''),
                'gender' => $gender,
                'ranking_koef' => $coefVal,
                'entries' => [],
            ];
        }
    }

    // 3. Načtení snapshotů z DB pro daný ranking_type a decision_date
    $snapshotsByGender = [];
    $entriesBySnapshotId = [];

    if ($decisionDate !== null && count($rankingClasses) > 0) {
        $snapStmt = $pdo->prepare('
            SELECT id, ranking_type, gender, decision_date, imported_at
            FROM ranking_snapshots
            WHERE ranking_type = :ranking_type
              AND decision_date = :decision_date
        ');
        $snapStmt->execute([
            ':ranking_type' => $rankingType,
            ':decision_date' => $decisionDate,
        ]);

        $entryStmt = $pdo->prepare('
            SELECT reg_no, last_name, first_name, rank_position, points, ranking_coef
            FROM ranking_entries
            WHERE snapshot_id = :snapshot_id
        ');

        foreach ($snapStmt->fetchAll() as $snap) {
            $g = (string) $snap['gender'];
            $sid = (int) $snap['id'];
            $snapshotsByGender[$g] = $snap;

            $entryStmt->execute([':snapshot_id' => $sid]);
            $map = [];
            foreach ($entryStmt->fetchAll() as $er) {
                $reg = strtoupper(trim((string) $er['reg_no']));
                if ($reg !== '') {
                    $map[$reg] = $er;
                }
            }
            $entriesBySnapshotId[$sid] = $map;
        }
    }

    // 4. Přihlášky ze getEventEntries a párování přes ClassID + registrační číslo
    $entriesResp = $fetchJson(sprintf('https://oris.ceskyorientak.cz/API/?format=json&method=getEventEntries&eventid=%d', $eventId));
    $rawEntries = $entriesResp['Data'] ?? [];

    if (is_array($rawEntries)) {
        foreach ($rawEntries as $ent) {
            if (!is_array($ent)) {
                continue;
            }

            $classId = (string) ($ent['ClassID'] ?? '');
            if ($classId === '' || !isset($rankingClasses[$classId])) {
                continue;
            }

            $gender = $rankingClasses[$classId]['gender'];
            $snap = ($gender !== null && isset($snapshotsByGender[$gender])) ? $snapshotsByGender[$gender] : null;
            $rankMap = ($snap !== null) ? ($entriesBySnapshotId[(int) $snap['id']] ?? []) : [];

            $regNo = strtoupper(trim((string) ($ent['RegNumber'] ?? $ent['RegNo'] ?? $ent['Reg. c.'] ?? '')));
            $matched = ($regNo !== '' && isset($rankMap[$regNo])) ? $rankMap[$regNo] : null;

            $competitorName = trim((string) ($ent['Name'] ?? ''));
            if ($competitorName === '') {
                $competitorName = trim(((string) ($ent['LastName'] ?? '')) . ' ' . ((string) ($ent['FirstName'] ?? '')));
            }

            $clubAbbr = trim((string) ($ent['ClubAbbr'] ?? $ent['Club'] ?? ''));

            $rankingClasses[$classId]['entries'][] = [
                'reg_no' => $regNo,
                'name' => $competitorName,
                'club' => $clubAbbr,
                'matched' => $matched !== null,
                'rank_position' => $matched !== null ? (int) $matched['rank_position'] : null,
                'points' => $matched !== null ? (int) $matched['points'] : null,
                'ranking_coef' => $matched !== null && $matched['ranking_coef'] !== null ? (float) $matched['ranking_coef'] : null,
            ];
        }
    }

    // Seřazení závodníků v kategorii podle rankingového pořadí (nepárovaní na konec)
    $classesOutput = [];
    foreach ($rankingClasses as $cls) {
        usort($cls['entries'], static function (array $a, array $b): int {
            $posA = $a['rank_position'] ?? PHP_INT_MAX;
            $posB = $b['rank_position'] ?? PHP_INT_MAX;
            if ($posA !== $posB) {
                return $posA <=> $posB;
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        $g = $cls['gender'];
        $hasSnapshot = ($g !== null && isset($snapshotsByGender[$g]));
        $cls['has_snapshot'] = $hasSnapshot;
        $cls['snapshot_id'] = $hasSnapshot ? (int) $snapshotsByGender[$g]['id'] : null;
        $classesOutput[] = $cls;
    }

    echo json_encode([
        'event' => [
            'oris_id' => $eventId,
            'name' => $eventName,
            'event_date' => $eventDate,
            'ranking_type' => $rankingType,
            'decision_date' => $decisionDate,
        ],
        'snapshots_found' => array_keys($snapshotsByGender),
        'classes' => $classesOutput,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
