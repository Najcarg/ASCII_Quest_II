"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const hudPath = path.join(
    __dirname,
    "..",
    "ascii-quest",
    "js",
    "exploration_hud.js",
);

const hud = fs.existsSync(hudPath) ? require(hudPath) : {};
const gameMarkup = fs.readFileSync(
    path.join(__dirname, "..", "ascii-quest", "game.php"),
    "utf8",
);
const criticalDamageMapping = gameMarkup.match(
    /\["Critical Damage", "combat\.critical_damage".+?, "(number|percentage)"\],/,
);

function createClassList(initialClasses = []) {
    const classes = new Set(initialClasses);

    return {
        contains(className) {
            return classes.has(className);
        },
        toggle(className, force) {
            if (force) {
                classes.add(className);
            } else {
                classes.delete(className);
            }
        },
    };
}

function createButton(target, initiallyActive = false) {
    const listeners = {};
    const attributes = {};

    return {
        dataset: { tabTarget: target },
        classList: createClassList(initiallyActive ? ["is-active"] : []),
        addEventListener(eventName, listener) {
            listeners[eventName] = listener;
        },
        setAttribute(name, value) {
            attributes[name] = value;
        },
        click() {
            listeners.click();
        },
        attributes,
    };
}

function createPanel(id) {
    return {
        id,
        hidden: false,
    };
}

function createTabGroup(buttons, panels) {
    return {
        querySelectorAll(selector) {
            if (selector === "[data-tab-target]") {
                return buttons;
            }

            if (selector === "[data-tab-panel]") {
                return panels;
            }

            return [];
        },
    };
}

function createResourceDocument(resourceName) {
    const attributes = {};
    const elements = {
        [`player${resourceName}`]: { textContent: "" },
        [`player${resourceName}Bar`]: {
            setAttribute(name, value) {
                attributes[name] = value;
            },
        },
        [`player${resourceName}Fill`]: { style: {} },
    };

    return {
        attributes,
        elements,
        getElementById(id) {
            return elements[id] || null;
        },
    };
}

function createAllocationDocument() {
    function createBar() {
        return {
            attributes: {},
            setAttribute(name, value) {
                this.attributes[name] = value;
            },
        };
    }

    const elements = {
        "left-details": {
            dataset: { characterId: "42", csrfToken: "test-token" },
            hidden: false,
        },
        detailStatPoints: { textContent: "1" },
        "detailStat-strength": { textContent: "10" },
        "detailStat-dexterity": { textContent: "5" },
        "detailStat-vitality": { textContent: "10" },
        "detailStat-energy": { textContent: "5" },
        "detailStat-fate": { textContent: "5" },
        detailAllocationMessage: { dataset: {}, hidden: true, textContent: "" },
        playerHp: { textContent: "145/200" },
        playerHpBar: createBar(),
        playerHpFill: { style: { width: "72.5%" } },
        playerMana: { textContent: "80/175" },
        playerManaBar: createBar(),
        playerManaFill: { style: { width: "45.7%" } },
    };
    const derivedStats = [
        {
            dataset: {
                characterStatPath: "combat.melee_damage",
                characterStatFormat: "number",
            },
            textContent: "55",
        },
        {
            dataset: {
                characterStatPath: "rates.attack_rate",
                characterStatFormat: "rate",
            },
            textContent: "1.00",
        },
        {
            dataset: {
                characterStatPath: "combat.critical_chance",
                characterStatFormat: "percentage",
            },
            textContent: "15%",
        },
        {
            dataset: {
                characterStatPath: "combat.critical_damage",
                characterStatFormat: criticalDamageMapping?.[1],
            },
            textContent: "20",
        },
        {
            dataset: {
                characterStatPath: "resistances.poison",
                characterStatFormat: "percentage",
            },
            textContent: "10%",
        },
    ];
    const allocationButtons = [
        "strength",
        "dexterity",
        "vitality",
        "energy",
        "fate",
    ].map(function (stat) {
        const listeners = {};

        return {
            dataset: { statAllocate: stat },
            disabled: false,
            addEventListener(eventName, listener) {
                listeners[eventName] = listener;
            },
            click() {
                return listeners.click();
            },
        };
    });

    return {
        allocationButtons,
        derivedStats,
        elements,
        getElementById(id) {
            return elements[id] || null;
        },
        querySelectorAll(selector) {
            if (selector === "[data-character-stat-path]") {
                return derivedStats;
            }

            if (selector === "[data-stat-allocate]") {
                return allocationButtons;
            }

            return [];
        },
    };
}

function authoritativeAllocationState(stat = "strength") {
    const state = {
        stat_points: 0,
        current_hp: 145,
        current_mana: 80,
        stats: {
            main: {
                strength: 11,
                dexterity: 5,
                vitality: 10,
                energy: 5,
                fate: 5,
            },
            resources: { max_life: 200, max_mana: 175 },
            combat: {
                melee_damage: 60,
                toughness: 24,
                dodging: 12,
                accuracy: 15,
                critical_damage: 20,
                critical_chance: 15,
                spell_power: 30,
            },
            resistances: {
                fire: 11,
                lightning: 5,
                poison: 10,
                cold: 5,
            },
            rates: {
                action: 1,
                attack_rate: 1,
                cast_rate: 1,
                block_rate: 1,
            },
            fortune: { loot_chance: 6, gold_find: 6 },
            utility: {
                life_regeneration: 0,
                mana_regeneration: 0,
                life_on_hit: 0,
                mana_on_hit: 0,
                life_per_kill: 0,
                mana_per_kill: 0,
                fire_damage: 0,
                lightning_damage: 0,
                cold_damage: 0,
                poison_damage: 0,
                bleed_damage: 0,
                burn_damage: 0,
                freeze_damage: 0,
                shock_damage: 0,
                status_effect_chance: 0,
            },
        },
    };

    if (stat === "vitality") {
        state.stats.main.strength = 10;
        state.stats.main.vitality = 11;
        state.stats.resources.max_life = 210;
        state.stats.combat.melee_damage = 55;
        state.stats.combat.toughness = 22;
        state.stats.resistances.fire = 10;
        state.stats.resistances.poison = 11;
    }

    if (stat === "energy") {
        state.stats.main.strength = 10;
        state.stats.main.energy = 6;
        state.stats.resources.max_mana = 190;
        state.stats.combat.melee_damage = 55;
        state.stats.combat.toughness = 22;
        state.stats.combat.spell_power = 35;
        state.stats.resistances.fire = 10;
        state.stats.resistances.cold = 6;
    }

    if (stat === "dexterity") {
        state.stats.main.strength = 10;
        state.stats.main.dexterity = 6;
        state.stats.combat.melee_damage = 55;
        state.stats.combat.toughness = 22;
        state.stats.combat.dodging = 14;
        state.stats.combat.accuracy = 17;
        state.stats.combat.critical_damage = 23;
        state.stats.resistances.fire = 10;
        state.stats.resistances.lightning = 6;
    }

    return state;
}

const tests = {
    "default tab remains active when a group initializes"() {
        assert.equal(
            typeof hud.initializeTabGroup,
            "function",
            "The HUD tab initializer must exist.",
        );

        const mainButton = createButton("left-main", true);
        const detailsButton = createButton("left-details");
        const mainPanel = createPanel("left-main");
        const detailsPanel = createPanel("left-details");

        hud.initializeTabGroup(
            createTabGroup(
                [mainButton, detailsButton],
                [mainPanel, detailsPanel],
            ),
        );

        assert.equal(mainPanel.hidden, false);
        assert.equal(detailsPanel.hidden, true);
        assert.equal(mainButton.classList.contains("is-active"), true);
        assert.equal(mainButton.attributes["aria-selected"], "true");
        assert.equal(detailsButton.attributes["aria-selected"], "false");
    },

    "clicking a tab shows only its matching panel"() {
        assert.equal(
            typeof hud.initializeTabGroup,
            "function",
            "The HUD tab initializer must exist.",
        );

        const itemsButton = createButton("right-items", true);
        const skillButton = createButton("right-skills");
        const itemsPanel = createPanel("right-items");
        const skillPanel = createPanel("right-skills");

        hud.initializeTabGroup(
            createTabGroup(
                [itemsButton, skillButton],
                [itemsPanel, skillPanel],
            ),
        );
        skillButton.click();

        assert.equal(itemsPanel.hidden, true);
        assert.equal(skillPanel.hidden, false);
        assert.equal(itemsButton.classList.contains("is-active"), false);
        assert.equal(skillButton.classList.contains("is-active"), true);
        assert.equal(skillButton.attributes["aria-selected"], "true");
    },

    "server resource values synchronize a visual bar"() {
        assert.equal(
            typeof hud.updateResourceBar,
            "function",
            "The resource-bar synchronizer must exist.",
        );

        const attributes = {};
        const bar = {
            setAttribute(name, value) {
                attributes[name] = value;
            },
        };
        const fill = { style: {} };

        hud.updateResourceBar(bar, fill, 45, 200);

        assert.equal(fill.style.width, "22.5%");
        assert.equal(attributes["aria-valuenow"], "45");
        assert.equal(attributes["aria-valuemax"], "200");
    },

    "trap HP response synchronizes the game DOM value and fill"() {
        assert.equal(
            typeof hud.synchronizeResourceDisplay,
            "function",
            "The game DOM resource synchronizer must exist.",
        );

        const gameDocument = createResourceDocument("Hp");

        assert.equal((gameMarkup.match(/id="playerHp"/g) || []).length, 1);
        assert.equal((gameMarkup.match(/id="playerHpBar"/g) || []).length, 1);
        assert.equal((gameMarkup.match(/id="playerHpFill"/g) || []).length, 1);

        hud.synchronizeResourceDisplay(gameDocument, "Hp", 110, 220);

        assert.equal(gameDocument.elements.playerHp.textContent, "110/220");
        assert.equal(gameDocument.elements.playerHpFill.style.width, "50%");
        assert.equal(gameDocument.attributes["aria-valuenow"], "110");
        assert.equal(gameDocument.attributes["aria-valuemax"], "220");
    },

    "Mana uses the same server-value DOM synchronization"() {
        assert.equal(
            typeof hud.synchronizeResourceDisplay,
            "function",
            "The game DOM resource synchronizer must exist.",
        );

        const gameDocument = createResourceDocument("Mana");

        assert.equal((gameMarkup.match(/id="playerMana"/g) || []).length, 1);
        assert.equal(
            (gameMarkup.match(/id="playerManaBar"/g) || []).length,
            1,
        );
        assert.equal(
            (gameMarkup.match(/id="playerManaFill"/g) || []).length,
            1,
        );

        hud.synchronizeResourceDisplay(gameDocument, "Mana", 45, 180);

        assert.equal(gameDocument.elements.playerMana.textContent, "45/180");
        assert.equal(gameDocument.elements.playerManaFill.style.width, "25%");
        assert.equal(gameDocument.attributes["aria-valuenow"], "45");
        assert.equal(gameDocument.attributes["aria-valuemax"], "180");
    },

    "authoritative allocation state refreshes Details values"() {
        assert.equal(
            typeof hud.applyAllocationState,
            "function",
            "The allocation-state renderer must exist.",
        );

        const gameDocument = createAllocationDocument();

        hud.applyAllocationState(
            gameDocument,
            authoritativeAllocationState(),
        );

        assert.equal(gameDocument.elements.detailStatPoints.textContent, "0");
        assert.equal(
            gameDocument.elements["detailStat-strength"].textContent,
            "11",
        );
        assert.equal(gameDocument.derivedStats[0].textContent, "60");
        assert.equal(gameDocument.derivedStats[1].textContent, "1.00");
        assert.equal(gameDocument.derivedStats[2].textContent, "15%");
        assert.equal(
            gameDocument.allocationButtons.every((button) => button.disabled),
            true,
        );
        assert.equal(gameDocument.elements["left-details"].hidden, false);
    },

    "allocation state preserves stored resources while updating maximums"() {
        assert.equal(
            typeof hud.applyAllocationState,
            "function",
            "The allocation-state renderer must exist.",
        );

        const gameDocument = createAllocationDocument();

        hud.applyAllocationState(
            gameDocument,
            authoritativeAllocationState("vitality"),
        );

        assert.equal(gameDocument.elements.playerHp.textContent, "145/210");
        assert.equal(
            gameDocument.elements.playerHpFill.style.width,
            "69.04761904761905%",
        );
        assert.equal(
            gameDocument.elements.playerHpBar.attributes["aria-valuenow"],
            "145",
        );
        assert.equal(
            gameDocument.elements.playerHpBar.attributes["aria-valuemax"],
            "210",
        );
        assert.equal(gameDocument.derivedStats[4].textContent, "11%");
        assert.equal(gameDocument.elements.playerMana.textContent, "80/175");
        assert.equal(
            gameDocument.elements.playerManaFill.style.width,
            "45.714285714285715%",
        );
        assert.equal(
            gameDocument.elements.playerManaBar.attributes["aria-valuenow"],
            "80",
        );
        assert.equal(
            gameDocument.elements.playerManaBar.attributes["aria-valuemax"],
            "175",
        );

        const energyDocument = createAllocationDocument();
        hud.applyAllocationState(
            energyDocument,
            authoritativeAllocationState("energy"),
        );

        assert.equal(energyDocument.elements.playerHp.textContent, "145/200");
        assert.equal(energyDocument.elements.playerMana.textContent, "80/190");
        assert.equal(
            energyDocument.elements.playerManaFill.style.width,
            "42.10526315789473%",
        );
        assert.equal(
            energyDocument.elements.playerManaBar.attributes["aria-valuenow"],
            "80",
        );
        assert.equal(
            energyDocument.elements.playerManaBar.attributes["aria-valuemax"],
            "190",
        );
    },

    "Critical Damage renders as a flat numeric value"() {
        assert.ok(
            criticalDamageMapping,
            "The game Details mapping must include Critical Damage.",
        );

        const gameDocument = createAllocationDocument();
        hud.applyAllocationState(
            gameDocument,
            authoritativeAllocationState("dexterity"),
        );
        const criticalDamage = gameDocument.derivedStats.find(
            (element) =>
                element.dataset.characterStatPath ===
                "combat.critical_damage",
        );

        assert.equal(criticalDamage.textContent, "23");
    },

    async "HUD allocation posts once and applies the server response"() {
        assert.equal(
            typeof hud.initializeStatAllocation,
            "function",
            "The allocation controller initializer must exist.",
        );

        const gameDocument = createAllocationDocument();
        const requests = [];
        let releaseResponse;
        const responsePending = new Promise(function (resolve) {
            releaseResponse = resolve;
        });
        const fetchAllocation = function (url, options) {
            requests.push({ options, url });
            return responsePending;
        };

        hud.initializeStatAllocation(gameDocument, fetchAllocation);

        const firstClick = gameDocument.allocationButtons[0].click();
        const duplicateClick = gameDocument.allocationButtons[0].click();

        assert.equal(requests.length, 1);
        assert.equal(
            gameDocument.allocationButtons.every((button) => button.disabled),
            true,
        );

        releaseResponse({
            ok: true,
            async json() {
                return {
                    success: true,
                    message: "Stat point allocated.",
                    character: authoritativeAllocationState(),
                };
            },
        });
        await firstClick;
        await duplicateClick;

        assert.equal(requests[0].url, "allocate_stat.php");
        assert.equal(requests[0].options.method, "POST");
        assert.equal(requests[0].options.headers.Accept, "application/json");
        assert.equal(requests[0].options.body.get("character_id"), "42");
        assert.equal(requests[0].options.body.get("csrf_token"), "test-token");
        assert.equal(requests[0].options.body.get("stat"), "strength");
        assert.equal(gameDocument.elements.detailStatPoints.textContent, "0");
        assert.equal(gameDocument.elements["left-details"].hidden, false);
        assert.equal(
            gameDocument.elements.detailAllocationMessage.textContent,
            "Stat point allocated.",
        );
    },

    async "zero stat points prevent allocation requests"() {
        assert.equal(
            typeof hud.initializeStatAllocation,
            "function",
            "The allocation controller initializer must exist.",
        );

        const gameDocument = createAllocationDocument();
        gameDocument.elements.detailStatPoints.textContent = "0";
        let requestCount = 0;

        hud.initializeStatAllocation(gameDocument, async function () {
            requestCount++;
        });
        await gameDocument.allocationButtons[0].click();

        assert.equal(requestCount, 0);
        assert.equal(
            gameDocument.allocationButtons.every((button) => button.disabled),
            true,
        );
    },
};

async function runTests() {
    let failures = 0;

    for (const [name, test] of Object.entries(tests)) {
        try {
            await test();
            console.log(`[PASS] ${name}`);
        } catch (error) {
            failures++;
            console.error(`[FAIL] ${name}: ${error.message}`);
        }
    }

    console.log(`\n${Object.keys(tests).length - failures} passed`);
    console.log(`${failures} failed`);

    process.exit(failures === 0 ? 0 : 1);
}

runTests();
