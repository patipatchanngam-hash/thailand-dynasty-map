<?php
// Shared configuration: database connection + kingdom metadata.
// Override connection settings with DATABASE_URL (postgresql://user:pass@host:port/db?sslmode=require)
// or individual environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSLMODE).

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $url = parse_url(getenv('DATABASE_URL') ?: '') ?: [];
        parse_str($url['query'] ?? '', $query);

        $host = $url['host'] ?? (getenv('DB_HOST') ?: 'localhost');
        $port = $url['port'] ?? (getenv('DB_PORT') ?: '5432');
        $name = isset($url['path']) ? ltrim($url['path'], '/') : (getenv('DB_NAME') ?: 'postgres');
        $user = isset($url['user']) ? urldecode($url['user']) : (getenv('DB_USER') ?: 'postgres');
        $pass = isset($url['pass']) ? urldecode($url['pass']) : (getenv('DB_PASS') ?: 'root');
        $ssl  = $query['sslmode'] ?? (getenv('DB_SSLMODE') ?: 'prefer');

        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;sslmode=$ssl", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// Table name => [Thai name, color]. Order is chronological-ish and used everywhere.
const KINGDOMS = [
    'funan'       => ['name' => 'ฟูนาน',               'color' => '#FFA07A'],
    'tampornling' => ['name' => 'อาณาจักรตามพรลิงค์',   'color' => '#DDA0DD'],
    'janela'      => ['name' => 'เจนละ',               'color' => '#88B04B'],
    'hripunchai'  => ['name' => 'หริภุญชัย',            'color' => '#F5DEB3'],
    'srivichai'   => ['name' => 'อาณาจักรศรีวิชัย',      'color' => '#92A8D1'],
    'panakorn'    => ['name' => 'อาณาจักรพระนคร',       'color' => '#F6C3C1'],
    'lavo'        => ['name' => 'อาณาจักรละโว้',         'color' => '#FFCC00'],
    'sukothai'    => ['name' => 'อาณาจักรสุโขทัย',       'color' => '#C39BD3'],
    'lanna'       => ['name' => 'อาณาจักรล้านนา',        'color' => '#76D7C4'],
    'ayuttaya'    => ['name' => 'อาณาจักรอยุธยา',        'color' => '#F1948A'],
    'cotraboon'   => ['name' => 'อาณาจักรโคตรบูร',       'color' => '#F7DC6F'],
    'lanchang'    => ['name' => 'อาณาจักรล้านช้าง',       'color' => '#85C1E9'],
    'kamenravak'  => ['name' => 'สมัยละแวก',            'color' => '#D5DBDB'],
    'ratanakosin' => ['name' => 'กรุงรัตนโกสินทร์',       'color' => '#48C9B0'],
];

// PDO messages carry the database host, IP, user and database name, so they must
// never reach the browser. Log the real reason and hand back something generic.
function db_error(PDOException $ex, string $context): string
{
    error_log("[$context] " . $ex->getMessage());
    return 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// JSON that is safe to embed inside a <script> block.
function js($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function nav(string $active): string
{
    $links = ['index.php' => 'แผนที่', 'family_tree.php' => 'แผนผังราชวงศ์', 'time.php' => 'ไทม์ไลน์'];
    $html = '';
    foreach ($links as $href => $label) {
        $cls = $href === $active ? ' class="active"' : '';
        $html .= "<li><a href=\"$href\"$cls>$label</a></li>";
    }
    return "<nav><ul>$html</ul></nav>";
}
