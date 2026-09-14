<?php
require __DIR__ . '/config.php';

$initialTable = $_GET['table'] ?? 'funan';
if (!isset(KINGDOMS[$initialTable])) {
    $initialTable = 'funan';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แผนผังราชวงศ์</title>
    <link rel="icon" href="assets/crown.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://balkan.app/js/OrgChart.js"></script>
    <style>
        #controls {
            margin: 12px 16px;
            padding: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        #controls select,
        #searchInput {
            font: inherit;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
        }

        #searchInput { width: 240px; }
        #searchInput:focus, #controls select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30, 79, 138, .2); }

        .autocomplete-wrapper { position: relative; }

        #autoCompleteContainer {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 300px;
            max-height: 320px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .12);
            z-index: 2000;
            padding: 4px;
        }

        .suggestion-item {
            padding: 6px 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            border-radius: 6px;
        }

        .suggestion-item:hover, .suggestion-item.focused { background: #f0f2f5; }
        .suggestion-item img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex: none; }
        .suggestion-item span { font-weight: 600; line-height: 1.2; }
        .no-results-item { padding: 8px; color: var(--muted); }

        #tree {
            margin: 0 16px 16px;
            height: calc(100vh - 170px);
            min-height: 420px;
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        #status { margin: 0 16px; color: var(--muted); }
        #status.error { color: #b91c1c; }

        label, [data-binding] { color: #000 !important; }

        .boc-edit-form.female .boc-edit-form-header,
        .boc-edit-form.female .boc-img-button,
        .boc-edit-form.kingfemale .boc-edit-form-header,
        .boc-edit-form.kingfemale .boc-img-button { background-color: #FFB6C1 !important; }

        .boc-edit-form.male .boc-edit-form-header,
        .boc-edit-form.male .boc-img-button,
        .boc-edit-form.kingmale .boc-edit-form-header,
        .boc-edit-form.kingmale .boc-img-button { background-color: #87CEFA !important; }

        .boc-edit-form.searchedmale .boc-edit-form-header,
        .boc-edit-form.searchedfemale .boc-edit-form-header { background-color: #FFD700 !important; }

        [data-n-id] rect:hover { filter: drop-shadow(4px 5px 5px #aeaeae); }
        [data-l-id] path { stroke: #000; }
    </style>
</head>

<body>
    <header class="site">
        <h1>แผนผังราชวงศ์</h1>
        <?= nav('family_tree.php') ?>
    </header>

    <div id="controls" class="card">
        <label for="tableSelect">อาณาจักร</label>
        <select id="tableSelect">
            <?php foreach (KINGDOMS as $key => $k): ?>
                <option value="<?= e($key) ?>" <?= $key === $initialTable ? 'selected' : '' ?>><?= e($k['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="autocomplete-wrapper">
            <input type="text" id="searchInput" placeholder="ค้นหาพระนาม..." autocomplete="off" />
            <div id="autoCompleteContainer" role="listbox" hidden></div>
        </div>
        <button id="resetSearch" class="btn">แสดงทั้งหมด</button>
    </div>
    <div id="status" role="status"></div>
    <div id="tree"></div>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const AVATAR = 'assets/avatar.svg';
            const CROWN = 'assets/crown.svg';

            const tableSelect = document.getElementById('tableSelect');
            const searchInput = document.getElementById('searchInput');
            const resetButton = document.getElementById('resetSearch');
            const autoCompleteContainer = document.getElementById('autoCompleteContainer');
            const treeContainer = document.getElementById('tree');
            const statusEl = document.getElementById('status');
            let allNodes = [];
            let chart;

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            function showToast(text) {
                const t = document.getElementById('toast');
                t.textContent = text;
                t.classList.add('show');
                setTimeout(() => t.classList.remove('show'), 2500);
            }

            function setStatus(text, isError = false) {
                statusEl.textContent = text;
                statusEl.classList.toggle('error', isError);
            }

            // ---- OrgChart templates ----
            OrgChart.templates.ana.defs = '<g transform="matrix(0.05,0,0,0.05,-12,-9)" id="heart"><path fill="#F57C00" d="M438.482,58.61c-24.7-26.549-59.311-41.655-95.573-41.711c-36.291,0.042-70.938,15.14-95.676,41.694l-8.431,8.909  l-8.431-8.909C181.284,5.762,98.663,2.728,45.832,51.815c-2.341,2.176-4.602,4.436-6.778,6.778 c-52.072,56.166-52.072,142.968,0,199.134l187.358,197.581c6.482,6.843,17.284,7.136,24.127,0.654 c0.224-0.212,0.442-0.43,0.654-0.654l187.29-197.581C490.551,201.567,490.551,114.77,438.482,58.61z"/></g>';
            OrgChart.templates.ana.link = '<path stroke-linejoin="round" stroke="#000000" stroke-width="1px" fill="none" d="{edge}" />';
            OrgChart.templates.ana.field_0 =
                '<text data-width="230" data-text-overflow="ellipsis" style="font-size: 20px;" fill="#000000" x="125" y="100" text-anchor="middle">{val}</text>';
            OrgChart.templates.ana.field_1 =
                '<text data-width="130" data-text-overflow="ellipsis" style="font-size: 16px;" fill="#000000" x="230" y="30" text-anchor="end">{val}</text>';
            OrgChart.templates.ana.img_0 =
                '<clipPath id="circleClip"><circle cx="40" cy="40" r="40"></circle></clipPath>' +
                `<image preserveAspectRatio="xMidYMid slice" xlink:href="{val}" x="0" y="0" width="80" height="80" clip-path="url(#circleClip)" onerror="this.onerror=null;this.setAttribute('href','${AVATAR}');"></image>`;

            function wikiElement(label) {
                return function (data, editElement, minWidth, readOnly) {
                    const value = data[editElement.binding];
                    if (!value || value === 'ไม่ปรากฏ') return { html: '' };
                    return {
                        html: `<div style="text-align:center;margin:6px 0">
                                   <a href="${esc(value)}" target="_blank" rel="noopener" style="font-size:16px">${label}</a>
                               </div>`,
                        id: OrgChart.elements.generateId(),
                        value: value
                    };
                };
            }
            OrgChart.elements.link1 = wikiElement('📖 วิกิพีเดีย (พระองค์)');
            OrgChart.elements.link2 = wikiElement('🏛️ วิกิพีเดีย (อาณาจักร)');

            const node = (fill, stroke, strokeWidth, crown) => {
                const t = Object.assign({}, OrgChart.templates.ana);
                t.node = `<rect x="0" y="0" height="110" width="250" fill="${fill}" stroke-width="${strokeWidth}" stroke="${stroke}" rx="15" ry="15"></rect>` +
                    (crown ? `<image x="14" y="-26" width="52" height="32" xlink:href="${CROWN}" />` : '');
                return t;
            };
            OrgChart.templates.male = node('#87CEFA', '#000', 2, false);
            OrgChart.templates.female = node('#FFB6C1', '#000', 2, false);
            OrgChart.templates.kingmale = node('#87CEFA', '#000', 2, true);
            OrgChart.templates.kingfemale = node('#FFB6C1', '#000', 2, true);
            OrgChart.templates.searchedmale = node('#FFE58A', '#FF0000', 5, true);
            OrgChart.templates.searchedfemale = node('#FFE58A', '#FF0000', 5, true);

            // ---- Data helpers ----
            function yearText(be) {
                if (be === null || be === undefined) return 'ไม่ปรากฏ';
                const ap = be - 2324;
                return `พ.ศ. ${be} (ค.ศ. ${be - 543}) ` + (ap < 0 ? `(ก่อน ร.ศ. ${-ap})` : `(ร.ศ. ${ap})`);
            }

            function baseTags(n) {
                const isKing = n.ละติจูด !== null;
                const tags = [n.เพศ === 'หญิง' ? (isKing ? 'kingfemale' : 'female') : (isKing ? 'kingmale' : 'male')];
                if (n._partner) tags.push('partner');
                return tags;
            }

            function markSearched(n) {
                const tags = n._partner ? ['partner'] : [];
                tags.push(n.เพศ === 'หญิง' ? 'searchedfemale' : 'searchedmale');
                n.tags = tags;
            }

            function resetTags() {
                allNodes.forEach(n => n.tags = baseTags(n));
            }

            const findDescendants = (nodeId, nodes) => {
                if (nodeId === null || nodeId === undefined) return [];
                let result = [];
                nodes.forEach(n => {
                    if (n.pid === nodeId) {
                        result.push(n);
                        result = result.concat(findDescendants(n.id, nodes));
                    }
                });
                return result;
            };

            const partnersOf = (list) => list.flatMap(n => allNodes.filter(p => p.pid === n.id && p._partner));

            function createChart(nodes) {
                chart = new OrgChart(treeContainer, {
                    nodes: nodes,
                    layout: OrgChart.normal,
                    scaleInitial: OrgChart.match.boundary,
                    mouseScrool: OrgChart.action.ctrlZoom,
                    keyNavigation: false,
                    filterBy: ['เพศ', 'ราชวงศ์'],
                    enableSearch: false,
                    template: 'ana',
                    editForm: {
                        photoBinding: 'รูปภาพ',
                        generateElementsFromFields: false,
                        buttons: { edit: null, share: null, pdf: null, remove: null },
                        elements: [
                            { type: 'textbox', label: 'พระนาม', binding: 'ชื่อ' },
                            { type: 'textbox', label: 'ตำแหน่ง', binding: 'ตำแหน่ง' },
                            { type: 'textbox', label: 'ราชวงศ์', binding: 'ราชวงศ์' },
                            { type: 'textbox', label: 'เริ่มครองราชย์', binding: 'ครองราชย์' },
                            { type: 'textbox', label: 'สิ้นสุดครองราชย์', binding: 'ครองราชย์สิ้นสุด' },
                            { type: 'textbox', label: 'ประสูติ', binding: 'ประสูติ' },
                            { type: 'textbox', label: 'สวรรคต', binding: 'สวรรคต' },
                            { type: 'textbox', label: 'บิดา', binding: 'บิดา' },
                            { type: 'textbox', label: 'มารดา', binding: 'มารดา' },
                            { type: 'textbox', label: 'คู่สมรส', binding: 'คู่สมรส' },
                            { type: 'textbox', label: 'พระราชบุตร', binding: 'พระราชบุตร' },
                            { type: 'link1', label: '', binding: 'วิกิพีเดีย' },
                            { type: 'link2', label: '', binding: 'url' },
                        ],
                    },
                    toolbar: { layout: false, zoom: true, fit: true, expandAll: false },
                    nodeBinding: { field_0: 'ชื่อ', field_1: 'ตำแหน่ง', img_0: 'รูปภาพ' },
                    tags: {
                        male: { template: 'male' },
                        female: { template: 'female' },
                        kingmale: { template: 'kingmale' },
                        kingfemale: { template: 'kingfemale' },
                        searchedmale: { template: 'searchedmale' },
                        searchedfemale: { template: 'searchedfemale' }
                    },
                });

                chart.on('render-link', function (sender, args) {
                    if (args.cnode.ppid != undefined) {
                        args.html += `<use xlink:href="#heart" x="${args.p.xa}" y="${args.p.ya}"/>`;
                    }
                    if (args.cnode.tags && args.cnode.tags.includes('partner')) {
                        const hasMatchingChild = sender.config.nodes.some(n => n.ppid == args.cnode.id);
                        if (!hasMatchingChild) {
                            args.html += `<use xlink:href="#heart" x="${args.p.xa}" y="${args.p.ya}"/>`;
                        }
                    }
                });

                chart.filterUI.on('add-filter', function (sender, args) {
                    const names = Object.keys(sender.filterBy);
                    if (names.indexOf(args.name) === names.length - 1) {
                        args.html += `<div data-btn-reset style="color:#039BE5;cursor:pointer">รีเซ็ต</div>`;
                    }
                });

                chart.filterUI.on('add-item', function (sender, args) {
                    let count = 0, totalCount = 0;
                    sender.instance.config.nodes.forEach(data => {
                        if (data[args.name] != undefined) {
                            totalCount++;
                            if (data[args.name] == args.value) count++;
                        }
                    });
                    let dataAllAttr = '';
                    if (args.text == '[All]') {
                        count = totalCount;
                        dataAllAttr = 'data-all';
                    }
                    args.html = `<div class="filter-item">
                        <input ${dataAllAttr} type="checkbox" id="${esc(args.value)}" name="${esc(args.value)}" ${args.checked ? 'checked' : ''}>
                        <label for="${esc(args.value)}">${esc(args.text)} (${count})</label>
                    </div>`;
                });

                chart.filterUI.on('update', function (sender) {
                    const btn = sender.element.querySelector('[data-btn-reset]');
                    if (btn) {
                        btn.addEventListener('click', function () {
                            sender.filterBy = null;
                            sender.update();
                            sender.instance.draw();
                        });
                    }
                });
            }

            // ---- Loading ----
            async function loadFamilyData(table) {
                setStatus('กำลังโหลด...');
                searchInput.value = '';
                hideSuggestions();
                document.title = `${tableSelect.options[tableSelect.selectedIndex].text} – แผนผังราชวงศ์`;

                try {
                    const response = await fetch(`fetch_family_data.php?table=${encodeURIComponent(table)}`);
                    const familyData = await response.json();
                    if (!response.ok || !Array.isArray(familyData)) {
                        throw new Error(familyData.error || response.statusText);
                    }

                    familyData.sort((a, b) => {
                        if (a.birth === null && b.birth === null) return a.id - b.id;
                        if (a.birth === null) return 1;
                        if (b.birth === null) return -1;
                        return a.birth - b.birth;
                    });

                    allNodes = familyData.map(m => {
                        const n = {
                            id: m.id,
                            // A few rows reference themselves, which breaks OrgChart's layout.
                            pid: m.parent_id === m.id ? null : m.parent_id,
                            ppid: m.ppid === m.id ? null : m.ppid,
                            ชื่อ: m.name ?? '',
                            ตำแหน่ง: m.relationship ?? '',
                            ครองราชย์: yearText(m.reignstart),
                            ครองราชย์สิ้นสุด: yearText(m.reignend),
                            ประสูติ: yearText(m.birth),
                            สวรรคต: yearText(m.death),
                            ราชวงศ์: m.monarch ?? 'ไม่ปรากฏ',
                            คู่สมรส: m.wife ?? 'ไม่ปรากฏ',
                            พระราชบุตร: m.child ?? 'ไม่ปรากฏ',
                            บิดา: m.father ?? 'ไม่ปรากฏ',
                            มารดา: m.mother ?? 'ไม่ปรากฏ',
                            เพศ: m.gender === 'Female' ? 'หญิง' : 'ชาย',
                            ละติจูด: m.latitude,
                            ลองจิจูด: m.longitude,
                            รูปภาพ: m.img || AVATAR,
                            วิกิพีเดีย: m.urlking ?? 'ไม่ปรากฏ',
                            url: m.url ?? 'ไม่ปรากฏ',
                            _partner: Array.isArray(m.tags) && m.tags.includes('partner'),
                        };
                        n.tags = baseTags(n);
                        return n;
                    });

                    if (chart) {
                        chart.load(allNodes);
                    } else {
                        createChart(allNodes);
                    }
                    setStatus(`${allNodes.length} พระองค์ · คลิกที่กล่องเพื่อดูรายละเอียด · Ctrl + ลูกกลิ้งเมาส์เพื่อซูม`);
                } catch (error) {
                    console.error('Error fetching data:', error);
                    setStatus(`โหลดข้อมูลไม่สำเร็จ: ${error.message}`, true);
                }
            }

            // ---- Search ----
            function handleSearch(selectedName) {
                const term = (selectedName || '').trim().toLowerCase();
                resetTags();
                if (!term) {
                    chart.load(allNodes);
                    return;
                }

                const matchedNode = allNodes.find(n => n.ชื่อ.toLowerCase() === term)
                    || allNodes.find(n => n.ชื่อ.toLowerCase().includes(term));

                if (!matchedNode) {
                    showToast('ไม่พบพระนามที่ค้นหา');
                    chart.load(allNodes);
                    return;
                }

                markSearched(matchedNode);
                let nodesToLoad;

                if (matchedNode.ชื่อ.includes('ครั้งที่')) {
                    // Rulers who reigned more than once: show two generations up and all descendants.
                    const parent = allNodes.find(n => n.id === matchedNode.pid);
                    const grandParent = parent ? allNodes.find(n => n.id === parent.pid) : null;
                    const greatGrandParents = grandParent ? allNodes.filter(n => n.id === grandParent.pid) : [];

                    const related = [
                        matchedNode,
                        ...findDescendants(parent?.id, allNodes),
                        ...findDescendants(grandParent?.id, allNodes),
                        ...findDescendants(matchedNode.id, allNodes),
                    ];
                    nodesToLoad = [...related, ...partnersOf(related), ...greatGrandParents, ...partnersOf(greatGrandParents)];
                    if (parent) nodesToLoad.push(parent);
                    if (grandParent) nodesToLoad.push(grandParent);
                } else {
                    const parent = matchedNode.pid != null ? allNodes.find(n => n.id === matchedNode.pid) : null;
                    const root = parent || matchedNode;
                    const descendants = findDescendants(root.id, allNodes);
                    nodesToLoad = [root, ...descendants, ...partnersOf(descendants)];
                }

                // OrgChart requires every pid/ppid to exist in the loaded set; detach orphans.
                const unique = [...new Set(nodesToLoad)];
                const ids = new Set(unique.map(n => n.id));
                const loaded = unique.map(n => {
                    const orphanPid = n.pid != null && !ids.has(n.pid);
                    const orphanPpid = n.ppid != null && !ids.has(n.ppid);
                    if (!orphanPid && !orphanPpid) return n;
                    const copy = { ...n };
                    if (orphanPid) copy.pid = null;
                    if (orphanPpid) copy.ppid = null;
                    return copy;
                });

                chart.load(loaded);
            }

            // ---- Autocomplete ----
            let focusedIndex = -1;

            function hideSuggestions() {
                autoCompleteContainer.innerHTML = '';
                autoCompleteContainer.hidden = true;
                focusedIndex = -1;
            }

            function selectSuggestion(n) {
                searchInput.value = n.ชื่อ;
                hideSuggestions();
                handleSearch(n.ชื่อ);
            }

            function showSuggestions(term) {
                autoCompleteContainer.innerHTML = '';
                autoCompleteContainer.hidden = false;
                focusedIndex = -1;

                const suggestions = allNodes
                    .filter(n => n.ละติจูด !== null && n.ชื่อ.toLowerCase().includes(term.toLowerCase()))
                    .slice(0, 10);

                if (!suggestions.length) {
                    const empty = document.createElement('div');
                    empty.className = 'no-results-item';
                    empty.textContent = 'ไม่พบ';
                    autoCompleteContainer.appendChild(empty);
                    return;
                }

                suggestions.forEach(n => {
                    const item = document.createElement('div');
                    item.className = 'suggestion-item';
                    item.setAttribute('role', 'option');

                    const img = document.createElement('img');
                    img.src = n.รูปภาพ;
                    img.alt = '';
                    img.onerror = function () { this.onerror = null; this.src = AVATAR; };

                    const span = document.createElement('span');
                    span.textContent = n.ชื่อ;

                    item.append(img, span);
                    item.addEventListener('click', () => selectSuggestion(n));
                    item._node = n;
                    autoCompleteContainer.appendChild(item);
                });
            }

            searchInput.addEventListener('input', () => {
                const term = searchInput.value.trim();
                term ? showSuggestions(term) : hideSuggestions();
            });

            searchInput.addEventListener('keydown', (ev) => {
                const items = [...autoCompleteContainer.querySelectorAll('.suggestion-item')];
                if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                    if (!items.length) return;
                    ev.preventDefault();
                    focusedIndex = (focusedIndex + (ev.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    items.forEach((it, i) => it.classList.toggle('focused', i === focusedIndex));
                } else if (ev.key === 'Enter') {
                    ev.preventDefault();
                    const pick = items[focusedIndex] || items[0];
                    if (pick) selectSuggestion(pick._node);
                    else handleSearch(searchInput.value);
                } else if (ev.key === 'Escape') {
                    hideSuggestions();
                }
            });

            document.addEventListener('click', (ev) => {
                if (!ev.target.closest('.autocomplete-wrapper')) hideSuggestions();
            });

            resetButton.addEventListener('click', () => {
                searchInput.value = '';
                hideSuggestions();
                const url = new URL(window.location.href);
                url.searchParams.delete('search');
                window.history.replaceState({}, document.title, url);
                handleSearch('');
            });

            tableSelect.addEventListener('change', async function () {
                const url = new URL(window.location.href);
                url.search = `?table=${encodeURIComponent(this.value)}`;
                window.history.replaceState({}, document.title, url);
                await loadFamilyData(this.value);
            });

            // ---- Initial load (table is pre-selected server-side from ?table=) ----
            (async () => {
                await loadFamilyData(tableSelect.value);
                const searchName = new URLSearchParams(window.location.search).get('search');
                if (searchName && chart) {
                    searchInput.value = searchName;
                    handleSearch(searchName);
                }
            })();
        });
    </script>
</body>

</html>
