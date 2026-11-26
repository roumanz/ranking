<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : 9794;
$url = sprintf('https://oris.ceskyorientak.cz/API/?format=json&method=getEvent&id=%d', $eventId);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => 'o-tools-ranking-debug/1.0',
]);
$raw = curl_exec($ch);
curl_close($ch);

$resp = json_decode((string) $raw, true);
$classes = $resp['Data']['Classes'] ?? [];

echo "Zavod ID: $eventId | Pocet kategorii: " . count($classes) . "\n\n";

foreach ($classes as $cid => $cls) {
    if (!is_array($cls)) {
        continue;
    }
    $rankVal = (string) ($cls['Ranking'] ?? '0');
    $coefVal = (string) ($cls['RankingKoef'] ?? '0');
    if (($rankVal !== '' && $rankVal !== '0') || ($coefVal !== '' && $coefVal !== '0')) {
        echo "Class [$cid] (" . ($cls['Name'] ?? '?') . "): " . json_encode($cls, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
