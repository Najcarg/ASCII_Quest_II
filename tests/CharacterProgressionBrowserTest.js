"use strict";
// Dependency-free local Chromium/CDP. Real game template, server-computed fake
// repository scenarios, no HTTP application/session/DB/migration/XP writes.
const { spawn, execFileSync } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const assert = require("node:assert/strict");
const root = path.resolve(__dirname, "..");
const output = path.resolve(process.argv[2] || path.join(os.tmpdir(), "ascii-quest-task34a-browser"));
fs.mkdirSync(output, { recursive: true });
const html = path.join(output, "progression.html");
fs.writeFileSync(html, execFileSync("php", [path.join(__dirname, "fixtures/task33_game.php"), root, "--combat"]));
const states = JSON.parse(execFileSync("php", [path.join(__dirname, "fixtures/task34a_progression.php")], { encoding: "utf8" }));
const profile = fs.mkdtempSync(path.join(os.tmpdir(), "task34a-chromium-"));
const browser = spawn(process.env.CHROMIUM || "chromium", ["--headless", "--no-sandbox", "--disable-gpu", "--allow-file-access-from-files", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"], { stdio: ["ignore", "ignore", "pipe"] });
let socket;
let nextId = 0;
const pending = new Map();
const applicationRequests = [];
function command(method, params = {}) {
    const id = ++nextId;
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { pending.delete(id); reject(new Error("CDP timeout " + method)); }, 15000);
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
    const shot = await command("Page.captureScreenshot", { captureBeyondViewport: false });
    fs.writeFileSync(path.join(output, name), Buffer.from(shot.data, "base64"));
}
(async () => {
    const endpoint = await new Promise((resolve, reject) => {
        let stderr = "";
        browser.stderr.on("data", chunk => {
            stderr += chunk;
            const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);
            if (match) resolve(match[1]);
        });
        browser.on("error", reject);
        browser.on("exit", code => reject(new Error("Chromium exited " + code)));
    });
    const targets = await (await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json`)).json();
    socket = new WebSocket(targets.find(target => target.type === "page").webSocketDebuggerUrl);
    await new Promise(resolve => { socket.onopen = resolve; });
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.method === "Network.requestWillBeSent" && /\.php(?:\?|$)/.test(message.params.request.url)) applicationRequests.push(message.params.request.url);
        const request = pending.get(message.id);
        if (!request) return;
        pending.delete(message.id); clearTimeout(request.timer);
        if (message.error) request.reject(new Error(JSON.stringify(message.error)));
        else request.resolve(message.result);
    };
    await command("Page.enable"); await command("Network.enable");
    await command("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await command("Page.navigate", { url: `file://${html}` });
    for (let i = 0; i < 100; i++) {
        if (await evaluate("!!window.ASCIIQuestHud && !!window.ASCIIQuestInventoryController")) break;
        await new Promise(resolve => setTimeout(resolve, 50));
    }
    // Suppress automatic combat polling; load only the presentation API.
    await evaluate(`window.ASCII_QUEST_STATE.mode = 'fixture'; new Promise((resolve,reject)=>{const s=document.createElement('script');s.src=${JSON.stringify(`file://${root}/ascii-quest/js/combat_hud.js`)};s.onload=resolve;s.onerror=reject;document.head.append(s);})`);
    await evaluate(`window.task34aStates=${JSON.stringify(states)}`);
    const results = [];
    for (const width of [1440, 1100, 720, 390, 320]) {
        await command("Emulation.setDeviceMetricsOverride", { width, height: 900, deviceScaleFactor: 1, mobile: width <= 720 });
        for (const [name, level, label, percent, feedback] of [
            ["xp_0", 1, "0/100 XP", 0, ""], ["xp_99", 1, "99/100 XP", 99, ""],
            ["xp_100", 2, "0/300 XP", 0, ""], ["single", 2, "20/300 XP", 6, "Level Up! 1 → 2"],
            ["multi", 5, "100/900 XP", 11, "Level Up! 1 → 5"],
            ["cap", 50, "MAX", 100, "Level Up! 49 → 50"], ["xp_999999", 50, "MAX", 100, ""],
        ]) {
            const result = await evaluate(`(() => {
                const state=task34aStates[${JSON.stringify(name)}];
                ASCIIQuestCombatHud.renderCombatHud(document,state,{},0);
                const bar=document.getElementById('playerXpBar');
                return {level:document.getElementById('playerLevel').textContent,xp:document.getElementById('playerXp').textContent,
                    percent:bar.getAttribute('aria-valuenow'),total:bar.dataset.currentXp,
                    feedback:document.getElementById('combatVictoryLevelUp').textContent,
                    feedbackHidden:document.getElementById('combatVictoryLevelUp').hidden,
                    gained:document.getElementById('combatVictoryExperience').textContent,
                    overflow:document.documentElement.scrollWidth>innerWidth};
            })()`);
            assert.equal(result.level, String(level)); assert.equal(result.xp, label);
            assert.equal(result.percent, String(percent)); assert.equal(result.feedback, feedback);
            assert.equal(result.feedbackHidden, feedback === ""); assert.equal(result.overflow, false);
            assert.equal(result.total, String(states[name].champion.progression.total_xp));
            // Existing resource bars transition for 160ms; validate settled pixels,
            // not just ARIA, and never capture a misleading mid-transition frame.
            await new Promise(resolve => setTimeout(resolve, 190));
            const renderedPercent = await evaluate("document.getElementById('playerXpFill').getBoundingClientRect().width / document.getElementById('playerXpBar').clientWidth * 100");
            assert.ok(Math.abs(renderedPercent - percent) < 1, `settled fill ${renderedPercent} vs ${percent}`);
            result.renderedPercent = renderedPercent;
            if (states[name].loot_phase) assert.equal(result.gained, String(states[name].loot_phase.rewards.experience));
            results.push({ width, scenario: name, ...result });
            if (["xp_99", "multi", "cap"].includes(name) && [1440, 390].includes(width)) {
                await evaluate("document.getElementById('playerXp').scrollIntoView({block:'center'})");
                await screenshot(`${width}-${name}.png`);
            }
        }
    }
    assert.equal(applicationRequests.length, 0, "No application requests or XP mutations");
    fs.writeFileSync(path.join(output, "results.json"), JSON.stringify(results, null, 2));
    console.log(`35 progression scenarios across 5 viewport widths passed. No application requests. Evidence: ${output}`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
    if (socket) socket.close();
    for (const request of pending.values()) clearTimeout(request.timer);
    // Chromium writes its profile while exiting; wait before deleting our temp directory.
    if (browser.exitCode === null && browser.signalCode === null) {
        await new Promise(resolve => { browser.once("exit", resolve); browser.kill(); });
    }
    fs.rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
});
