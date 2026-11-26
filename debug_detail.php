<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($eventId <= 0) {
    echo "Zadej ID zavodu v URL, napr.: debug_detail.php?id=9794\n";
    exit;
}

$fetchJson = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'o-tools-ranking-debug/1.0',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['_error' => 'cURL: ' . $err];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['_error' => 'Invalid JSON', '_raw' => mb_substr($raw, 0, 1000)];
    }

    return $decoded;
};

$eventUrl = sprintf('https://oris.ceskyorientak.cz/API/?format=json&method=getEvent&id=%d', $eventId);
$entriesUrl = sprintf('https://oris.ceskyorientak.cz/API/?format=json&method=getEventEntries&eventid=%d', $eventId);

$eventResp = $fetchJson($eventUrl);
$entriesResp = $fetchJson($entriesUrl);

$eventData = $eventResp['Data'] ?? [];
$entriesData = $entriesResp['Data'] ?? [];

echo "=== GET EVENT ===\n";
echo "URL: $eventUrl\n";
if (is_array($eventData)) {
    echo "Hlavni klice v Data:\n";
    foreach ($eventData as $k => $v) {
        if ($k === 'Classes') {
            continue;
        }
        if (is_scalar($v) || $v === null) {
            echo "  [$k] => " . var_export($v, true) . "\n";
        } elseif (preg_match('/rank|date|coef|level|sport|org|region/i', (string) $k)) {
            echo "  [$k] => " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    $classes = $eventData['Classes'] ?? [];
    echo "\nPocet kategorii (Classes): " . (is_array($classes) ? count($classes) : 0) . "\n";
    if (is_array($classes) && $classes !== []) {
        $firstClass = reset($classes);
        echo "Ukazka prvni kategorie:\n" . json_encode($firstClass, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

        echo "Kategorie, ktere maji v sobe neco s 'rank' / 'coef' / 'coeff':\n";
        $matching = 0;
        foreach ($classes as $cid => $cls) {
            if (!is_array($cls)) {
                continue;
            }
            $jsonCls = json_encode($cls, JSON_UNESCAPED_UNICODE);
            if (preg_match('/rank|coef/i', (string) $jsonCls)) {
                echo "  Class [$cid] (" . ($cls['Name'] ?? '?') . "): $jsonCls\n";
                $matching++;
                if ($matching >= 5) {
                    break;
                }
            }
        }
        if ($matching === 0) {
            echo "  (Zadna kategorie nema v klicich/hodnotach 'rank' nebo 'coef' - vypisu vsechny klice prvni kategorie vyse)\n";
        }
    }
} else {
    echo json_encode($eventResp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== GET EVENT ENTRIES ===\n";
echo "URL: $entriesUrl\n";
echo "Pocet polozek v Data: " . (is_array($entriesData) ? count($entriesData) : 0) . "\n";
if (is_array($entriesData) && $entriesData !== []) {
    $firstEntry = reset($entriesData);
    echo "Ukazka prvni prihlasky:\n" . json_encode($firstEntry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo json_encode($entriesResp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
