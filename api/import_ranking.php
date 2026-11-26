<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$dbConfigFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbConfigFile)) {
    throw new RuntimeException('Chybí konfigurační soubor config/db.php.');
}

$cfg = require $dbConfigFile;
if (!is_array($cfg)) {
    throw new RuntimeException('Soubor config/db.php musí vracet pole s parametry připojení.');
}

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $cfg['host'] ?? 'md432.wedos.net',
    $cfg['dbname'] ?? 'd31903_ranking',
    $cfg['charset'] ?? 'utf8mb4'
);

$pdo = new PDO(
    $dsn,
    (string) ($cfg['user'] ?? ''),
    (string) ($cfg['pass'] ?? ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// -----------------------------------------------------------------------------
// 2. Pomocné funkce
// -----------------------------------------------------------------------------
function normalizeLastDayOfMonth(string $dateStr): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', trim($dateStr));
    if (!$dt) {
        throw new InvalidArgumentException('Neplatné datum (očekáváno YYYY-MM-DD).');
    }
    return $dt->modify('last day of this month')->format('Y-m-d');
}

function downloadOrisRankingCsv(string $decisionDate, string $gender, int $rankType, string &$usedUrl): string
{
    $usedUrl = sprintf(
        'https://oris.ceskyorientak.cz/ranking_export?date=%s&sport=1&gender=%s&ranktype=%d&csv=1',
        urlencode($decisionDate),
        urlencode($gender),
        $rankType
    );

    $ch = curl_init($usedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'o-tools-ranking-importer/1.0',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        throw new RuntimeException("Nepodařilo se stáhnout CSV z ORISu (HTTP {$code}): {$err}");
    }

    return (string) $raw;
}

function parseRankingCsv(string $csvContent): array
{
    // Odstranění případného UTF-8 BOM a sjednocení konců řádků
    $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
    $csvContent = str_replace(["\r\n", "\r"], "\n", $csvContent);

    $stream = fopen('php://memory', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Nelze otevřít paměťový stream pro CSV.');
    }
    fwrite($stream, $csvContent);
    rewind($stream);

    $header = fgetcsv($stream, 0, ';', '"', '\\');
    if (!$header || count($header) < 6) {
        fclose($stream);
        throw new RuntimeException('Chybí nebo je neplatná hlavička CSV souboru.');
    }

    // Mapování názvů sloupců pro případné drobné odlišnosti v pořadí
    $map = [];
    foreach ($header as $idx => $colName) {
        $norm = mb_strtolower(trim((string) $colName), 'UTF-8');
        $map[$norm] = $idx;
    }

    $idxPos   = $map['poradi'] ?? 0;
    $idxLast  = $map['prijmeni'] ?? 1;
    $idxFirst = $map['jmeno'] ?? 2;
    $idxReg   = $map['reg. c.'] ?? ($map['reg.c.'] ?? 3);
    $idxPts   = $map['body'] ?? 4;
    $idxCoef  = $map['rank.c'] ?? ($map['rank.c.'] ?? 5);

    $rows = [];
    while (($line = fgetcsv($stream, 0, ';', '"', '\\')) !== false) {
        if ($line === [null] || count($line) < 4) {
            continue;
        }

        $regNo = strtoupper(trim((string) ($line[$idxReg] ?? '')));
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
}

function importRankingSnapshot(
    PDO $pdo,
    string $rankingType,
    string $gender,
    string $decisionDate,
    string $sourceUrl,
    array $entries
): int {
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
            ':ranking_type' => $rankingType,
            ':gender'       => $gender,
            ':decision_date'=> $decisionDate,
            ':source_url'   => $sourceUrl,
        ]);

        $snapshotId = (int) $pdo->lastInsertId();

        // Při opakovaném importu stejného snapshotu vyčistíme předchozí položky
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
        return $snapshotId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -----------------------------------------------------------------------------
// 3. Zpracování požadavku
// -----------------------------------------------------------------------------
$rankingType = strtolower(trim((string) ($_REQUEST['ranking_type'] ?? 'sprint')));
if (!in_array($rankingType, ['forest', 'sprint'], true)) {
    $rankingType = 'sprint';
}

$gender = strtoupper(trim((string) ($_REQUEST['gender'] ?? 'M')));
if ($gender === 'F') {
    $gender = 'W';
}
if (!in_array($gender, ['M', 'W'], true)) {
    $gender = 'M';
}

$rawDate = trim((string) ($_REQUEST['date'] ?? '2026-11-30'));
$defaultRankType = ($rankingType === 'forest') ? 2 : 8;
$rankTypeCode = isset($_REQUEST['ranktype']) && $_REQUEST['ranktype'] !== ''
    ? (int) $_REQUEST['ranktype']
    : $defaultRankType;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
        $decisionDate = normalizeLastDayOfMonth($rawDate);
        $sourceUrl = '';

        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $csvContent = (string) file_get_contents($_FILES['csv_file']['tmp_name']);
            $sourceUrl = 'upload:' . basename((string) ($_FILES['csv_file']['name'] ?? 'ranking.csv'));
        } else {
            $csvContent = downloadOrisRankingCsv($decisionDate, $gender, $rankTypeCode, $sourceUrl);
        }

        $entries = parseRankingCsv($csvContent);
        if (count($entries) === 0) {
            throw new RuntimeException('V CSV nebyly nalezeny žádné platné záznamy závodníků.');
        }

        $snapshotId = importRankingSnapshot($pdo, $rankingType, $gender, $decisionDate, $sourceUrl, $entries);
        $message = sprintf(
            'Hotovo! Snapshot #%d (%s / %s / %s) uložen, naimportováno %d závodníků.',
            $snapshotId,
            strtoupper($rankingType),
            $gender,
            $decisionDate,
            count($entries)
        );
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>Import rankingu (ORIS CSV)</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
    label { display: block; margin-top: 0.75rem; font-weight: 600; }
    input, select { padding: 0.45rem; width: 100%; max-width: 360px; margin-top: 0.25rem; }
    button { margin-top: 1.25rem; padding: 0.6rem 1.2rem; font-size: 1rem; cursor: pointer; }
    .ok { background: #e7f6ec; border: 1px solid #2e7d32; padding: 0.75rem; margin-top: 1rem; }
    .err { background: #fdecea; border: 1px solid #c62828; padding: 0.75rem; margin-top: 1rem; }
  </style>
</head>
<body>
  <h1>Import rankingu z ORIS CSV</h1>

  <?php if ($message !== null): ?>
    <div class="ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <label for="ranking_type">Typ rankingu</label>
    <select id="ranking_type" name="ranking_type">
      <option value="sprint" <?= $rankingType === 'sprint' ? 'selected' : '' ?>>Sprintový (sprint)</option>
      <option value="forest" <?= $rankingType === 'forest' ? 'selected' : '' ?>>Lesní (forest)</option>
    </select>

    <label for="gender">Pohlaví</label>
    <select id="gender" name="gender">
      <option value="M" <?= $gender === 'M' ? 'selected' : '' ?>>Muži (M)</option>
      <option value="W" <?= $gender === 'W' ? 'selected' : '' ?>>Ženy (W)</option>
    </select>

    <label for="date">Rozhodné datum (upraví se na konec měsíce)</label>
    <input id="date" type="date" name="date" value="<?= htmlspecialchars($rawDate, ENT_QUOTES, 'UTF-8') ?>">

    <label for="ranktype">ORIS ranktype (les = 2, sprint = 8)</label>
    <input id="ranktype" type="number" name="ranktype" value="<?= $rankTypeCode ?>">

    <label for="csv_file">Volitelně lokální CSV soubor (pokud nevybereš, stáhne se z ORISu)</label>
    <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt,text/csv">

    <button type="submit">Importovat ranking</button>
  </form>
</body>
</html>
