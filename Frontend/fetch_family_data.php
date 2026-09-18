<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
// Errors must not be cached; the success path overrides this below.
header('Cache-Control: no-store');

$table = $_GET['table'] ?? 'ratanakosin';
if (!isset(KINGDOMS[$table])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid table name']);
    exit;
}

try {
    // $table is whitelisted above, so interpolating it is safe.
    $rows = db()->query(
        "SELECT id, parent_id, name, relationship, birth, death, img, tags, monarch, wife, child,
                father, mother, urlking, ppid, reignstart, reignend, gender, latitude, longitude, url
         FROM public.$table
         ORDER BY id"
    )->fetchAll();
} catch (PDOException $ex) {
    http_response_code(500);
    echo json_encode(['error' => db_error($ex, "fetch_family_data:$table")], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($rows as &$row) {
    $row['tags'] = $row['tags'] !== null ? explode(',', trim($row['tags'], '{}')) : [];
}
unset($row);

// The dynasty data only changes when the database is reloaded, so let browsers
// keep it while the visitor switches back and forth between kingdoms.
header('Cache-Control: public, max-age=3600');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
