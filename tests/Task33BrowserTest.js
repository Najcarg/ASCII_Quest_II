"use strict";

// Dependency-free local Chromium/CDP check. No application server, session or DB.
// Usage: node tests/Task33BrowserTest.js OUTPUT_DIR [--before] [--root=/path/to/base-checkout]
// Record exact-base geometry with --record-baseline --root=BASE, then run normally
// in the same OUTPUT_DIR to compare desktop frames and responsive placement.
const { spawn, execFileSync } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const assert = require("node:assert/strict");
const output = path.resolve(process.argv[2] || path.join(os.tmpdir(), "ascii-quest-task33"));
const before = process.argv.includes("--before");
const recordBaseline = process.argv.includes("--record-baseline");
const rootOption = process.argv.find((argument) => argument.startsWith("--root="));
const root = rootOption ? path.resolve(rootOption.slice(7)) : path.resolve(__dirname, "..");
fs.mkdirSync(output, { recursive: true });
const html = path.join(output, before ? "before.html" : "after.html");
fs.writeFileSync(html, execFileSync("php", [path.join(__dirname, "fixtures/task33_game.php"), root]));
const profile = fs.mkdtempSync(path.join(os.tmpdir(), "task33-chromium-"));
const browser = spawn(process.env.CHROMIUM || "chromium", ["--headless", "--no-sandbox", "--disable-gpu", "--allow-file-access-from-files", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"], { stdio: ["ignore", "ignore", "pipe"] });
let socket;
const pending = new Map();
let nextId = 0;
const applicationRequests = [];
function command(method, params = {}) {
    const id = ++nextId;
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
}
async function evaluate(expression) {
    const response = await command("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
    if (response.exceptionDetails) throw new Error(JSON.stringify(response.exceptionDetails));
    return response.result.value;
}
async function screenshot(name) {
    const metrics = await command("Page.getLayoutMetrics");
    const shot = await command("Page.captureScreenshot", { captureBeyondViewport: true, clip: { x: 0, y: 0, width: metrics.cssContentSize.width, height: metrics.cssContentSize.height, scale: 1 } });
    fs.writeFileSync(path.join(output, name), Buffer.from(shot.data, "base64"));
}
(async () => {
    const endpoint = await new Promise((resolve, reject) => {
        let stderr = "";
        browser.stderr.on("data", (chunk) => {
            stderr += chunk;
            const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);
            if (match) resolve(match[1]);
        });
        browser.on("error", reject);
        browser.on("exit", (code) => reject(new Error(`Chromium exited ${code}`)));
    });
    const port = new URL(endpoint).port;
    const targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
    socket = new WebSocket(targets.find((target) => target.type === "page").webSocketDebuggerUrl);
    await new Promise((resolve) => { socket.onopen = resolve; });
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.method === 'Network.requestWillBeSent' && /(?:inventory_state|equip_item|unequip_item)\.php/.test(message.params.request.url)) applicationRequests.push(message.params.request.url);
        const request = pending.get(message.id);
        if (!request) return;
        pending.delete(message.id); clearTimeout(request.timer);
        if (message.error) request.reject(new Error(JSON.stringify(message.error)));
        else request.resolve(message.result);
    };
    await command("Page.enable");
    await command("Network.enable");
    await command("DOM.enable");
    await command("CSS.enable");
    // Target-like landscape desktop; screenshot uses this exact CSS viewport.
    await command("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await command("Page.navigate", { url: `file://${html}` });
    for (let i = 0; i < 100; i++) {
        if (await evaluate("!!window.ASCIIQuestInventoryController && document.querySelectorAll('.map-cell').length > 0")) break;
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
    await evaluate("document.querySelector('[data-inventory-slot]').click(); document.fonts.ready.then(() => true)");
    await screenshot(before || recordBaseline ? "before-desktop.png" : "after-desktop.png");
    if (before) {
        const metrics = await evaluate(`(() => { const r=document.querySelector('.hud-bottom-panel').getBoundingClientRect(); return {infoWidth:r.width,infoHeight:r.height}; })()`);
        fs.writeFileSync(path.join(output, 'before-results.json'), JSON.stringify(metrics, null, 2));
        console.log(`Before screenshot: ${output}/before-desktop.png; ${JSON.stringify(metrics)}`);
        return;
    }
    const results = [];
    const geometry = {};
    const baselinePath = path.join(output, 'baseline-geometry.json');
    const baseline = !recordBaseline && fs.existsSync(baselinePath)
        ? JSON.parse(fs.readFileSync(baselinePath, 'utf8')) : null;
    function checkGeometry(name, layout, desktop) {
        geometry[name] = layout;
        if (baseline) {
            const previous = baseline[name];
            assert.ok(previous, `baseline exists: ${name}`);
            // Task33.3 is inner-only: every outer rectangle must equal Task33.2.
            if (!desktop && /right-(skills|passives)$/.test(name)) {
                // Stacked Details now contains the requested short placeholder,
                // not the formerly retained multi-line item. Its natural height
                // (and the spanning left/row bottom) can shrink, never the map.
                for (const key of Object.keys(layout)) {
                    if (['left', 'row', 'details'].includes(key)) {
                        for (const edge of ['x','y','width','right']) assert.equal(layout[key][edge], previous[key][edge], `${name}: unchanged ${key}.${edge}`);
                        assert.ok(layout[key].height <= previous[key].height, `${name}: no inflated stacked height`);
                    } else assert.deepEqual(layout[key], previous[key], `${name}: unchanged ${key}`);
                }
            } else assert.deepEqual(layout, previous, `${name}: all outer geometry unchanged against exact base`);
        }
    }
    const measureLayout = () => evaluate(`(() => {
        const rect = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}; };
        const offset = (panel, child) => document.querySelector(child).getBoundingClientRect().y - document.querySelector(panel).getBoundingClientRect().y;
        return { left:rect('.hud-left-panel'), row:rect('.hud-lower-row'), info:rect('.hud-bottom-panel'), details:rect('.item-details-panel'), map:rect('.map-area'), mapGrid:rect('.game-map, .battle-scene'), mapCell:rect('.map-cell, .battle-combatant'), items:rect('.hud-right-panel'), contentOffsets:{info:offset('.hud-bottom-panel', '.hud-bottom-tabs'), details:offset('.item-details-panel', '.item-details-panel h2')}, gap:parseFloat(getComputedStyle(document.querySelector('.game-layout')).rowGap), overflow:document.documentElement.scrollWidth > innerWidth };
    })()`);
    function assertCentrePlacement(layout, beside = true) {
        assert.equal(layout.overflow, false);
        assert.equal(layout.row.x, layout.map.x, 'lowerRow.left == mainMap.left');
        assert.equal(layout.row.right, layout.map.right, 'lowerRow.right == mainMap.right');
        assert.ok(layout.row.right < layout.items.x && layout.row.right < layout.items.right, 'lower row must not extend under Items');
        assert.ok(Math.abs(layout.row.y - layout.map.bottom - layout.gap) < 1, 'lower panels immediately follow map (one grid gap)');
        assert.equal(layout.items.y, layout.map.y, 'Items stays at map top');
        assert.equal(layout.items.x, layout.map.right + layout.gap, 'Items stays in right sidebar column');
        assert.ok(layout.info.height >= 190, 'preserve enlarged Info area');
        if (beside) {
            assert.ok(layout.info.width > layout.details.width);
            assert.equal(layout.info.y, layout.details.y);
            assert.equal(layout.details.right, layout.map.right);
            assert.ok(layout.details.x >= layout.info.right, 'Item Details beside Info');
            if (!recordBaseline) {
                for (const key of ['left', 'items', 'info', 'details']) {
                    assert.ok(Math.abs(layout[key].bottom - layout.row.bottom) < 1,
                        `${key}.bottom=${layout[key].bottom} == lowerRow.bottom=${layout.row.bottom} (<1px)`);
                }
                assert.equal(layout.contentOffsets.info, 6, 'Info tabs stay top-aligned');
                assert.equal(layout.contentOffsets.details, 9, 'Details heading stays top-aligned');
            }
        } else {
            assert.ok(layout.details.y >= layout.info.bottom);
        }
    }
    async function assertInnerFill() {
        const fill = await evaluate(`(() => {
            const panel = document.querySelector('.hud-bottom-panel');
            const active = panel.querySelector('[data-tab-panel]:not([hidden])');
            const r = panel.getBoundingClientRect(), a = active.getBoundingClientRect();
            const log = active.querySelector('.game-log'), messages = active.querySelector('.game-log-messages');
            return {tab:active.id, bottom:r.bottom - 6, activeBottom:a.bottom, top:a.top,
                tabsBottom:panel.querySelector('.hud-tabs').getBoundingClientRect().bottom,
                logBottom:log?.getBoundingClientRect().bottom, messagesBottom:messages?.getBoundingClientRect().bottom,
                height:r.height, overflow:active.scrollWidth > active.clientWidth,
                logTop:log?.getBoundingClientRect().top,
                verticalOverflow:active.scrollHeight > active.clientHeight + 1,
                messagesTop:messages?.getBoundingClientRect().top,
                titleBottom:active.querySelector('.game-log-title')?.getBoundingClientRect().bottom,
                placeholderTop:active.querySelector('p')?.getBoundingClientRect().top};
        })()`);
        assert.ok(Math.abs(fill.activeBottom - fill.bottom) < 1, `active inner panel fills remaining height: ${JSON.stringify(fill)}`);
        assert.equal(fill.top, fill.tabsBottom, 'active content begins directly beneath tabs');
        assert.equal(fill.overflow, false, 'no inner horizontal overflow');
        assert.equal(fill.verticalOverflow, false, 'active panel contains its contents');
        if (fill.placeholderTop !== undefined) assert.equal(fill.placeholderTop, fill.top, 'placeholder stays top-aligned');
        if (fill.logBottom !== undefined) {
            assert.ok(Math.abs(fill.logBottom - fill.bottom) < 1, `log fills to panel bottom: ${JSON.stringify(fill)}`);
            assert.ok(Math.abs(fill.messagesBottom - (fill.bottom - 1)) < 1, 'messages fill log interior');
            assert.equal(fill.logTop, fill.top + 4, 'title/log stays at original top offset');
            assert.equal(fill.messagesTop, fill.titleBottom, 'messages begin directly below title');
        }
        if (await evaluate("innerWidth <= 1100")) assert.ok(fill.height < 300, 'no huge tablet/mobile info height');
        return fill;
    }
    async function assertLogOverflow() {
        const previous = await measureLayout();
        const scroll = await evaluate(`(() => {
            const log = document.querySelector('.hud-bottom-panel .game-log-messages'); window.savedLog = log.innerHTML;
            log.replaceChildren(...Array.from({length:160}, (_, i) => { const p=document.createElement('div'); p.className='game-log-entry'; p.textContent='Local overflow message '+i; return p; }));
            log.scrollTop=0;
            return {scroll:log.scrollHeight > log.clientHeight, top:log.firstElementChild.getBoundingClientRect().top-log.getBoundingClientRect().top};
        })()`);
        assert.equal(scroll.scroll, true, 'long log scrolls internally');
        assert.equal(scroll.top, 4, 'messages start at top padding');
        assert.deepEqual(await measureLayout(), previous, 'long log leaves every outer rectangle unchanged');
        await assertInnerFill();
        await evaluate("document.querySelector('.hud-bottom-panel .game-log-messages').innerHTML=window.savedLog; delete window.savedLog");
    }
    async function checkDetailsModes(label) {
        for (const [target, title, placeholder] of [
            ['right-skills', 'Skill Details', 'Select a skill to view its details.'],
            ['right-passives', 'Passive Details', 'Select a passive skill to view its details.'],
            ['right-items', 'Item Details', null],
        ]) {
            await evaluate(`document.querySelector('[data-tab-target="${target}"]').click()`);
            const modeLayout = await measureLayout();
            if (await evaluate('innerWidth > 720')) assertCentrePlacement(modeLayout, await evaluate('innerWidth > 1100'));
            assert.equal(modeLayout.overflow, false);
            checkGeometry(`${label}-${target}`, modeLayout, await evaluate('innerWidth > 1100'));
            if (!recordBaseline) {
                assert.equal(await evaluate("document.querySelectorAll('.item-details-panel').length"), 1);
                assert.equal(await evaluate("document.getElementById('itemDetailsTitle').textContent"), title);
                if (placeholder) {
                    assert.equal(await evaluate("document.getElementById('inventoryDetailsEmpty').textContent"), placeholder);
                    assert.equal(await evaluate("document.getElementById('inventoryDetails').hidden && !document.getElementById('inventoryDetailsEmpty').hidden"), true);
                } else {
                    assert.equal(await evaluate("!document.getElementById('inventoryDetails').hidden && window.ASCIIQuestInventoryController.selectedItemId() === 1"), true);
                }
            }
        }
    }
    const layout = await measureLayout();
    assertCentrePlacement(layout);
    checkGeometry('1440x900', layout, true);
    if (!recordBaseline) results.push({innerFill:await assertInnerFill()});
    const previousMetrics = path.join(output, 'before-results.json');
    if (fs.existsSync(previousMetrics)) {
        const previous = JSON.parse(fs.readFileSync(previousMetrics, 'utf8'));
        // Width intentionally shrinks to the centre column in Task33.1; keep the enlarged height.
        assert.ok(layout.info.height >= previous.infoHeight, JSON.stringify({previous, current:layout.info}));
    }
    results.push({ desktopLayout: layout });
    for (const tab of ["bottom-server", "bottom-chat", "bottom-information"]) {
        assert.equal(await evaluate(`document.querySelector('[data-tab-target="${tab}"]').click(); !document.getElementById('${tab}').hidden && !document.getElementById('inventoryDetails').hidden`), true);
        const tabLayout = await measureLayout();
        assertCentrePlacement(tabLayout);
        checkGeometry(`1440x900-${tab}`, tabLayout, true);
        if (!recordBaseline) results.push({innerFill:await assertInnerFill()});
    }
    results.push('3 Info/Server/Chat tabs retain centre-only placement and visible selected details.');
    await checkDetailsModes('1440x900');
    if (!recordBaseline) {
        // Exercise the public presentation API through the real DOM. No mechanics.
        assert.equal(await evaluate(`(() => {
            const c = window.ASCIIQuestInventoryController;
            const snapshot = JSON.stringify(window.ASCII_QUEST_STATE.inventory);
            document.querySelector('[data-tab-target="right-skills"]').click();
            c.setSkillDetails({name:'<b>Local skill</b>',description:'Display-only example',stat_lines:['Example line']});
            if (document.getElementById('contextDetailName').textContent !== '<b>Local skill</b>' || document.getElementById('contextDetails').querySelector('b')) return false;
            document.querySelector('[data-tab-target="right-passives"]').click();
            c.setPassiveDetails({name:'Local passive',description:'Display-only example'});
            if (document.getElementById('contextDetailName').textContent !== 'Local passive') return false;
            c.setSkillDetails(null); c.setPassiveDetails(null);
            document.querySelector('[data-tab-target="right-items"]').click();
            return !document.getElementById('inventoryDetails').hidden && c.selectedItemId() === 1 && JSON.stringify(window.ASCII_QUEST_STATE.inventory) === snapshot;
        })()`), true);
        for (const [target, name] of [['right-skills','after-skill-desktop.png'],['right-passives','after-passive-desktop.png']]) {
            await evaluate(`document.querySelector('[data-tab-target="${target}"]').click()`);
            await screenshot(name);
        }
        await evaluate("document.querySelector('[data-tab-target=right-items]').click()");
        await assertLogOverflow();
        results.push('Task33.3: one panel, all mode titles/placeholders, retained item selection, text-only skill/passive hooks, no state mutation, bounded long-log scrolling.');
    }
    await evaluate("document.querySelector('.hud-right-panel').style.minHeight = '1400px'");
    const stressLayout = await measureLayout();
    assertCentrePlacement(stressLayout);
    checkGeometry('1440x900-tall-items', stressLayout, true);
    if (!recordBaseline) await assertInnerFill();
    results.push({ stressLayout });
    await evaluate("document.querySelector('.hud-right-panel').style.removeProperty('min-height')");
    results.push('Tall-sidebar stress check: 1400px Items retains immediate map gap and aligned desktop bottoms.');
    // Inspect the actual platform font selected for the Egyptian symbol, not just font-family/check().
    const tree = await command("DOM.getDocument");
    for (const selector of ["[data-inventory-slot] .item-type-icon", "#inventoryDetailName .item-type-icon"]) {
        const { nodeId } = await command("DOM.querySelector", { nodeId: tree.root.nodeId, selector });
        const fonts = await command("CSS.getPlatformFontsForNode", { nodeId });
        assert.ok(fonts.fonts.some((font) => /Egypt/i.test(font.familyName) && font.glyphCount > 0), JSON.stringify(fonts));
        results.push({ selector, platformFonts: fonts.fonts });
    }
    const glyphMetrics = await evaluate(`(() => [...document.querySelectorAll('[data-inventory-slot="1"], #inventoryDetailName')].map(node => {
        const icon = node.querySelector('.item-type-icon'); const r = icon.getBoundingClientRect(); const p = node.getBoundingClientRect();
        return { text:node.textContent, font:getComputedStyle(icon).fontFamily, lineHeight:getComputedStyle(node).lineHeight, iconHeight:r.height, parentHeight:p.height, iconTop:r.top, parentTop:p.top, parentBottom:p.bottom };
    }))()`);
    for (const metric of glyphMetrics) {
        assert.ok(metric.iconTop >= metric.parentTop && metric.iconTop + metric.iconHeight <= metric.parentBottom, JSON.stringify(metric));
    }
    results.push({ glyphMetrics });
    await command("Page.captureScreenshot", { clip: { x: layout.items.x, y: layout.items.y, width: layout.items.width, height: layout.items.height, scale: 2 }, captureBeyondViewport: true }).then((shot) => fs.writeFileSync(path.join(output, "after-inventory-2x.png"), Buffer.from(shot.data, "base64")));
    await command("Page.captureScreenshot", { clip: { x: layout.row.x, y: layout.row.y, width: layout.row.width, height: layout.row.height, scale: 2 }, captureBeyondViewport: true }).then((shot) => fs.writeFileSync(path.join(output, "after-details-2x.png"), Buffer.from(shot.data, "base64")));
    // Rebind the existing controller's fetch boundary only: real click/DOM paths, synthetic responses.
    assert.equal(await evaluate(`(async () => {
        const initial = structuredClone(window.ASCII_QUEST_STATE.inventory);
        let current = structuredClone(initial);
        let calls = [];
        const pause = () => new Promise(resolve => setTimeout(resolve, 0));
        for (const node of document.querySelectorAll('[data-inventory-slot], [data-equipment-slot], #equipmentAction, #inventoryNext, #inventoryPrevious')) node.replaceWith(node.cloneNode(true));
        window.ASCIIQuestInventoryController = window.ASCIIQuestItemHud.createController({ document, initialState:current, uuidFactory:()=>'local-fixture', fetchImplementation:async (url, options) => {
            calls.push(url);
            if (url.includes('?page=')) {
                const page = Number(url.split('?page=')[1]);
                current = structuredClone(initial);
                current.pagination = {page,total_pages:2,has_previous:page>1,has_next:page<2};
                if (page === 2) current.items = [{...initial.items[0], id:99, display_name:'Page Two Mace'}];
                return {ok:true,json:async()=>current};
            }
            if (url === 'equip_item.php') {
                current = {...current,items:current.items.slice(1),equipment:{weapon:{...initial.items[0],equipped:true,equipped_slot:'weapon'}}};
            } else if (url === 'unequip_item.php') {
                current = structuredClone(initial);
            }
            return {ok:true,json:async()=>({state:current})};
        }});
        document.querySelector('[data-inventory-slot]').click();
        document.getElementById('equipmentAction').click(); await pause();
        const slot = document.querySelector('[data-equipment-slot="weapon"]');
        if (slot.querySelectorAll('.item-type-icon').length !== 1 || !slot.textContent.includes('Fine Mace of Accuracy')) return false;
        slot.click();
        const callCount = calls.length;
        for (const target of ['right-skills','right-passives','right-items']) document.querySelector('[data-tab-target="'+target+'"]').click();
        if (calls.length !== callCount || document.getElementById('inventoryDetails').hidden) return false;
        if (document.getElementById('equipmentAction').textContent !== 'Unequip' || document.getElementById('inventoryDetailName').textContent !== slot.textContent) return false;
        document.getElementById('equipmentAction').click(); await pause();
        if (!document.getElementById('inventoryDetails').hidden || document.getElementById('inventoryDetailsEmpty').hidden) return false;
        document.querySelector('[data-inventory-slot]').click();
        document.getElementById('inventoryNext').click(); await pause();
        if (!document.getElementById('inventoryDetails').hidden || document.getElementById('inventoryPageLabel').textContent !== 'Page 2 of 2') return false;
        document.getElementById('inventoryPrevious').click(); await pause();
        document.querySelector('[data-inventory-slot]').click();
        if (window.ASCIIQuestInventoryController.detailsMode) {
            document.querySelector('[data-tab-target="right-passives"]').click();
            window.ASCIIQuestInventoryController.applyState({items:[],equipment:{},pagination:{}});
            if (document.getElementById('inventoryDetailsEmpty').textContent !== 'Select a passive skill to view its details.') return false;
            document.querySelector('[data-tab-target="right-items"]').click();
            if (!document.getElementById('inventoryDetails').hidden || document.getElementById('inventoryDetailsEmpty').textContent !== 'Select an inventory or equipped item.') return false;
            window.ASCIIQuestInventoryController.applyState(initial);
            document.querySelector('[data-inventory-slot]').click();
        }
        return calls.join(',') === 'equip_item.php,unequip_item.php,inventory_state.php?page=2,inventory_state.php?page=1';
    })()`), true);
    results.push('Browser selection, equip/unequip, details reset, next/previous pagination passed (mock fetch; no DB).');
    // Wide desktop, breakpoint boundaries, tablet and narrow mobile.
    for (const width of [1920, 1440, 1280, 1101, 1100, 1099, 1024, 800, 721, 720, 719, 390, 320]) {
        await command("Emulation.setDeviceMetricsOverride", { width, height: 1100, deviceScaleFactor: 1, mobile: width <= 720 });
        const responsive = await evaluate(`(() => { document.querySelector('[data-tab-target="bottom-chat"]').click(); const a=document.querySelector('.hud-bottom-panel').getBoundingClientRect(); const b=document.querySelector('.item-details-panel').getBoundingClientRect(); const input=document.querySelector('#bottom-chat input'); return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,stacked:b.y>=a.bottom, inputWidth:input.getBoundingClientRect().width, availableInputWidth:input.parentElement.clientWidth - 16}; })()`);
        assert.ok(responsive.scrollWidth <= width, JSON.stringify(responsive));
        assert.ok(Math.abs(responsive.inputWidth - responsive.availableInputWidth) < 1, JSON.stringify(responsive));
        await evaluate("document.querySelector('[data-tab-target=bottom-information]').click()");
        assert.equal(responsive.stacked, width <= 1100);
        for (const tab of ['bottom-information', 'bottom-server', 'bottom-chat']) {
            await evaluate(`document.querySelector('[data-tab-target="${tab}"]').click()`);
            checkGeometry(`${width}x1100-${tab}`, await measureLayout(), width > 1100);
            if (!recordBaseline) results.push({width,innerFill:await assertInnerFill()});
        }
        await evaluate("document.querySelector('[data-tab-target=bottom-information]').click()");
        const viewportLayout = await measureLayout();
        if (width > 720) assertCentrePlacement(viewportLayout, width > 1100);
        else {
            const mobile = viewportLayout;
            assert.ok(mobile.row.y >= mobile.items.bottom, 'single-column lower panels follow Items');
            assert.ok(mobile.row.x >= 0 && mobile.row.right <= width, 'mobile lower row fits viewport');
        }
        checkGeometry(`${width}x1100`, viewportLayout, width > 1100);
        results.push({ ...responsive, layout:viewportLayout });
        await checkDetailsModes(`${width}x1100`);
        // Stress every desktop width, including the three-column boundary.
        if (width > 1100) {
            await evaluate("document.querySelector('.hud-right-panel').style.minHeight = '1400px'");
            const tallLayout = await measureLayout();
            assertCentrePlacement(tallLayout);
            checkGeometry(`${width}x1100-tall-items`, tallLayout, true);
            if (!recordBaseline) await assertInnerFill();
            results.push({ width, tallLayout });
            await evaluate("document.querySelector('.hud-right-panel').style.removeProperty('min-height')");
        }
        if (!recordBaseline && [1100,720,390,320].includes(width)) await assertLogOverflow();
        if (width === 390) await screenshot("after-mobile.png");
    }
    // The same fill contract applies to the real combat template branch. The
    // fixture omits combat polling/controllers: layout checks never call a server.
    const combatHtml = path.join(output, 'combat.html');
    fs.writeFileSync(combatHtml, execFileSync('php', [path.join(__dirname, 'fixtures/task33_game.php'), root, '--combat']));
    await command('Page.navigate', {url:`file://${combatHtml}`});
    for (let i=0; i<100; i++) {
        if (await evaluate("!!window.ASCIIQuestInventoryController && !!document.getElementById('combatBattleEvents')")) break;
        await new Promise(resolve => setTimeout(resolve,50));
    }
    await evaluate("document.querySelector('[data-inventory-slot]').click(); document.fonts.ready.then(() => true)");
    for (const [width,height] of [[1440,900],[1101,1100],[1100,1100],[720,1100],[390,1100],[320,1100]]) {
        await command('Emulation.setDeviceMetricsOverride', {width,height,deviceScaleFactor:1,mobile:width<=720});
        for (const tab of ['combat-server-info','combat-chat','combat-battle-info']) {
            await evaluate(`document.querySelector('[data-tab-target="${tab}"]').click()`);
            const combatLayout = await measureLayout();
            assert.equal(combatLayout.overflow,false);
            if (width>720) assertCentrePlacement(combatLayout,width>1100);
            checkGeometry(`combat-${width}x${height}-${tab}`,combatLayout,width>1100);
            if (!recordBaseline) results.push({combat:true,width,innerFill:await assertInnerFill()});
        }
        await checkDetailsModes(`combat-${width}x${height}`);
        if (!recordBaseline) await assertLogOverflow();
        if (width===1440 && !recordBaseline) await screenshot('after-combat-desktop.png');
    }
    results.push('Combat template: 6 viewport layouts, all 3 bottom tabs and 3 details modes; structural fill, top alignment, no overflow.');
    assert.deepEqual(applicationRequests, [], 'no real inventory/equipment API requests');
    results.push('No real application API requests; mode switches are presentation-only.');
    fs.writeFileSync(recordBaseline ? baselinePath : path.join(output, 'geometry.json'), JSON.stringify(geometry, null, 2));
    fs.writeFileSync(path.join(output, recordBaseline ? 'baseline-results.json' : 'browser-results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
    console.log(`Task33/33.1/33.2/33.3 browser: 14 exploration + 6 combat viewport layouts, all 3 bottom tabs and 3 details modes, inner-fill/overflow/hooks, 5 tall-sidebar stress checks, synthetic selection/equipment/pagination, 2 platform-font and 2 glyph-fit checks passed (no DB). ${recordBaseline ? 'Exact-base geometry recorded.' : baseline ? 'Exact-base desktop frames and responsive placement match; stacked context heights naturally shrink.' : 'No baseline geometry supplied.'}`);
})().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => {
    socket?.close(); browser.kill();
    // Chromium children can finish writing the profile just after the parent exits.
    browser.on("exit", () => fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 }));
});
