"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const hud = require("../ascii-quest/js/item_hud.js");
function element() {
    return {
        children: [], attributes: {}, dataset: {}, classList: { remove() {}, add() {} },
        setAttribute(key, value) { this.attributes[key] = value; },
        replaceChildren(...nodes) { this.children = nodes; this.value = ""; },
        get textContent() { return this.children.length ? this.children.map((child) => child.textContent ?? child).join("") : (this.value || ""); },
        set textContent(value) { this.children = []; this.value = value; },
    };
}
function fixture() {
    const cell = element(), slot = element(); slot.dataset.equipmentSlot = "weapon";
    const ids = Object.fromEntries(["inventoryDetails", "inventoryDetailName", "inventoryDetailMeta", "inventoryDetailStats"].map((id) => [id, element()]));
    const document = {
        createElement: element, getElementById: (id) => ids[id] || null,
        querySelectorAll: (selector) => selector === "[data-inventory-slot]" ? [cell] : selector === "[data-equipment-slot]" ? [slot] : [],
    };
    return { document, cell, slot, ids };
}
const tests = {};
for (const [type, icon] of Object.entries({ sword:"⯝", dagger:"†", mace:"𓉒", wand:"‡", shield:"⛨", gloves:"☞", boots:"⛛", belt:"▬", ring:"◎", amulet:"◈", charm:"❖" })) {
    tests[`${type} maps to ${icon} from authoritative base_type in all three views`] = () => {
        const { document, cell, slot, ids } = fixture();
        const item = Object.freeze({ id:1, base_type:type, display_name:"Keen Relic of Accuracy", glyph:"old", rarity:"magic", stat_lines:["+5 Accuracy"], affixes:[{ display_fragment:"of Accuracy" }] });
        const snapshot = JSON.stringify(item);
        hud.render(document, { items:[item], equipment:{weapon:item} });
        hud.renderDetails(document, item);
        for (const node of [cell, slot, ids.inventoryDetailName]) {
            assert.equal(node.textContent, `${icon} Keen Relic of Accuracy`);
            assert.equal(node.children.filter((child) => child.className === "item-type-icon").length, 1);
            assert.equal(node.children[0].attributes["aria-hidden"], "true");
            assert.equal(node.children[1].textContent, item.display_name);
        }
        hud.render(document, { items:[item], equipment:{weapon:item} });
        hud.renderDetails(document, item);
        assert.equal(cell.textContent, `${icon} Keen Relic of Accuracy`);
        assert.equal(ids.inventoryDetailStats.textContent, "No base stat bonus · +5 Accuracy");
        assert.equal(JSON.stringify(item), snapshot);
    };
}
tests["unmapped types retain legacy inventory/equipment glyph and plain details, never infer name"] = () => {
    for (const type of ["axe", "staff", "helm", "chest", "unknown", "toString", "constructor", ""]) {
        const { document, cell, slot, ids } = fixture();
        const item = { id:1, base_type:type, display_name:"Sword Dagger Mace of Accuracy", glyph:"/" };
        hud.render(document, { items:[item], equipment:{weapon:item} }); hud.renderDetails(document, item);
        assert.equal(cell.textContent, "/ Sword Dagger Mace of Accuracy");
        assert.equal(slot.textContent, cell.textContent);
        assert.equal(ids.inventoryDetailName.textContent, item.display_name);
    }
};
tests["markup-like names stay selectable text and authoritative names already containing symbols are untouched"] = () => {
    const { document, cell } = fixture();
    const item = { base_type:"sword", display_name:"<img src=x> ⯝ Accurate", glyph:"/" };
    hud.renderItemName(document, cell, item);
    assert.equal(cell.children[1].textContent, item.display_name);
    assert.equal(cell.children[1].className, "item-display-name");
    // A literal symbol in a stored name is data, not a presentation prefix to strip.
    assert.equal(item.display_name, "<img src=x> ⯝ Accurate");
};
tests["details live once in the shared lower row, not under inventory pagination"] = () => {
    const markup = fs.readFileSync(require.resolve("../ascii-quest/game.php"), "utf8");
    assert.equal((markup.match(/id="inventoryDetails"/g) || []).length, 1);
    const row = markup.indexOf('class="hud-lower-row"');
    assert.ok(row > markup.indexOf('id="inventoryNext"'));
    assert.ok(markup.indexOf('id="inventoryDetails"') > row);
    assert.ok(markup.indexOf('id="bottom-information"') > row);
    assert.ok(markup.includes('class="item-details-panel"'));
};
let passed = 0, failed = 0;
for (const [name, test] of Object.entries(tests)) {
    try { test(); passed++; console.log(`[PASS] ${name}`); }
    catch (error) { failed++; console.error(`[FAIL] ${name}: ${error.message}`); }
}
console.log(`\n${passed} passed\n${failed} failed`);
process.exitCode = failed ? 1 : 0;
