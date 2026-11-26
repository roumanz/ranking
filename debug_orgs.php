<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$today = new DateTimeImmutable('today');
$dateFrom = $today->modify('-31 days')->format('Y-m-d');
$dateTo = $today->modify('+31 days')->format('Y-m-d');

$url = sprintf(
    'https://oris.ceskyorientak.cz/API/?format=json&method=getEventList&datefrom=%s&dateto=%s',
    urlencode($dateFrom),
    urlencode($dateTo)
);

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
    echo "CURL ERROR: $err\n";
    exit;
}

$data = json_decode($raw, true);
$events = $data['Data'] ?? [];

echo "URL: $url\n";
echo "Celkem akcí: " . count($events) . "\n\n";

// Vypíšeme prvních 5 akcí a klíče související s pořadateli / kluby
$shown = 0;
foreach ($events as $id => $ev) {
    if (!is_array($ev)) {
        continue;
    }

    echo "=== ID klíče v Data: $id | Event ID: " . ($ev['ID'] ?? '?') . " | Název: " . ($ev['Name'] ?? '?') . " ===\n";

    foreach ($ev as $k => $v) {
        if (preg_match('/org|club|region|sport|level|disc/i', (string) $k)) {
            echo "  [$k] => " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    echo "\n";

    $shown++;
    if ($shown >= 5) {
        break;
    }
}
