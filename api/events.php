<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
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

    $today = new DateTimeImmutable('today');
    $dateFrom = $today->modify('-31 days')->format('Y-m-d');
    $dateTo = $today->modify('+31 days')->format('Y-m-d');

    $hasRankingFilter = isset($_GET['has_ranking']) && in_array((string) $_GET['has_ranking'], ['1', 'true'], true);
    $rankingTypeFilter = strtolower(trim((string) ($_GET['ranking_type'] ?? '')));
    if (!in_array($rankingTypeFilter, ['forest', 'sprint'], true)) {
        $rankingTypeFilter = '';
    }

    $fetchJson = static function (string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
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
        $datePart = substr($val, 0, 10);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
        if (!$dt) {
            return null;
        }
        return $dt->modify('last day of this month')->format('Y-m-d');
    };

    $detectRankingType = static function (array $eventData, string $disciplineName): string {
        $discText = mb_strtolower(trim($disciplineName . ' ' . (string) ($eventData['Name'] ?? '')), 'UTF-8');
        if (str_contains($discText, 'sprint') || str_contains($discText, 'temp')) {
            return 'sprint';
        }
        return 'forest';
    };

    $extractRankingInfoFromDetail = static function (array $detailData, string $disciplineName) use ($normalizeLastDayOfMonth, $detectRankingType): array {
        $classes = $detailData['Classes'] ?? [];
        $hasRanking = 0;

        if (is_array($classes)) {
            foreach ($classes as $cls) {
                if (!is_array($cls)) {
                    continue;
                }
                $rankFlag = trim((string) ($cls['Ranking'] ?? '0'));
                $rankCoefRaw = str_replace(',', '.', trim((string) ($cls['RankingKoef'] ?? '0')));
                $rankCoef = is_numeric($rankCoefRaw) ? (float) $rankCoefRaw : 0.0;

                if ($rankFlag === '1' || $rankCoef > 0.0) {
                    $hasRanking = 1;
                    break;
                }
            }
        }

        $decisionDate = $normalizeLastDayOfMonth((string) ($detailData['RankingDecisionDate'] ?? ''));
        $rankingType = ($hasRanking === 1) ? $detectRankingType($detailData, $disciplineName) : null;

        return [
            'has_ranking' => $hasRanking,
            'ranking_type' => $rankingType,
            'ranking_decision_date' => $decisionDate,
        ];
    };

    $extractText = static function ($value, array $preferredKeys): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (!is_array($value) || $value === []) {
            return '';
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            foreach ($preferredKeys as $key) {
                if (isset($value[$key]) && $value[$key] !== '') {
                    return trim((string) $value[$key]);
                }
            }
            return '';
        }

        $parts = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                foreach ($preferredKeys as $key) {
                    if (!empty($entry[$key])) {
                        $parts[] = trim((string) $entry[$key]);
                        break;
                    }
                }
            } elseif ($entry !== '') {
                $parts[] = trim((string) $entry);
            }
        }

        return implode(', ', array_unique($parts));
    };

    $extractClubs = static function (array $item): string {
        $abbrs = [];

        foreach ($item as $key => $val) {
            if (!preg_match('/^org\d*$/i', (string) $key) && !preg_match('/^orgs$/i', (string) $key)) {
                continue;
            }
            if (!is_array($val) || $val === []) {
                continue;
            }

            if (isset($val['Abbr']) && trim((string) $val['Abbr']) !== '') {
                $abbrs[] = trim((string) $val['Abbr']);
            } else {
                foreach ($val as $sub) {
                    if (is_array($sub) && isset($sub['Abbr']) && trim((string) $sub['Abbr']) !== '') {
                        $abbrs[] = trim((string) $sub['Abbr']);
                    }
                }
            }
        }

        return implode(', ', array_unique($abbrs));
    };

    $buildSelectQuery = static function (bool $onlyRanking, string $rankType): array {
        $where = ['event_date BETWEEN :date_from AND :date_to'];
        $params = [];

        if ($onlyRanking) {
            $where[] = 'has_ranking = 1';
        }
        if ($rankType !== '') {
            $where[] = 'ranking_type = :ranking_type';
            $params[':ranking_type'] = $rankType;
        }

        $sql = '
            SELECT
                oris_id,
                name,
                event_date,
                sport_id,
                sport_name,
                discipline_name,
                level_code,
                level_name,
                region,
                clubs,
                entries_count,
                is_cancelled,
                has_ranking,
                ranking_type,
                ranking_decision_date,
                detail_synced_at,
                updated_at
            FROM oris_events
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY event_date ASC, name ASC
        ';

        return [$sql, $params];
    };

    [$selectSql, $filterParams] = $buildSelectQuery($hasRankingFilter, $rankingTypeFilter);
    $stmt = $pdo->prepare($selectSql);
    $stmt->execute(array_merge([':date_from' => $dateFrom, ':date_to' => $dateTo], $filterParams));
    $events = $stmt->fetchAll();

    // Kontrola stáří seznamu závodů (1 hodina)
    $allRangeStmt = $pdo->prepare('
        SELECT oris_id, discipline_name, has_ranking, ranking_decision_date, detail_synced_at, updated_at
        FROM oris_events
        WHERE event_date BETWEEN :date_from AND :date_to
    ');
    $allRangeStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $rangeRows = $allRangeStmt->fetchAll();

    $needsListSync = count($rangeRows) === 0;
    if (!$needsListSync) {
        $maxUpdated = null;
        foreach ($rangeRows as $r) {
            if (!empty($r['updated_at']) && ($maxUpdated === null || $r['updated_at'] > $maxUpdated)) {
                $maxUpdated = (string) $r['updated_at'];
            }
        }
        if ($maxUpdated !== null && (time() - (new DateTimeImmutable($maxUpdated))->getTimestamp()) > 3600) {
            $needsListSync = true;
        }
    }

    if ($needsListSync) {
        $orisUrl = sprintf(
            'https://oris.ceskyorientak.cz/API/?format=json&method=getEventList&datefrom=%s&dateto=%s',
            urlencode($dateFrom),
            urlencode($dateTo)
        );
        $decoded = $fetchJson($orisUrl);
        $orisData = $decoded['Data'] ?? [];

        if (is_array($orisData) && count($orisData) > 0) {
            $upsertSql = '
                INSERT INTO oris_events (
                    oris_id,
                    name,
                    event_date,
                    sport_id,
                    sport_name,
                    discipline_name,
                    level_code,
                    level_name,
                    region,
                    clubs,
                    entries_count,
                    is_cancelled
                ) VALUES (
                    :oris_id,
                    :name,
                    :event_date,
                    :sport_id,
                    :sport_name,
                    :discipline_name,
                    :level_code,
                    :level_name,
                    :region,
                    :clubs,
                    :entries_count,
                    :is_cancelled
                )
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    event_date = VALUES(event_date),
                    sport_id = VALUES(sport_id),
                    sport_name = VALUES(sport_name),
                    discipline_name = VALUES(discipline_name),
                    level_code = VALUES(level_code),
                    level_name = VALUES(level_name),
                    region = VALUES(region),
                    clubs = VALUES(clubs),
                    entries_count = VALUES(entries_count),
                    is_cancelled = VALUES(is_cancelled),
                    updated_at = CURRENT_TIMESTAMP
            ';
            $upsertStmt = $pdo->prepare($upsertSql);

            foreach ($orisData as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $orisId = (int) ($item['ID'] ?? 0);
                $eventDate = (string) ($item['Date'] ?? '');
                if ($orisId <= 0 || $eventDate === '') {
                    continue;
                }

                $sportRaw = $item['Sport'] ?? [];
                $sportId = is_array($sportRaw) ? (string) ($sportRaw['ID'] ?? '') : (string) $sportRaw;
                $sportName = $extractText($sportRaw, ['NameCZ', 'Abbr', 'ShortName', 'Name', 'ID']);

                $discRaw = $item['Discipline'] ?? $item['Disciplines'] ?? [];
                $disciplineName = $extractText($discRaw, ['ShortName', 'Abbr', 'NameCZ', 'Name', 'ID']);

                $levelRaw = $item['Level'] ?? [];
                $levelCode = $extractText($levelRaw, ['ShortName', 'Abbr', 'NameCZ', 'Name', 'ID']);
                $levelName = $extractText($levelRaw, ['NameCZ', 'Name', 'ShortName', 'Abbr', 'ID']);

                $regionRaw = (!empty($item['Region'])) ? $item['Region'] : ($item['Regions'] ?? []);
                $regionText = $extractText($regionRaw, ['Abbr', 'ShortName', 'NameCZ', 'Name', 'ID']);

                $clubsText = $extractClubs($item);

                $entriesCount = (int) ($item['EntriesCount'] ?? $item['Entries'] ?? $item['Count'] ?? 0);
                $cancelledVal = (string) ($item['Cancelled'] ?? $item['Canceled'] ?? '0');
                $isCancelled = ($cancelledVal === '1' || strtolower($cancelledVal) === 'true') ? 1 : 0;

                $upsertStmt->execute([
                    ':oris_id' => $orisId,
                    ':name' => (string) ($item['Name'] ?? ''),
                    ':event_date' => substr($eventDate, 0, 10),
                    ':sport_id' => $sportId,
                    ':sport_name' => $sportName,
                    ':discipline_name' => $disciplineName,
                    ':level_code' => $levelCode,
                    ':level_name' => $levelName,
                    ':region' => $regionText,
                    ':clubs' => $clubsText,
                    ':entries_count' => $entriesCount,
                    ':is_cancelled' => $isCancelled,
                ]);
            }

            $allRangeStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
            $rangeRows = $allRangeStmt->fetchAll();
        }
    }

    // Doplnění rankingových údajů přes getEvent (pokud ještě nebyly synchronizovány nebo jsou starší než 24h)
    $detailStmt = $pdo->prepare('
        UPDATE oris_events
        SET
            has_ranking = :has_ranking,
            ranking_type = :ranking_type,
            ranking_decision_date = :ranking_decision_date,
            detail_synced_at = NOW()
        WHERE oris_id = :oris_id
    ');

    $syncedDetailsCount = 0;
    foreach ($rangeRows as $row) {
        $needDetail = empty($row['detail_synced_at']);
        if (!$needDetail && !empty($row['detail_synced_at'])) {
            $age = time() - (new DateTimeImmutable((string) $row['detail_synced_at']))->getTimestamp();
            if ($age > 86400) {
                $needDetail = true;
            }
        }

        if (!$needDetail) {
            continue;
        }

        $orisId = (int) $row['oris_id'];
        $detailUrl = sprintf('https://oris.ceskyorientak.cz/API/?format=json&method=getEvent&id=%d', $orisId);
        $detailJson = $fetchJson($detailUrl);
        $detailData = $detailJson['Data'] ?? [];

        if (is_array($detailData) && $detailData !== []) {
            $info = $extractRankingInfoFromDetail($detailData, (string) ($row['discipline_name'] ?? ''));
            $detailStmt->execute([
                ':has_ranking' => $info['has_ranking'],
                ':ranking_type' => $info['ranking_type'],
                ':ranking_decision_date' => $info['ranking_decision_date'],
                ':oris_id' => $orisId,
            ]);
            $syncedDetailsCount++;
        }
    }

    // Znovunačtení výsledných závodů po případné synchronizaci
    [$selectSql, $filterParams] = $buildSelectQuery($hasRankingFilter, $rankingTypeFilter);
    $stmt = $pdo->prepare($selectSql);
    $stmt->execute(array_merge([':date_from' => $dateFrom, ':date_to' => $dateTo], $filterParams));
    $events = $stmt->fetchAll();

    echo json_encode([
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'count' => count($events),
        'syncedDetails' => $syncedDetailsCount,
        'events' => $events,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
