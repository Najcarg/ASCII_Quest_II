"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const modulePath = path.join(__dirname, "..", "ascii-quest", "js", "item_hud.js");
const itemHud = fs.existsSync(modulePath) ? require(modulePath) : {};
const gameMarkup = fs.readFileSync(path.join(__dirname, "..", "ascii-quest", "game.php"), "utf8");

function classList() {
    const values = new Set();
    return {
        add(...names) { names.forEach((name) => values.add(name)); },
        remove(...names) { names.forEach((name) => values.delete(name)); },
        contains(name) { return values.has(name); },
        values,
    };
}

function element() {
    const listeners = {};
    return {
        textContent: "",
        hidden: false,
        disabled: false,
        dataset: {},
        classList: classList(),
        attributes: {},
        style: {},
        setAttribute(name, value) { this.attributes[name] = String(value); },
        addEventListener(name, callback) { listeners[name] = callback; },
        click() { return listeners.click?.({ currentTarget: this }); },
    };
}

function documentFixture() {
    const cells = Array.from({ length: 25 }, element);
    cells.forEach((cell, index) => { cell.dataset.inventorySlot = String(index + 1); });
    const equipmentSlots = ["helm", "gloves", "chest", "ring", "weapon", "off-hand", "amulet", "belt", "charm", "boots"].map((slot) => {
        const node = element(); node.dataset.equipmentSlot = slot; return node;
    });
    const elements = {
        inventoryEmpty: element(),
        inventoryMessage: element(),
        inventoryPageLabel: element(),
        inventoryPrevious: element(),
        inventoryNext: element(),
        inventoryDetails: element(),
        inventoryDetailName: element(),
        inventoryDetailMeta: element(),
        inventoryDetailStats: element(),
        equipmentAction: element(),
        equipmentLockReason: element(),
        playerHp: element(), playerHpBar: element(), playerHpFill: element(),
        playerMana: element(), playerManaBar: element(), playerManaFill: element(),
    };
    return {
        cells,
        elements,
        querySelectorAll(selector) {
            if (selector === "[data-inventory-slot]") return cells;
            if (selector === "[data-equipment-slot]") return equipmentSlots;
            return [];
        },
        getElementById(id) { return elements[id] || null; },
        equipmentSlots,
    };
}

function item(id, name = `Sword ${id}`, rarity = "normal") {
    return {
        id,
        display_name: name,
        rarity,
        item_level: 1,
        definition_key: "basic_sword",
        base_type: "sword",
        category: "weapon",
        equipment_slot: "weapon",
        glyph: "/",
        base_stats: {
            damage_type: "physical", damage_min: 4, damage_max: 7,
            toughness: 0, attack_rate_modifier_bp: 0,
            cast_rate_modifier_bp: 0, block_rate_modifier_bp: 0,
        },
        equipped: false,
        equipped_slot: null,
    };
}

function state(items, page = 1, totalPages = 1) {
    const equipment = Object.fromEntries(["helm", "gloves", "chest", "ring", "weapon", "off-hand", "amulet", "belt", "charm", "boots"].map((slot) => [slot, null]));
    return {
        items,
        equipment,
        pagination: {
            page, per_page: 25, total_items: items.length + (page - 1) * 25,
            total_pages: totalPages, has_previous: page > 1, has_next: page < totalPages,
        },
        equipment_locked: false,
        mutation_disabled_reason: null,
    };
}

const tests = {
    "empty inventory renders a safe empty state"() {
        const document = documentFixture();
        itemHud.render(document, state([]));
        assert.equal(document.elements.inventoryEmpty.hidden, false);
        assert.equal(document.elements.inventoryEmpty.textContent, "Inventory is empty.");
        assert.equal(document.cells.every((cell) => cell.textContent === ""), true);
    },

    "one item renders in server order and uses textContent"() {
        const document = documentFixture();
        itemHud.render(document, state([item(1, "<img src=x onerror=alert(1)>")]));
        assert.equal(document.cells[0].textContent, "/ <img src=x onerror=alert(1)>");
        assert.equal(document.cells[0].dataset.itemId, "1");
        assert.equal(document.cells[0].classList.contains("inventory-rarity-normal"), true);
        assert.equal(document.cells[1].textContent, "");
    },

    "25 items fill exactly one visual page"() {
        const document = documentFixture();
        itemHud.render(document, state(Array.from({ length: 25 }, (_, index) => item(index + 1))));
        assert.equal(document.cells.filter((cell) => cell.dataset.itemId).length, 25);
        assert.equal(document.elements.inventoryNext.disabled, true);
    },

    "26 items expose a next page without creating extra cells"() {
        const document = documentFixture();
        itemHud.render(document, state(Array.from({ length: 25 }, (_, index) => item(26 - index)), 1, 2));
        assert.equal(document.cells.length, 25);
        assert.equal(document.elements.inventoryNext.disabled, false);
        assert.equal(document.elements.inventoryPageLabel.textContent, "Page 1 of 2");
    },

    "pointer selection renders selected item details"() {
        const document = documentFixture();
        const controller = itemHud.createController({ document, initialState: state([item(7, "Basic Sword")]) });
        document.cells[0].click();
        assert.equal(document.elements.inventoryDetails.hidden, false);
        assert.equal(document.elements.inventoryDetailName.textContent, "Basic Sword");
        assert.equal(document.elements.inventoryDetailMeta.textContent, "Normal · Item level 1 · Sword");
        assert.equal(document.elements.inventoryDetailStats.textContent, "Physical damage 4–7");
        assert.equal(controller.selectedItemId(), 7);
    },

    async "next page requests and renders authoritative state"() {
        const document = documentFixture();
        const requests = [];
        itemHud.createController({
            document,
            initialState: state([item(26)], 1, 2),
            fetchImplementation: async (url, options) => {
                requests.push({ url, options });
                return { ok: true, json: async () => state([item(1)], 2, 2) };
            },
        });
        await document.elements.inventoryNext.click();
        assert.equal(requests[0].url, "inventory_state.php?page=2");
        assert.equal(requests[0].options.method, "GET");
        assert.equal(document.cells[0].dataset.itemId, "1");
        assert.equal(document.elements.inventoryPrevious.disabled, false);
    },

    async "previous page requests the prior authoritative page"() {
        const document = documentFixture();
        let requested = "";
        itemHud.createController({
            document,
            initialState: state([item(1)], 2, 2),
            fetchImplementation: async (url) => {
                requested = url;
                return { ok: true, json: async () => state([item(26)], 1, 2) };
            },
        });
        await document.elements.inventoryPrevious.click();
        assert.equal(requested, "inventory_state.php?page=1");
        assert.equal(document.elements.inventoryPageLabel.textContent, "Page 1 of 2");
    },

    async "network failures render a recoverable message"() {
        const document = documentFixture();
        itemHud.createController({
            document,
            initialState: state([item(26)], 1, 2),
            fetchImplementation: async () => { throw new Error("private network detail"); },
        });
        await document.elements.inventoryNext.click();
        assert.equal(document.elements.inventoryMessage.textContent, "Unable to load inventory. Please try again.");
        assert.equal(document.elements.inventoryNext.disabled, false);
    },

    "refresh state rendering replaces stale page and selection"() {
        const document = documentFixture();
        const controller = itemHud.createController({ document, initialState: state([item(9)]) });
        document.cells[0].click();
        controller.applyState(state([item(10)]));
        assert.equal(document.cells[0].dataset.itemId, "10");
        assert.equal(controller.selectedItemId(), null);
        assert.equal(document.elements.inventoryDetails.hidden, true);
    },

    "Task 20 markup activates pointer equipment controls without claim or drag behavior"() {
        assert.equal(typeof itemHud.createController, "function");
        assert.ok(gameMarkup.includes("data-inventory-slot"));
        assert.ok(gameMarkup.includes("data-equipment-slot"));
        assert.ok(gameMarkup.includes('id="equipmentAction"'));
        assert.ok(gameMarkup.includes('id="inventoryPrevious"'));
        assert.ok(gameMarkup.includes('id="inventoryNext"'));
        assert.equal(/data-claim(?:=|\s)|>\s*Claim\s*</.test(gameMarkup), false);
        assert.equal(/dragstart|drop\s*\(/.test(itemHud.createController.toString()), false);
    },

    "ten-slot paper doll renders equipped items with safe text"() {
        const document = documentFixture();
        const initial = state([]);
        initial.equipment.weapon = { ...item(7, "<img onerror=alert(1)>"), equipped: true, equipped_slot: "weapon" };
        itemHud.render(document, initial);
        assert.equal(document.equipmentSlots.length, 10);
        assert.equal(document.equipmentSlots[4].textContent, "/ <img onerror=alert(1)>");
        assert.equal(document.equipmentSlots[4].dataset.itemId, "7");
        assert.equal(document.equipmentSlots[3].textContent, "Ring");
    },

    async "selected inventory item equips and refreshes authoritative inventory and equipment"() {
        const document = documentFixture();
        const requests = [];
        const initial = state([item(7)]);
        const updated = state([]); updated.equipment.weapon = { ...item(7), equipped: true, equipped_slot: "weapon" };
        itemHud.createController({
            document, initialState: initial, csrfToken: "csrf", uuidFactory: () => "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
            fetchImplementation: async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => ({ state: updated, champion: { current_hp: 120, current_mana: 80, stats: { resources: { max_life: 170, max_mana: 190 } } } }) }; },
        });
        document.cells[0].click();
        assert.equal(document.elements.equipmentAction.textContent, "Equip");
        await document.elements.equipmentAction.click();
        assert.equal(requests[0].url, "equip_item.php");
        assert.deepEqual(JSON.parse(requests[0].options.body), { csrf_token: "csrf", item_id: 7, slot: "weapon", request_token: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa" });
        assert.equal(document.cells[0].dataset.itemId, undefined);
        assert.equal(document.equipmentSlots[4].dataset.itemId, "7");
        assert.equal(document.elements.playerHp.textContent, "120/170");
        assert.equal(document.elements.playerMana.textContent, "80/190");
        assert.equal(document.elements.playerHpBar.attributes["aria-valuemax"], "170");
    },

    async "equipped item unequips and failed retry reuses one mutation token"() {
        const document = documentFixture();
        const initial = state([]); initial.equipment.weapon = { ...item(7), equipped: true, equipped_slot: "weapon" };
        const requests = []; let attempt = 0;
        itemHud.createController({
            document, initialState: initial, csrfToken: "csrf", uuidFactory: () => "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
            fetchImplementation: async (url, options) => {
                requests.push({ url, options }); attempt++;
                if (attempt === 1) throw new Error("lost response");
                const updated = state([item(7)]); return { ok: true, json: async () => ({ state: updated }) };
            },
        });
        document.equipmentSlots[4].click();
        assert.equal(document.elements.equipmentAction.textContent, "Unequip");
        await document.elements.equipmentAction.click();
        await document.elements.equipmentAction.click();
        assert.equal(requests.length, 2);
        assert.equal(JSON.parse(requests[0].options.body).request_token, JSON.parse(requests[1].options.body).request_token);
        assert.equal(document.cells[0].dataset.itemId, "7");
    },

    "server lock reason disables equipment mutation controls"() {
        const document = documentFixture(); const initial = state([item(7)]);
        initial.equipment_locked = true; initial.mutation_disabled_reason = "combat_active";
        itemHud.createController({ document, initialState: initial });
        document.cells[0].click();
        assert.equal(document.elements.equipmentAction.disabled, true);
        assert.equal(document.elements.equipmentLockReason.textContent, "Equipment cannot change during combat.");
    },
};

(async function run() {
    let passed = 0;
    let failed = 0;
    for (const [name, test] of Object.entries(tests)) {
        try {
            await test();
            console.log(`[PASS] ${name}`);
            passed++;
        } catch (error) {
            console.log(`[FAIL] ${name}: ${error.message}`);
            failed++;
        }
    }
    console.log(`\n${passed} passed`);
    console.log(`${failed} failed`);
    process.exitCode = failed === 0 ? 0 : 1;
})();
