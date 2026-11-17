"use strict";

// Dependency-free local Chromium/CDP check. No application server, session or DB.
// Usage: node tests/Task33BrowserTest.js OUTPUT_DIR [--before] [--root=/path/to/base-checkout]
const { spawn, execFileSync } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const assert = require("node:assert/strict");
const output = path.resolve(process.argv[2] || path.join(os.tmpdir(), "ascii-quest-task33"));
const before = process.argv.includes("--before");
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
        const request = pending.get(message.id);
        if (!request) return;
        pending.delete(message.id); clearTimeout(request.timer);
        if (message.error) request.reject(new Error(JSON.stringify(message.error)));
        else request.resolve(message.result);
    };
    await command("Page.enable");
    await command("DOM.enable");
    await command("CSS.enable");
    await command("Emulation.setDeviceMetricsOverride", { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await command("Page.navigate", { url: `file://${html}` });
    for (let i = 0; i < 100; i++) {
        if (await evaluate("!!window.ASCIIQuestInventoryController && document.querySelectorAll('.map-cell').length > 0")) break;
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
    await evaluate("document.querySelector('[data-inventory-slot]').click(); document.fonts.ready.then(() => true)");
    await screenshot(before ? "before-desktop.png" : "after-desktop.png");
    if (before) {
        const metrics = await evaluate(`(() => { const r=document.querySelector('.hud-bottom-panel').getBoundingClientRect(); return {infoWidth:r.width,infoHeight:r.height}; })()`);
        fs.writeFileSync(path.join(output, 'before-results.json'), JSON.stringify(metrics, null, 2));
        console.log(`Before screenshot: ${output}/before-desktop.png; ${JSON.stringify(metrics)}`);
        return;
    }
    const results = [];
    const layout = await evaluate(`(() => {
        const rect = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right}; };
        return { row:rect('.hud-lower-row'), info:rect('.hud-bottom-panel'), details:rect('.item-details-panel'), map:rect('.map-area'), items:rect('.hud-right-panel'), overflow:document.documentElement.scrollWidth > innerWidth };
    })()`);
    assert.equal(layout.overflow, false);
    assert.equal(layout.row.x, layout.map.x);
    assert.equal(layout.row.right, layout.items.right);
    assert.ok(layout.info.width > layout.details.width);
    assert.equal(layout.info.y, layout.details.y);
    assert.ok(layout.info.height >= 175);
    const previousMetrics = path.join(output, 'before-results.json');
    if (fs.existsSync(previousMetrics)) {
        const previous = JSON.parse(fs.readFileSync(previousMetrics, 'utf8'));
        assert.ok(layout.info.width > previous.infoWidth && layout.info.height > previous.infoHeight, JSON.stringify({previous, current:layout.info}));
    }
    results.push({ desktopLayout: layout });
    for (const tab of ["bottom-server", "bottom-chat", "bottom-information"]) {
        assert.equal(await evaluate(`document.querySelector('[data-tab-target="${tab}"]').click(); !document.getElementById('${tab}').hidden && !document.getElementById('inventoryDetails').hidden`), true);
    }
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
        window.ASCIIQuestItemHud.createController({ document, initialState:current, uuidFactory:()=>'local-fixture', fetchImplementation:async (url, options) => {
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
        if (document.getElementById('equipmentAction').textContent !== 'Unequip' || document.getElementById('inventoryDetailName').textContent !== slot.textContent) return false;
        document.getElementById('equipmentAction').click(); await pause();
        if (!document.getElementById('inventoryDetails').hidden || document.getElementById('inventoryDetailsEmpty').hidden) return false;
        document.querySelector('[data-inventory-slot]').click();
        document.getElementById('inventoryNext').click(); await pause();
        if (!document.getElementById('inventoryDetails').hidden || document.getElementById('inventoryPageLabel').textContent !== 'Page 2 of 2') return false;
        document.getElementById('inventoryPrevious').click(); await pause();
        document.querySelector('[data-inventory-slot]').click();
        return calls.join(',') === 'equip_item.php,unequip_item.php,inventory_state.php?page=2,inventory_state.php?page=1';
    })()`), true);
    results.push('Browser selection, equip/unequip, details reset, next/previous pagination passed (mock fetch; no DB).');
    for (const width of [1024, 800, 720, 390, 320]) {
        await command("Emulation.setDeviceMetricsOverride", { width, height: 1100, deviceScaleFactor: 1, mobile: width <= 720 });
        const responsive = await evaluate(`(() => { document.querySelector('[data-tab-target="bottom-chat"]').click(); const a=document.querySelector('.hud-bottom-panel').getBoundingClientRect(); const b=document.querySelector('.item-details-panel').getBoundingClientRect(); const input=document.querySelector('#bottom-chat input'); return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,stacked:b.y>=a.bottom, inputWidth:input.getBoundingClientRect().width, availableInputWidth:input.parentElement.clientWidth - 16}; })()`);
        assert.ok(responsive.scrollWidth <= width, JSON.stringify(responsive));
        assert.ok(Math.abs(responsive.inputWidth - responsive.availableInputWidth) < 1, JSON.stringify(responsive));
        await evaluate("document.querySelector('[data-tab-target=bottom-information]').click()");
        if (width <= 1024) assert.equal(responsive.stacked, true);
        results.push(responsive);
        if (width === 390) await screenshot("after-mobile.png");
    }
    fs.writeFileSync(path.join(output, "browser-results.json"), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
    console.log("Task33 browser: desktop alignment, tabs, real mace fallback in inventory/details, 5 responsive widths passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => {
    socket?.close(); browser.kill();
    browser.on("exit", () => fs.rmSync(profile, { recursive: true, force: true }));
});
