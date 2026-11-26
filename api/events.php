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

    $selectSql = '
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
            ranking_decision_date,
            updated_at
        FROM oris_events
        WHERE event_date BETWEEN :date_from AND :date_to
        ORDER BY event_date ASC, name ASC
    ';

    $stmt = $pdo->prepare($selectSql);
    $stmt->execute([
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ]);
    $events = $stmt->fetchAll();

    $needsSync = count($events) === 0;
    if (!$needsSync) {
        $maxUpdatedStmt = $pdo->prepare('
            SELECT MAX(updated_at) AS last_update
            FROM oris_events
            WHERE event_date BETWEEN :date_from AND :date_to
        ');
        $maxUpdatedStmt->execute([
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);
        $lastUpdateStr = $maxUpdatedStmt->fetchColumn();

        if ($lastUpdateStr) {
            $lastUpdate = new DateTimeImmutable((string) $lastUpdateStr);
            if ((time() - $lastUpdate->getTimestamp()) > 3600) {
                $needsSync = true;
            }
        }
    }

    $extractText = static function ($value, array $preferredKeys): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (!is_array($value) || $value === []) {
            return '';
        }

        // Asociativní pole (jeden objekt)
        if (array_keys($value) !== range(0, count($value) - 1)) {
            foreach ($preferredKeys as $key) {
                if (isset($value[$key]) && $value[$key] !== '') {
                    return trim((string) $value[$key]);
                }
            }
            return '';
        }

        // Pole položek
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

    if ($needsSync) {
        $orisUrl = sprintf(
            'https://oris.ceskyorientak.cz/API/?format=json&method=getEventList&datefrom=%s&dateto=%s',
            urlencode($dateFrom),
            urlencode($dateTo)
        );

        $ch = curl_init($orisUrl);
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

                $orgsRaw = $item['Org'] ?? $item['Orgs'] ?? $item['Clubs'] ?? [];
                $clubsText = $extractText($orgsRaw, ['Abbr', 'ShortName', 'Name', 'ID']);

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

            $stmt->execute([
                ':date_from' => $dateFrom,
                ':date_to' => $dateTo,
            ]);
            $events = $stmt->fetchAll();
        }
    }

    echo json_encode([
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'count' => count($events),
        'events' => $events,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
