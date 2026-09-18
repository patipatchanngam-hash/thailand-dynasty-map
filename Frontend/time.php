<?php
require __DIR__ . '/config.php';

$rulers = [];
$error = null;

try {
    foreach (KINGDOMS as $table => $k) {
        $rows = db()->query(
            "SELECT id, name, reignstart, reignend, relationship, monarch
             FROM public.$table
             WHERE reignstart IS NOT NULL AND latitude IS NOT NULL
             ORDER BY reignstart, reignend"
        )->fetchAll();
        foreach ($rows as $row) {
            $row['table'] = $table;
            $rulers[] = $row;
        }
    }
} catch (PDOException $ex) {
    $error = db_error($ex, 'time');
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ไทม์ไลน์ราชวงศ์ไทย</title>
    <link rel="icon" href="assets/crown.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        main { max-width: 1400px; margin: 16px auto; padding-inline: 16px; }

        .controls { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
        .row { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; }

        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px; background: #eef0f4;
            cursor: pointer; font-size: 14px; user-select: none;
            border: 2px solid transparent;
        }
        .chip input { display: none; }
        .chip.on { background: #fff; border-color: var(--c); }
        .chip .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--c); border: 1px solid rgba(0,0,0,.2); }
        .chip:not(.on) { opacity: .55; }

        select, input[type=range] { font: inherit; }
        select { padding: 6px 10px; border-radius: 6px; border: 1px solid #d1d5db; }

        .timeline { margin-top: 16px; overflow: auto; position: relative; max-height: 72vh; }
        .canvas { position: relative; }

        .axis {
            position: sticky; top: 0; z-index: 5; height: 34px;
            background: #fff; border-bottom: 1px solid #e5e7eb;
        }
        .tick { position: absolute; top: 0; height: 100%; border-left: 1px solid #d1d5db; font-size: 12px; padding: 8px 0 0 4px; white-space: nowrap; color: var(--muted); }
        .gridline { position: absolute; top: 34px; bottom: 0; border-left: 1px dashed #eceef2; pointer-events: none; }

        .lane-row { position: relative; border-bottom: 1px solid #f0f1f4; }
        .row-label {
            position: sticky; left: 0; z-index: 4; width: 170px; height: 100%;
            background: #fff; border-right: 1px solid #e5e7eb;
            display: flex; align-items: center; gap: 8px; padding: 0 10px;
            font-weight: 600; font-size: 14px;
        }
        .row-label .dot { width: 12px; height: 12px; border-radius: 50%; flex: none; }

        .bar {
            position: absolute; height: 26px; border-radius: 6px;
            font: inherit; font-size: 12px; line-height: 24px; padding: 0 6px; text-align: left;
            overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
            border: 1px solid rgba(0,0,0,.18); cursor: pointer;
            transition: box-shadow .15s ease, transform .15s ease;
        }
        .bar:hover, .bar:focus-visible, .bar.active { box-shadow: 0 3px 10px rgba(0,0,0,.25); transform: translateY(-1px); z-index: 3; }
        .bar:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }
        .bar.active { outline: 2px solid #111; }

        .detail {
            position: fixed; right: 16px; bottom: 16px; width: 320px; max-width: calc(100% - 32px);
            padding: 16px; z-index: 50;
        }
        .detail h3 { margin: 0 0 4px; padding-right: 24px; }
        .detail p { margin: 4px 0; font-size: 14px; }
        .detail .close { position: absolute; top: 8px; right: 10px; border: none; background: none; font-size: 20px; cursor: pointer; }
        .detail .btn { margin-top: 10px; display: inline-block; text-decoration: none; }

        .error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; }
        .empty { padding: 30px; text-align: center; color: var(--muted); }
    </style>
</head>

<body>
    <header class="site">
        <h1>ไทม์ไลน์ราชวงศ์ไทย</h1>
        <?= nav('time.php') ?>
    </header>

    <main>
        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="controls card">
            <div class="row">
                <label class="chip on" style="--c:#1e4f8a"><input type="checkbox" id="selectAll" checked>เลือกทั้งหมด</label>
            </div>
            <div class="row" id="kingdomChips">
                <?php foreach (KINGDOMS as $key => $k): ?>
                    <label class="chip on" style="--c:<?= e($k['color']) ?>">
                        <input type="checkbox" value="<?= e($key) ?>" checked>
                        <span class="dot"></span><?= e($k['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="row">
                <label>ศักราช
                    <select id="era">
                        <option value="BE">พ.ศ.</option>
                        <option value="CE">ค.ศ.</option>
                        <option value="AP">ร.ศ.</option>
                    </select>
                </label>
                <label>ซูม <input type="range" id="zoom" min="1" max="30" value="4"></label>
                <span style="color:var(--muted);font-size:13px">คลิกที่แถบเพื่อดูรายละเอียด</span>
            </div>
        </section>

        <section class="timeline card" id="timeline">
            <div class="canvas" id="canvas"></div>
        </section>
    </main>

    <div class="detail card" id="detail" role="region" aria-live="polite" aria-label="รายละเอียด" hidden>
        <button class="close" id="detailClose" aria-label="ปิด">×</button>
        <div id="detailBody"></div>
    </div>

    <script>
        const KINGDOMS = <?= js(KINGDOMS) ?>;
        const RULERS = <?= js($rulers) ?>;

        const LABEL_W = 170, LANE_H = 32, ROW_PAD = 8, MIN_BAR = 70;
        const ERA_OFFSET = { BE: 0, CE: -543, AP: -2324 };
        const ERA_PREFIX = { BE: 'พ.ศ.', CE: 'ค.ศ.', AP: 'ร.ศ.' };

        const timeline = document.getElementById('timeline');
        const canvas = document.getElementById('canvas');
        const eraSel = document.getElementById('era');
        const zoom = document.getElementById('zoom');
        const selectAll = document.getElementById('selectAll');
        const chips = [...document.querySelectorAll('#kingdomChips input')];

        function fmt(be, era) {
            if (be === null || be === undefined) return 'ไม่ปรากฏ';
            const y = be + ERA_OFFSET[era];
            return y >= 0 ? `${ERA_PREFIX[era]} ${y}` : `ก่อน ${ERA_PREFIX[era]} ${-y}`;
        }

        function el(tag, cls, text) {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        }

        // Readable text color for a given background.
        function textColor(hex) {
            const n = parseInt(hex.slice(1), 16);
            const l = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
            return l > 0.6 ? '#1b1b1b' : '#fff';
        }

        // Scale of the last render, used to keep the same year centred when zooming or filtering.
        let lastScale = null;
        // Midpoint of the visible bar area (to the right of the sticky kingdom labels), in canvas px.
        const viewCenter = () => timeline.scrollLeft + (timeline.clientWidth + LABEL_W) / 2;

        function render() {
            const era = eraSel.value;
            const pxPerYear = +zoom.value;
            const active = chips.filter(c => c.checked).map(c => c.value);
            const data = RULERS.filter(r => active.includes(r.table));

            const centerBE = lastScale ? lastScale.startBE + (viewCenter() - LABEL_W) / lastScale.pxPerYear : null;

            canvas.innerHTML = '';
            if (!data.length) {
                lastScale = null;
                canvas.appendChild(el('div', 'empty', 'ไม่มีข้อมูลที่จะแสดง'));
                return;
            }

            const minBE = Math.min(...data.map(r => r.reignstart));
            const maxBE = Math.max(...data.map(r => r.reignend ?? r.reignstart));
            const step = pxPerYear >= 12 ? 10 : pxPerYear >= 4 ? 50 : 100;
            const off = ERA_OFFSET[era];
            const startBE = Math.floor((minBE + off) / step) * step - off;
            const endBE = Math.ceil((maxBE + off) / step) * step - off + step;
            const x = be => LABEL_W + (be - startBE) * pxPerYear;
            const width = x(endBE) + 20;
            canvas.style.width = width + 'px';

            // Axis
            const axis = el('div', 'axis');
            axis.style.width = width + 'px';
            const corner = el('div', 'row-label', 'อาณาจักร');
            corner.style.position = 'absolute';
            axis.appendChild(corner);
            for (let be = startBE; be <= endBE; be += step) {
                const t = el('div', 'tick', fmt(be, era));
                t.style.left = x(be) + 'px';
                axis.appendChild(t);
                const g = el('div', 'gridline');
                g.style.left = x(be) + 'px';
                canvas.appendChild(g);
            }
            canvas.appendChild(axis);

            // One row per kingdom, bars packed into lanes so they never overlap.
            active.forEach(table => {
                const items = data.filter(r => r.table === table);
                if (!items.length) return;
                const color = KINGDOMS[table].color;
                const laneEnds = [];
                const placed = items.map(r => {
                    const left = x(r.reignstart);
                    const w = Math.max((( r.reignend ?? r.reignstart) - r.reignstart) * pxPerYear, MIN_BAR);
                    let lane = laneEnds.findIndex(end => end <= left);
                    if (lane === -1) { lane = laneEnds.length; laneEnds.push(0); }
                    laneEnds[lane] = left + w + 2;
                    return { r, left, w, lane };
                });

                const row = el('div', 'lane-row');
                const h = laneEnds.length * LANE_H + ROW_PAD * 2;
                row.style.height = h + 'px';
                row.style.width = width + 'px';

                const label = el('div', 'row-label');
                const dot = el('span', 'dot');
                dot.style.background = color;
                label.append(dot, KINGDOMS[table].name);
                row.appendChild(label);

                placed.forEach(({ r, left, w, lane }) => {
                    const bar = el('button', 'bar', r.name);
                    bar.type = 'button';
                    bar.style.left = left + 'px';
                    bar.style.width = w + 'px';
                    bar.style.top = (ROW_PAD + lane * LANE_H) + 'px';
                    bar.style.background = color;
                    bar.style.color = textColor(color);
                    bar.title = `${r.name}\n${fmt(r.reignstart, era)} – ${fmt(r.reignend, era)}`;
                    bar.addEventListener('click', () => showDetail(r, bar));
                    row.appendChild(bar);
                });
                canvas.appendChild(row);
            });

            lastScale = { startBE, pxPerYear };
            if (centerBE !== null) {
                timeline.scrollLeft = x(centerBE) - (timeline.clientWidth + LABEL_W) / 2;
            }
        }

        // Zoom slider fires many input events; render at most once per frame.
        let frame = 0;
        function scheduleRender() {
            if (!frame) frame = requestAnimationFrame(() => { frame = 0; render(); });
        }

        function showDetail(r, bar) {
            document.querySelectorAll('.bar.active').forEach(b => b.classList.remove('active'));
            bar.classList.add('active');
            const body = document.getElementById('detailBody');
            body.innerHTML = '';
            body.append(
                el('h3', '', r.name),
                el('p', '', `${KINGDOMS[r.table].name}${r.relationship ? ' · ' + r.relationship : ''}`),
                el('p', '', r.monarch ? `ราชวงศ์: ${r.monarch}` : ''),
                el('p', '', `ครองราชย์: ${fmt(r.reignstart, 'BE')} – ${fmt(r.reignend, 'BE')}`),
                el('p', '', `${fmt(r.reignstart, 'CE')} – ${fmt(r.reignend, 'CE')}`),
                el('p', '', `${fmt(r.reignstart, 'AP')} – ${fmt(r.reignend, 'AP')}`),
            );
            const link = el('a', 'btn', 'ดูแผนผังราชวงศ์');
            link.href = `family_tree.php?table=${encodeURIComponent(r.table)}&search=${encodeURIComponent(r.name)}`;
            body.appendChild(link);
            document.getElementById('detail').hidden = false;
        }

        function closeDetail() {
            document.getElementById('detail').hidden = true;
            document.querySelectorAll('.bar.active').forEach(b => b.classList.remove('active'));
        }
        document.getElementById('detailClose').addEventListener('click', closeDetail);
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeDetail(); });

        function syncChips() {
            chips.forEach(c => c.parentElement.classList.toggle('on', c.checked));
            selectAll.checked = chips.every(c => c.checked);
            selectAll.parentElement.classList.toggle('on', selectAll.checked);
        }

        chips.forEach(c => c.addEventListener('change', () => { syncChips(); render(); }));
        selectAll.addEventListener('change', () => { chips.forEach(c => c.checked = selectAll.checked); syncChips(); render(); });
        eraSel.addEventListener('change', render);
        zoom.addEventListener('input', scheduleRender);

        render();
    </script>
</body>

</html>
