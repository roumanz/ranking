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
