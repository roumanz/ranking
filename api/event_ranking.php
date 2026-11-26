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

    $fetchText = static function (string $url, int $timeout = 25): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'o-tools-ranking/1.0',
        ]);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            throw new RuntimeException("Chyba komunikace s ORIS API (HTTP {$code}): {$curlErr}");
        }

        return (string) $raw;
    };

    $fetchJson = static function (string $url) use ($fetchText): array {
        $decoded = json_decode($fetchText($url), true);
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

    $normalizeRegNo = static function (?string $rawReg): string {
        $upper = strtoupper(trim((string) $rawReg));
        $clean = preg_replace('/[^A-Z0-9]/', '', $upper);
        return $clean !== null ? $clean : '';
    };

    $normalizeHeaderName = static function (string $rawHeader): string {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader) ?? $rawHeader;
        $clean = mb_strtolower(trim($clean), 'UTF-8');
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
        if (is_string($translit) && $translit !== '') {
            $clean = strtolower(preg_replace('/[^a-z0-9. ]+/', '', $translit) ?? $translit);
        }
        return trim($clean);
    };

    $parseRankingCsv = static function (string $csvContent) use ($normalizeRegNo, $normalizeHeaderName): array {
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $csvContent = str_replace(["\r\n", "\r"], "\n", $csvContent);

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Nelze otevřít paměťový stream pro CSV.');
        }
        fwrite($stream, $csvContent);
        rewind($stream);

        $header = fgetcsv($stream, 0, ';', '"', '\\');
        if (!$header || count($header) < 5) {
            fclose($stream);
            return [];
        }

        $map = [];
        foreach ($header as $idx => $colName) {
            $norm = $normalizeHeaderName((string) $colName);
            if ($norm !== '') {
                $map[$norm] = $idx;
            }
        }

        $idxPos   = $map['poradi'] ?? 0;
        $idxLast  = $map['prijmeni'] ?? 1;
        $idxFirst = $map['jmeno'] ?? 2;
        $idxReg   = $map['reg. c.'] ?? ($map['reg.c.'] ?? ($map['reg.'] ?? 3));
        $idxPts   = $map['body'] ?? 4;
        $idxCoef  = $map['rank.c'] ?? ($map['rank.c.'] ?? ($map['rank.'] ?? 5));

        $rows = [];
        while (($line = fgetcsv($stream, 0, ';', '"', '\\')) !== false) {
            if ($line === [null] || count($line) < 4) {
                continue;
            }
            $regNo = $normalizeRegNo((string) ($line[$idxReg] ?? ''));
            if ($regNo === '') {
                continue;
            }
            $posRaw  = trim((string) ($line[$idxPos] ?? '0'));
            $ptsRaw  = preg_replace('/\s+/', '', trim((string) ($line[$idxPts] ?? '0')));
            $coefRaw = str_replace(',', '.', trim((string) ($line[$idxCoef] ?? '')));

            $rows[] = [
                'reg_no'        => $regNo,
                'last_name'     => trim((string) ($line[$idxLast] ?? '')),
                'first_name'    => trim((string) ($line[$idxFirst] ?? '')),
                'rank_position' => max(0, (int) $posRaw),
                'points'        => (int) ($ptsRaw !== '' ? $ptsRaw : 0),
                'ranking_coef'  => ($coefRaw !== '' && is_numeric($coefRaw)) ? (float) $coefRaw : null,
            ];
        }

        fclose($stream);
        return $rows;
    };

    $autoImportSnapshot = static function (
        PDO $pdo,
        string $rankingType,
        string $gender,
        string $decisionDate
    ) use ($fetchText, $parseRankingCsv): ?array {
        $rankTypeCode = ($rankingType === 'sprint') ? 8 : 2;
        $orisGenderParam = ($gender === 'W') ? 'F' : $gender;
        $sourceUrl = sprintf(
            'https://oris.ceskyorientak.cz/ranking_export?date=%s&sport=1&gender=%s&ranktype=%d&csv=1',
            urlencode($decisionDate),
            urlencode($orisGenderParam),
            $rankTypeCode
        );

        $csvContent = $fetchText($sourceUrl);
        $entries = $parseRankingCsv($csvContent);
        if (count($entries) === 0) {
            return null;
        }

        $pdo->beginTransaction();
        try {
            $stmtSnap = $pdo->prepare('
                INSERT INTO ranking_snapshots (ranking_type, gender, decision_date, source_url, imported_at)
                VALUES (:ranking_type, :gender, :decision_date, :source_url, NOW())
                ON DUPLICATE KEY UPDATE
                    source_url = VALUES(source_url),
                    imported_at = NOW(),
                    id = LAST_INSERT_ID(id)
            ');
            $stmtSnap->execute([
                ':ranking_type'  => $rankingType,
                ':gender'        => $gender,
                ':decision_date' => $decisionDate,
                ':source_url'    => $sourceUrl,
            ]);

            $snapshotId = (int) $pdo->lastInsertId();

            $stmtDel = $pdo->prepare('DELETE FROM ranking_entries WHERE snapshot_id = :snapshot_id');
            $stmtDel->execute([':snapshot_id' => $snapshotId]);

            $stmtIns = $pdo->prepare('
                INSERT INTO ranking_entries
                    (snapshot_id, reg_no, last_name, first_name, rank_position, points, ranking_coef)
                VALUES
                    (:snapshot_id, :reg_no, :last_name, :first_name, :rank_position, :points, :ranking_coef)
            ');

            foreach ($entries as $entry) {
                $stmtIns->execute([
                    ':snapshot_id'   => $snapshotId,
                    ':reg_no'        => $entry['reg_no'],
                    ':last_name'     => $entry['last_name'],
                    ':first_name'    => $entry['first_name'],
                    ':rank_position' => $entry['rank_position'],
                    ':points'        => $entry['points'],
                    ':ranking_coef'  => $entry['ranking_coef'],
                ]);
            }

            $pdo->commit();

            return [
                'id'            => $snapshotId,
                'ranking_type'  => $rankingType,
                'gender'        => $gender,
                'decision_date' => $decisionDate,
                'entries_count' => count($entries),
                'auto_imported' => true,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    };

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

    $rawClasses = $detailData['Classes'] ?? [];
    $rankingClasses = [];
    $neededGenders = [];

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
            if ($gender !== null) {
                $neededGenders[$gender] = true;
            }

            $rankingClasses[$classId] = [
                'class_id'     => $classId,
                'name'         => (string) ($cls['Name'] ?? ''),
                'gender'       => $gender,
                'ranking_koef' => $coefVal,
                'entries'      => [],
            ];
        }
    }

    $snapshotsByGender = [];
    $entriesBySnapshotId = [];
    $autoImportedCount = 0;

    if ($decisionDate !== null && count($rankingClasses) > 0) {
        $snapStmt = $pdo->prepare('
            SELECT id, ranking_type, gender, decision_date, imported_at
            FROM ranking_snapshots
            WHERE ranking_type = :ranking_type
              AND decision_date = :decision_date
        ');
        $snapStmt->execute([
            ':ranking_type'  => $rankingType,
            ':decision_date' => $decisionDate,
        ]);

        foreach ($snapStmt->fetchAll() as $snap) {
            $snapshotsByGender[(string) $snap['gender']] = $snap;
        }

        foreach (array_keys($neededGenders) as $g) {
            if (!isset($snapshotsByGender[$g])) {
                $importedSnap = $autoImportSnapshot($pdo, $rankingType, $g, $decisionDate);
                if ($importedSnap !== null) {
                    $snapshotsByGender[$g] = $importedSnap;
                    $autoImportedCount++;
                }
            }
        }

        $entryStmt = $pdo->prepare('
            SELECT reg_no, last_name, first_name, rank_position, points, ranking_coef
            FROM ranking_entries
            WHERE snapshot_id = :snapshot_id
        ');

        foreach ($snapshotsByGender as $g => $snap) {
            $sid = (int) $snap['id'];
            $entryStmt->execute([':snapshot_id' => $sid]);
            $map = [];
            foreach ($entryStmt->fetchAll() as $er) {
                $reg = $normalizeRegNo((string) $er['reg_no']);
                if ($reg !== '') {
                    $map[$reg] = $er;
                }
            }
            $entriesBySnapshotId[$sid] = $map;
        }
    }

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

            $rawReg = (string) ($ent['RegNumber'] ?? $ent['RegNo'] ?? $ent['Reg. č.'] ?? $ent['Reg. c.'] ?? '');
            $regNo = $normalizeRegNo($rawReg);
            $matched = ($regNo !== '' && isset($rankMap[$regNo])) ? $rankMap[$regNo] : null;

            $competitorName = trim((string) ($ent['Name'] ?? ''));
            if ($competitorName === '') {
                $competitorName = trim(((string) ($ent['LastName'] ?? '')) . ' ' . ((string) ($ent['FirstName'] ?? '')));
            }

            $clubAbbr = trim((string) ($ent['ClubAbbr'] ?? $ent['Club'] ?? ''));

            $rankingClasses[$classId]['entries'][] = [
                'reg_no'        => $regNo !== '' ? $regNo : trim($rawReg),
                'name'          => $competitorName,
                'club'          => $clubAbbr,
                'matched'       => $matched !== null,
                'rank_position' => $matched !== null ? (int) $matched['rank_position'] : null,
                'points'        => $matched !== null ? (int) $matched['points'] : null,
                'ranking_coef'  => $matched !== null && $matched['ranking_coef'] !== null ? (float) $matched['ranking_coef'] : null,
            ];
        }
    }

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
        $cls['snapshot_entries_count'] = $hasSnapshot ? count($entriesBySnapshotId[(int) $snapshotsByGender[$g]['id']] ?? []) : 0;
        $cls['matched_count'] = count(array_filter($cls['entries'], static fn(array $e): bool => $e['matched']));
        $classesOutput[] = $cls;
    }

    echo json_encode([
        'event' => [
            'oris_id'       => $eventId,
            'name'          => $eventName,
            'event_date'    => $eventDate,
            'ranking_type'  => $rankingType,
            'decision_date' => $decisionDate,
        ],
        'snapshots_found'     => array_keys($snapshotsByGender),
        'auto_imported_count' => $autoImportedCount,
        'classes'             => $classesOutput,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
