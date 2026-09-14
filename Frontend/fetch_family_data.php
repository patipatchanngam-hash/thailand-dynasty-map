<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

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
    echo json_encode(['error' => 'Database error: ' . $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($rows as &$row) {
    $row['tags'] = $row['tags'] !== null ? explode(',', trim($row['tags'], '{}')) : [];
}
unset($row);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
