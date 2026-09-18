<?php
require __DIR__ . '/config.php';

$columns = 'latitude, longitude, name, kingdomname, url, urlking, imgplace, reignstart, reignend, after, before, relationship';

// Search uses GET so results can be bookmarked/shared and Back works without a resubmit prompt.
$searched   = isset($_GET['searchType']);
$searchType = $_GET['searchType'] ?? 'year';
$era        = $_GET['era'] ?? 'BE';
$beforeAP   = $_GET['beforeAP'] ?? 'normalAP';
$yearRaw    = trim($_GET['year'] ?? '');
$nameRaw    = trim($_GET['name'] ?? '');
$relRaw     = trim($_GET['relationship'] ?? '');

// No kingdoms in the URL means all of them (the form omits the list when everything is checked).
$selected = array_values(array_filter((array) ($_GET['kingdoms'] ?? []), fn($k) => isset(KINGDOMS[$k])));
if (!$selected) {
    $selected = array_keys(KINGDOMS);
}

// Convert the entered year to Buddhist Era (the unit stored in the database).
$yearBE = null;
if ($searchType === 'year' && is_numeric($yearRaw)) {
    $y = (int) $yearRaw;
    $yearBE = match ($era) {
        'CE'    => $y + 543,
        'AP'    => $beforeAP === 'beforeAP' ? 2324 - $y : 2324 + $y,
        default => $y,
    };
}

$locations = [];
$error = null;

try {
    foreach ($selected as $table) {
        if ($searchType === 'name' && $nameRaw !== '' && $searched) {
            $stmt = db()->prepare("SELECT $columns FROM public.$table WHERE latitude IS NOT NULL AND name ILIKE :q ORDER BY reignstart NULLS LAST, id");
            $stmt->execute([':q' => '%' . $nameRaw . '%']);
        } elseif ($searchType === 'relationship' && $relRaw !== '' && $searched) {
            $rel = preg_match('/(\d+)/', $relRaw, $m) ? 'รัชกาลที่ ' . $m[1] : $relRaw;
            $stmt = db()->prepare("SELECT $columns FROM public.$table WHERE latitude IS NOT NULL AND (relationship = :rel OR relationship LIKE :relTimes) ORDER BY id");
            $stmt->execute([':rel' => $rel, ':relTimes' => $rel . ' ครั้งที่%']);
        } elseif ($yearBE !== null && $searched) {
            $stmt = db()->prepare("SELECT $columns FROM public.$table WHERE latitude IS NOT NULL AND :y BETWEEN reignstart AND COALESCE(reignend, reignstart) ORDER BY reignstart");
            $stmt->execute([':y' => $yearBE]);
        } else {
            // Default view: the first known ruler of each kingdom.
            $stmt = db()->query("SELECT $columns FROM public.$table WHERE latitude IS NOT NULL ORDER BY reignstart NULLS LAST, id LIMIT 1");
        }
        foreach ($stmt->fetchAll() as $row) {
            $row['table'] = $table;
            $locations[] = $row;
        }
    }
} catch (PDOException $ex) {
    $error = db_error($ex, 'index');
}

$message = '';
if ($searched && !$error) {
    $message = $locations ? 'พบ ' . count($locations) . ' รายการ' : 'ไม่พบข้อมูลที่ค้นหา';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แผนที่ราชวงศ์ไทย</title>
    <link rel="icon" href="assets/crown.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <link rel="stylesheet" href="assets/style.css">
    <style>
        html, body { height: 100%; }
        body { display: flex; flex-direction: column; }

        .map-wrap { position: relative; flex: 1; min-height: 420px; }
        #map { position: absolute; inset: 0; }

        .panel {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 320px;
            max-width: calc(100% - 24px);
            max-height: calc(100% - 24px);
            overflow-y: auto;
            padding: 14px;
            z-index: 1000;
        }

        .panel h2 { margin: 0 0 10px; font-size: 17px; display: flex; justify-content: space-between; align-items: center; }
        .panel .toggle { display: none; background: none; border: none; font-size: 20px; cursor: pointer; }

        .kingdom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 8px; font-size: 14px; }
        .kingdom-grid label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
        .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; border: 1px solid rgba(0,0,0,.25); }

        fieldset { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px 10px; margin: 0 0 10px; }
        legend { font-weight: 600; padding: 0 4px; }

        .field { margin-bottom: 10px; }
        .field label { display: block; font-size: 14px; margin-bottom: 4px; }
        .field input, .field select {
            width: 100%; padding: 8px; font: inherit;
            border: 1px solid #d1d5db; border-radius: 6px;
        }
        .actions { display: flex; gap: 8px; }
        .actions .btn { flex: 1; }

        .ruler-marker span {
            display: block; width: 18px; height: 18px; border-radius: 50%;
            border: 1.5px solid #1f2937; box-shadow: 0 1px 3px rgba(0,0,0,.3);
        }

        .error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; margin-bottom: 10px; font-size: 14px; }

        .popup { text-align: center; min-width: 220px; font-family: 'Sarabun', sans-serif; }
        .popup img.place { width: 100%; max-height: 130px; object-fit: cover; border-radius: 6px; }
        .popup h3 { margin: 6px 0 2px; }
        .popup p { margin: 3px 0; }
        .popup .links { display: flex; justify-content: center; gap: 10px; margin: 6px 0; }
        .popup .links a { font-size: 13px; }
        .popup .reign { font-size: 13px; color: #4b5563; }

        @media (max-width: 640px) {
            .panel { top: auto; bottom: 12px; right: 12px; left: 12px; width: auto; max-height: 55%; }
            .panel .toggle { display: block; }
            .panel.collapsed form { display: none; }
        }
    </style>
</head>

<body>
    <header class="site">
        <h1>แผนที่ราชวงศ์ไทย</h1>
        <?= nav('index.php') ?>
    </header>

    <div class="map-wrap">
        <div id="map"></div>

        <div class="panel card" id="panel">
            <h2>ค้นหา <button type="button" class="toggle" id="panelToggle" aria-label="ย่อ/ขยาย">▾</button></h2>

            <?php if ($error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="GET" id="searchForm">
                <fieldset>
                    <legend>อาณาจักร</legend>
                    <label style="display:flex;gap:6px;margin-bottom:6px;cursor:pointer">
                        <input type="checkbox" id="selectAll"> เลือกทั้งหมด
                    </label>
                    <div class="kingdom-grid">
                        <?php foreach (KINGDOMS as $key => $k): ?>
                            <label>
                                <input type="checkbox" name="kingdoms[]" value="<?= e($key) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
                                <span class="dot" style="background:<?= e($k['color']) ?>"></span><?= e($k['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="field">
                    <label for="searchType">ค้นหาด้วย</label>
                    <select id="searchType" name="searchType">
                        <option value="year" <?= $searchType === 'year' ? 'selected' : '' ?>>ปี</option>
                        <option value="relationship" <?= $searchType === 'relationship' ? 'selected' : '' ?>>รัชกาลที่</option>
                        <option value="name" <?= $searchType === 'name' ? 'selected' : '' ?>>พระนาม</option>
                    </select>
                </div>

                <div data-for="year">
                    <div class="field">
                        <label for="era">ศักราช</label>
                        <select id="era" name="era">
                            <option value="BE" <?= $era === 'BE' ? 'selected' : '' ?>>พุทธศักราช (พ.ศ.)</option>
                            <option value="CE" <?= $era === 'CE' ? 'selected' : '' ?>>คริสต์ศักราช (ค.ศ.)</option>
                            <option value="AP" <?= $era === 'AP' ? 'selected' : '' ?>>รัตนโกสินทรศก (ร.ศ.)</option>
                        </select>
                    </div>
                    <div class="field" id="beforeAPField">
                        <label for="beforeAP">ช่วงเวลา</label>
                        <select id="beforeAP" name="beforeAP">
                            <option value="normalAP" <?= $beforeAP === 'normalAP' ? 'selected' : '' ?>>ร.ศ.</option>
                            <option value="beforeAP" <?= $beforeAP === 'beforeAP' ? 'selected' : '' ?>>ก่อน ร.ศ.</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="year">ปี</label>
                        <input type="number" id="year" name="year" value="<?= e($yearRaw) ?>" placeholder="เช่น 2400">
                    </div>
                </div>

                <div class="field" data-for="relationship">
                    <label for="relationship">รัชกาลที่</label>
                    <input type="number" min="1" id="relationship" name="relationship" value="<?= e($relRaw) ?>" placeholder="เช่น 5">
                </div>

                <div class="field" data-for="name">
                    <label for="name">พระนาม</label>
                    <input type="text" id="name" name="name" value="<?= e($nameRaw) ?>" placeholder="เช่น นเรศวร">
                </div>

                <div class="actions">
                    <button type="submit" class="btn">ค้นหา</button>
                    <a href="index.php" class="btn secondary" style="text-align:center;text-decoration:none">รีเซ็ต</a>
                </div>
            </form>
        </div>
    </div>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script>
        const KINGDOMS = <?= js(KINGDOMS) ?>;
        const locations = <?= js($locations) ?>;
        const message = <?= js($message) ?>;

        const $ = (sel) => document.querySelector(sel);
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        function showToast(text) {
            const t = $('#toast');
            t.textContent = text;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 2500);
        }

        function fmtYears(be) {
            if (be === null || be === undefined) return null;
            const ap = be - 2324;
            return {
                BE: `พ.ศ. ${be}`,
                CE: be - 543 >= 0 ? `ค.ศ. ${be - 543}` : `ก่อน ค.ศ. ${543 - be}`,
                AP: ap >= 0 ? `ร.ศ. ${ap}` : `ก่อน ร.ศ. ${-ap}`,
            };
        }

        function reignLine(loc, era) {
            const s = fmtYears(loc.reignstart), e = fmtYears(loc.reignend);
            return `${s ? s[era] : 'ไม่ปรากฏ'} – ${e ? e[era] : 'ไม่ปรากฏ'}`;
        }

        // ---- Form behaviour ----
        const searchType = $('#searchType');
        const era = $('#era');
        function syncFields() {
            document.querySelectorAll('[data-for]').forEach(el => el.hidden = el.dataset.for !== searchType.value);
            $('#beforeAPField').hidden = era.value !== 'AP';
        }
        searchType.addEventListener('change', syncFields);
        era.addEventListener('change', syncFields);
        syncFields();

        const selectAll = $('#selectAll');
        const boxes = [...document.querySelectorAll('input[name="kingdoms[]"]')];
        const syncSelectAll = () => selectAll.checked = boxes.every(b => b.checked);
        selectAll.addEventListener('change', () => boxes.forEach(b => b.checked = selectAll.checked));
        boxes.forEach(b => b.addEventListener('change', syncSelectAll));
        syncSelectAll();

        const form = $('#searchForm');
        form.addEventListener('submit', (ev) => {
            if (!boxes.some(b => b.checked)) { ev.preventDefault(); return showToast('กรุณาเลือกอาณาจักรอย่างน้อยหนึ่งอาณาจักร'); }
            const input = { year: '#year', relationship: '#relationship', name: '#name' }[searchType.value];
            if (!$(input).value.trim()) { ev.preventDefault(); showToast('กรุณากรอกข้อมูลที่ต้องการค้นหา'); $(input).focus(); return; }

            // Keep the shareable URL short: disabled fields are not submitted.
            form.querySelectorAll('[data-for], #beforeAPField').forEach(sec => {
                if (sec.hidden) sec.querySelectorAll('input, select').forEach(i => i.disabled = true);
            });
            if (boxes.every(b => b.checked)) boxes.forEach(b => b.disabled = true);
        });
        // Re-enable fields if the page is restored from the back/forward cache.
        window.addEventListener('pageshow', () => form.querySelectorAll(':disabled').forEach(i => i.disabled = false));

        $('#panelToggle').addEventListener('click', () => $('#panel').classList.toggle('collapsed'));
        L.DomEvent.disableClickPropagation($('#panel'));
        L.DomEvent.disableScrollPropagation($('#panel'));

        // ---- Map ----
        const map = L.map('map', { zoomControl: false }).setView([13.5, 101.0], 6);
        L.control.zoom({ position: 'bottomleft' }).addTo(map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Nearby rulers are clustered; rulers sharing the exact same spot spiderfy when the cluster is clicked.
        const cluster = L.markerClusterGroup({ maxClusterRadius: 40, showCoverageOnHover: false });
        const markers = [];
        locations.forEach(loc => {
            if (loc.latitude == null || loc.longitude == null) return;
            const color = (KINGDOMS[loc.table] || {}).color || '#888';
            const m = L.marker([+loc.latitude, +loc.longitude], {
                icon: L.divIcon({
                    className: 'ruler-marker',
                    html: `<span style="background:${esc(color)}"></span>`,
                    iconSize: [18, 18],
                }),
                title: loc.name,
            });
            m.bindPopup(() => popupHtml(loc), { maxWidth: 280 });
            cluster.addLayer(m);
            markers.push(m);
        });
        map.addLayer(cluster);

        if (markers.length) {
            map.fitBounds(cluster.getBounds().pad(0.3), { maxZoom: 9 });
            if (markers.length === 1) cluster.zoomToShowLayer(markers[0], () => markers[0].openPopup());
        }

        function popupHtml(loc) {
            const tableName = (KINGDOMS[loc.table] || {}).name || loc.kingdomname;
            const treeUrl = `family_tree.php?table=${encodeURIComponent(loc.table)}&search=${encodeURIComponent(loc.name)}`;
            return `
                <div class="popup">
                    ${loc.imgplace ? `<img class="place" src="${esc(loc.imgplace)}" alt="" onerror="this.remove()">` : ''}
                    <h3>${esc(tableName)}</h3>
                    <p><strong>${esc(loc.relationship)}</strong></p>
                    <p>${esc(loc.name)}</p>
                    <p class="reign">${reignLine(loc, 'BE')}<br>${reignLine(loc, 'CE')}<br>${reignLine(loc, 'AP')}</p>
                    <p class="reign">ก่อนหน้า: ${esc(loc.before || 'ไม่ปรากฏ')}<br>ถัดไป: ${esc(loc.after || 'ไม่ปรากฏ')}</p>
                    <div class="links">
                        ${loc.urlking ? `<a href="${esc(loc.urlking)}" target="_blank" rel="noopener">วิกิพีเดีย (พระองค์)</a>` : ''}
                        ${loc.url ? `<a href="${esc(loc.url)}" target="_blank" rel="noopener">วิกิพีเดีย (อาณาจักร)</a>` : ''}
                    </div>
                    <a class="btn" style="display:inline-block;color:#fff;text-decoration:none" href="${esc(treeUrl)}">ดูแผนผังราชวงศ์</a>
                </div>`;
        }

        if (message) showToast(message);
    </script>
</body>

</html>
