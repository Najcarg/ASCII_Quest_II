"use strict";
const assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const path = require("node:path");
const hud = require("../ascii-quest/js/exploration_hud.js");
const combat = require("../ascii-quest/js/combat_hud.js");
const states = JSON.parse(execFileSync("php", [path.join(__dirname, "fixtures/task34a_progression.php")], { encoding: "utf8" }));
function fixture() {
    const nodes = Object.fromEntries(["playerLevel", "playerXp", "playerXpBar", "playerXpFill", "combatVictoryLevelUp", "combatVictoryExperience"].map(id => [id, {
        textContent: "", hidden: false, style: {}, dataset: {}, attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
    }]));
    return { nodes, document: { getElementById: id => nodes[id] || null } };
}
const tests = {
    "server thresholds and cumulative progress display at exact boundaries": () => {
        const f = fixture();
        for (const [key, level, label, percent] of [["xp_0", 1, "0/100 XP", 0], ["xp_99", 1, "99/100 XP", 99], ["xp_100", 2, "0/300 XP", 0], ["xp_120", 2, "20/300 XP", 6], ["xp_399", 2, "299/300 XP", 99], ["xp_400", 3, "0/500 XP", 0]]) {
            hud.renderProgression(f.document, states[key].champion.progression);
            assert.equal(f.nodes.playerLevel.textContent, String(level));
            assert.equal(f.nodes.playerXp.textContent, label);
            assert.equal(f.nodes.playerXpFill.style.width, percent + "%");
            assert.equal(f.nodes.playerXpBar.attributes["aria-valuenow"], String(percent));
        }
    },
    "MAX preserves cumulative XP and no next requirement is calculated client-side": () => {
        const f = fixture();
        hud.renderProgression(f.document, states.xp_999999.champion.progression);
        assert.equal(f.nodes.playerXp.textContent, "MAX");
        assert.equal(f.nodes.playerLevel.textContent, "100");
        assert.equal(f.nodes.playerXpBar.dataset.currentXp, "999999");
        assert.equal(f.nodes.playerXpBar.attributes["aria-valuetext"], "999999 total XP; MAX");
        assert.equal(f.nodes.playerXpFill.style.width, "100%");
    },
    "combat presentation retains immutable single multi cap reward feedback": () => {
        for (const [key, oldLevel, newLevel] of [["single", 1, 2], ["multi", 1, 5], ["cap", 49, 50]]) {
            const view = combat.buildPresentation(states[key], 0);
            assert.equal(view.champion.progression.level, newLevel);
            assert.equal(view.lootPhase.rewards.progression.old_level, oldLevel);
            assert.equal(view.lootPhase.rewards.progression.new_level, newLevel);
        }
    },
    "missing legacy projection does not fabricate XP or level": () => {
        const f = fixture(); f.nodes.playerLevel.textContent = "unchanged";
        hud.renderProgression(f.document, null);
        assert.equal(f.nodes.playerLevel.textContent, "unchanged");
        assert.equal(combat.buildPresentation({ champion: { current_hp: 1 } }, 0).champion.progression, null);
    },
};
let passed = 0;
for (const [name, test] of Object.entries(tests)) { test(); console.log("[PASS] " + name); passed++; }
console.log(`\n${passed} passed\n0 failed`);
