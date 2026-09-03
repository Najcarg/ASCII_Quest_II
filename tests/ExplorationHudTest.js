"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const hudPath = path.join(
    __dirname,
    "..",
    "ascii-quest",
    "js",
    "exploration_hud.js",
);

const hud = fs.existsSync(hudPath) ? require(hudPath) : {};
const gameControlsSource = fs.readFileSync(
    path.join(__dirname, "..", "ascii-quest", "js", "game_controls.js"),
    "utf8",
);
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
        "left-main": {
            dataset: { characterId: "42", csrfToken: "test-token" },
            hidden: false,
        },
        "left-details": {
            dataset: {},
            hidden: true,
        },
        mainStatPoints: { textContent: "1" },
        "mainStat-strength": { textContent: "10" },
        "mainStat-dexterity": { textContent: "5" },
        "mainStat-vitality": { textContent: "10" },
        "mainStat-energy": { textContent: "5" },
        "mainStat-fate": { textContent: "5" },
        mainAllocationMessage: { dataset: {}, hidden: true, textContent: "" },
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

function createWarpDocument() {
    function createElement(tagName) {
        const listeners = {};
        const element = {
            tagName,
            children: [],
            className: "",
            dataset: {},
            disabled: false,
            hidden: false,
            textContent: "",
            addEventListener(eventName, listener) {
                listeners[eventName] = listener;
            },
            appendChild(child) {
                this.children.push(child);
            },
            click(event = { target: this }) {
                return listeners.click?.(event);
            },
            closest(selector) {
                return selector === "[data-warp-travel]" &&
                    this.dataset.warpTravel
                    ? this
                    : null;
            },
            listeners,
        };

        Object.defineProperty(element, "innerHTML", {
            set(value) {
                if (value === "") {
                    this.children = [];
                }
            },
        });

        return element;
    }

    const elements = {
        "left-warp": createElement("section"),
        warpDestinationList: createElement("div"),
        warpConfirmation: createElement("div"),
        warpConfirmationText: createElement("p"),
        warpConfirmButton: createElement("button"),
        warpCancelButton: createElement("button"),
        warpMessage: createElement("div"),
        playerGold: createElement("strong"),
    };
    elements["left-warp"].dataset = {
        csrfToken: "csrf-token",
        currentGold: "20",
        destinations: JSON.stringify([
            {
                id: "forgotten_cave",
                name: "Forgotten Cave",
                cost: 10,
                current_location: false,
            },
        ]),
    };
    elements.warpConfirmation.hidden = true;

    return {
        elements,
        createElement,
        getElementById(id) {
            return elements[id] || null;
        },
    };
}

function createGameControlsHarness(options = {}) {
    const interactionResult = options.interactionResult || null;
    const movementResult = options.movementResult || null;
    const documentListeners = {};
    const elementListeners = {};
    const requests = [];
    const unlockStates = [];
    let reloads = 0;
    let intervals = 0;

    function createElement() {
        return {
            children: [],
            dataset: {},
            textContent: "",
            className: "",
            title: "",
            scrollHeight: 0,
            scrollTop: 0,
            appendChild(child) {
                this.children.push(child);
                this.firstElementChild = this.children[0] || null;
            },
            removeChild(child) {
                this.children = this.children.filter((candidate) => candidate !== child);
                this.firstElementChild = this.children[0] || null;
            },
            addEventListener(eventName, listener) {
                elementListeners[eventName] = listener;
            },
        };
    }

    const elements = {
        gameMap: createElement(),
        gameLogMessages: createElement(),
        playerPosition: createElement(),
        playerGold: createElement(),
        playerHp: createElement(),
        playerMana: createElement(),
        "left-warp": createElement(),
    };
    elements["left-warp"].dataset.warpPending = "false";

    const documentRoot = {
        getElementById(id) {
            return elements[id] || null;
        },
        createElement,
        createDocumentFragment() {
            return createElement();
        },
        addEventListener(eventName, listener) {
            documentListeners[eventName] = listener;
        },
    };
    const state = {
        mode: options.mode || "exploration",
        mapRows: [".....", ".....", "....."],
        mapWidth: 5,
        mapHeight: 3,
        viewportWidth: 5,
        viewportHeight: 3,
        playerX: 3,
        playerY: 2,
        playerGlyph: "@",
        playerName: "Tester",
        tileTypes: {
            ".": { display_glyph: ".", css_class: "tile-floor", name: "Floor" },
        },
        csrfToken: "test-token",
        currentWarp: {
            id: "deep_cave",
            name: "Deep Cave",
            x: 4,
            y: 2,
            glyph: "⬡",
        },
        initialMessages: [],
        encounterEnemy: options.encounterEnemy || {
            id: "deep_cave_01_cave_brute",
            name: "Cave Brute",
            x: 4,
            y: 1,
            glyph: "B",
        },
    };
    const windowRoot = {
        ASCII_QUEST_STATE: state,
        ASCIIQuestHud: {
            applyWarpUnlockState(result) {
                unlockStates.push(result);
                return true;
            },
            updateWarpDestinations() {},
        },
        location: {
            reload() {
                reloads++;
            },
        },
    };

    async function fetchImplementation(url, options = {}) {
        requests.push({ url, options });

        return {
            ok: true,
            async json() {
                if (url === "interact.php") {
                    return interactionResult || {
                        success: false,
                        message: "There is nothing to interact with here.",
                        messages: ["There is nothing to interact with here."],
                    };
                }

                if (url === "move_character.php" && movementResult) {
                    return movementResult;
                }

                return {
                    success: false,
                    message: "Blocked.",
                    messages: ["Blocked."],
                };
            },
        };
    }

    vm.runInNewContext(gameControlsSource, {
        window: windowRoot,
        document: documentRoot,
        fetch: fetchImplementation,
        FormData,
        URLSearchParams,
        setInterval() {
            intervals++;
        },
        setTimeout(callback) {
            callback();
        },
        console,
    });

    return {
        requests,
        unlockStates,
        get intervals() {
            return intervals;
        },
        get reloads() {
            return reloads;
        },
        clickMap(x, y) {
            elementListeners.click?.({
                target: {
                    closest(selector) {
                        return selector === ".map-cell"
                            ? { dataset: { mapX: String(x), mapY: String(y) } }
                            : null;
                    },
                },
            });
        },
        pressE() {
            documentListeners.keydown?.({
                key: "e",
                preventDefault() {},
            });
        },
        pressKey(key) {
            documentListeners.keydown?.({
                key,
                preventDefault() {},
            });
        },
    };
}

function waitForGameControlRequest() {
    return new Promise((resolve) => setImmediate(resolve));
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
    async "clicking the Warp glyph never sends an unlock request"() {
        const controls = createGameControlsHarness();

        controls.clickMap(4, 2);
        await waitForGameControlRequest();

        assert.equal(controls.requests.length, 1);
        assert.equal(controls.requests[0].url, "move_character.php");
        assert.equal(controls.requests[0].options.body.get("direction"), "right");
    },

    async "E uses the shared interaction endpoint and applies a Warp unlock response"() {
        const warpResult = {
            success: true,
            action: "unlock_warp",
            reload: false,
            message: "Warp unlocked: Deep Cave",
            messages: ["Warp unlocked: Deep Cave"],
            destinations: [
                { id: "deep_cave", name: "Deep Cave", cost: 5, current_location: true },
            ],
            character_updates: { gold: 20 },
        };
        const controls = createGameControlsHarness({ interactionResult: warpResult });

        controls.pressE();
        await waitForGameControlRequest();

        assert.equal(controls.requests.length, 1);
        assert.equal(controls.requests[0].url, "interact.php");
        assert.equal(controls.requests[0].options.body.get("csrf_token"), "test-token");
        assert.equal(controls.unlockStates.length, 1);
        assert.equal(controls.unlockStates[0].action, "unlock_warp");
    },

    async "E still dispatches chest interaction through the existing endpoint"() {
        const controls = createGameControlsHarness({
            interactionResult: {
                success: true,
                action: "open_chest",
                reload: false,
                message: "You open the chest.",
                messages: ["You open the chest."],
                tile_updates: [{ x: 1, y: 1, glyph: "o" }],
                character_updates: { gold: 25 },
            },
        });

        controls.pressE();
        await waitForGameControlRequest();

        assert.equal(controls.requests.length, 1);
        assert.equal(controls.requests[0].url, "interact.php");
        assert.equal(controls.unlockStates.length, 0);
    },

    async "combat mode attaches no movement E click or map-sync behavior"() {
        const controls = createGameControlsHarness({ mode: "combat" });

        controls.pressKey("ArrowRight");
        controls.pressKey("w");
        controls.pressE();
        controls.clickMap(4, 2);
        await waitForGameControlRequest();

        assert.equal(controls.requests.length, 0);
        assert.equal(controls.intervals, 0);
    },

    async "combat-start movement response locks exploration and reloads immediately"() {
        const controls = createGameControlsHarness({
            movementResult: {
                success: true,
                combat_started: true,
                pos_x: 3,
                pos_y: 2,
                combat: { encounter_id: 10, status: "active" },
                messages: ["The Cave Brute engages."],
            },
        });

        controls.clickMap(4, 2);
        await waitForGameControlRequest();
        controls.pressE();
        controls.pressKey("ArrowLeft");
        await waitForGameControlRequest();

        assert.equal(controls.requests.length, 1);
        assert.equal(controls.requests[0].url, "move_character.php");
        assert.equal(controls.reloads, 1);
    },

    "configured enemy is a visual occupied overlay without changing map JSON"() {
        const controls = createGameControlsHarness();

        assert.ok(gameControlsSource.includes("encounterEnemy"));
        assert.equal(gameControlsSource.includes('interactWithCurrentTile("combat"'), false);
        assert.ok(gameMarkup.includes("encounterEnemy"));
        assert.ok(gameMarkup.includes("combat-placeholder"));
        assert.equal(controls.requests.length, 0);
    },

    "Warp destinations expose current, disabled, and travel actions"() {
        assert.equal(typeof hud.buildWarpDestinationView, "function");

        const destinations = hud.buildWarpDestinationView(
            [
                { id: "deep_cave", name: "Deep Cave", cost: 5, current_location: true },
                { id: "forgotten_cave", name: "Forgotten Cave", cost: 10, current_location: false },
            ],
            7,
        );

        assert.deepEqual(destinations[0], {
            id: "deep_cave",
            name: "Deep Cave",
            cost: 5,
            action: "current",
            actionLabel: "CURRENT LOCATION",
            disabled: true,
        });
        assert.equal(destinations[1].action, "insufficient");
        assert.equal(destinations[1].actionLabel, "NOT ENOUGH GOLD");
        assert.equal(destinations[1].disabled, true);

        const affordable = hud.buildWarpDestinationView(
            [{ id: "forgotten_cave", name: "Forgotten Cave", cost: 10, current_location: false }],
            10,
        );
        assert.equal(affordable[0].action, "travel");
        assert.equal(affordable[0].actionLabel, "WARP");
        assert.equal(affordable[0].disabled, false);
    },

    "Warp confirmation cancel sends no request"() {
        assert.equal(typeof hud.createWarpTravelController, "function");

        let selected = null;
        let requests = 0;
        const controller = hud.createWarpTravelController({
            csrfToken: "csrf",
            fetchImplementation() {
                requests++;
            },
            onConfirmation(destination) {
                selected = destination;
            },
        });
        const destination = {
            id: "forgotten_cave",
            name: "Forgotten Cave",
            cost: 10,
            action: "travel",
        };

        controller.select(destination);
        assert.equal(selected, destination);
        controller.cancel();

        assert.equal(selected, null);
        assert.equal(requests, 0);
    },

    async "Warp confirm posts only identifier and CSRF and blocks duplicate submission"() {
        const requests = [];
        let releaseResponse;
        const pendingResponse = new Promise(function (resolve) {
            releaseResponse = resolve;
        });
        const controller = hud.createWarpTravelController({
            csrfToken: "csrf-token",
            fetchImplementation(url, options) {
                requests.push({ url, options });
                return pendingResponse;
            },
        });
        controller.select({
            id: "forgotten_cave",
            name: "Forgotten Cave",
            cost: 10,
            action: "travel",
        });

        const first = controller.confirm();
        const duplicate = controller.confirm();
        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, "travel_warp.php");
        assert.equal(requests[0].options.body.get("warp_id"), "forgotten_cave");
        assert.equal(requests[0].options.body.get("csrf_token"), "csrf-token");
        assert.equal(requests[0].options.body.has("cost"), false);
        assert.equal(requests[0].options.body.has("map"), false);
        assert.equal(requests[0].options.body.has("arrival_x"), false);

        releaseResponse({
            ok: true,
            async json() {
                return {
                    success: true,
                    reload: true,
                    character_updates: { gold: 10 },
                };
            },
        });
        await first;
        await duplicate;
    },

    async "Successful Warp travel updates Gold and requests authoritative map reload"() {
        let travelResult = null;
        const controller = hud.createWarpTravelController({
            csrfToken: "csrf-token",
            async fetchImplementation() {
                return {
                    ok: true,
                    async json() {
                        return {
                            success: true,
                            reload: true,
                            destination: { map_name: "Forgotten Cave" },
                            character_updates: { gold: 10 },
                        };
                    },
                };
            },
            onTravel(result) {
                travelResult = result;
            },
        });
        controller.select({ id: "forgotten_cave", action: "travel" });

        await controller.confirm();

        assert.equal(travelResult.character_updates.gold, 10);
        assert.equal(travelResult.reload, true);
        assert.equal(travelResult.destination.map_name, "Forgotten Cave");
    },

    async "Warp server errors are delivered as safe display text"() {
        let displayedError = "";
        const controller = hud.createWarpTravelController({
            csrfToken: "csrf-token",
            async fetchImplementation() {
                return {
                    ok: false,
                    async json() {
                        return { success: false, message: "Not enough Gold to use that Warp." };
                    },
                };
            },
            onError(message) {
                displayedError = message;
            },
        });
        controller.select({ id: "forgotten_cave", action: "travel" });

        await controller.confirm();

        assert.equal(displayedError, "Not enough Gold to use that Warp.");
    },

    "Successful unlock data refreshes the Warp destination view immediately"() {
        assert.equal(typeof hud.applyWarpUnlockState, "function");

        let rendered = null;
        const state = hud.applyWarpUnlockState(
            {
                destinations: [
                    { id: "deep_cave", name: "Deep Cave", cost: 5, current_location: true },
                ],
                character_updates: { gold: 20 },
            },
            function (destinations, gold) {
                rendered = hud.buildWarpDestinationView(destinations, gold);
            },
        );

        assert.equal(state, true);
        assert.equal(rendered.length, 1);
        assert.equal(rendered[0].id, "deep_cave");
        assert.equal(rendered[0].action, "current");
    },

    "live Gold changes recalculate stored Warp affordability"() {
        assert.equal(typeof hud.applyWarpGoldState, "function");

        const panel = {
            dataset: {
                currentGold: "4",
                destinations: JSON.stringify([
                    {
                        id: "deep_cave",
                        name: "Deep Cave",
                        cost: 5,
                        current_location: false,
                    },
                ]),
            },
        };
        const documentRoot = {
            getElementById(id) {
                return id === "left-warp" ? panel : null;
            },
        };
        let rendered = null;

        const applied = hud.applyWarpGoldState(
            documentRoot,
            5,
            function (destinations, gold) {
                rendered = hud.buildWarpDestinationView(destinations, gold);
            },
        );

        assert.equal(applied, true);
        assert.equal(panel.dataset.currentGold, "5");
        assert.equal(rendered[0].action, "travel");
    },

    "pending Warp state is shared through the exploration DOM"() {
        assert.equal(typeof hud.setWarpTravelPending, "function");

        const panel = { dataset: {} };
        const documentRoot = {
            getElementById(id) {
                return id === "left-warp" ? panel : null;
            },
        };

        hud.setWarpTravelPending(documentRoot, true);
        assert.equal(panel.dataset.warpPending, "true");
        hud.setWarpTravelPending(documentRoot, false);
        assert.equal(panel.dataset.warpPending, "false");
    },

    async "confirmed HUD travel updates Gold and reloads the authoritative map"() {
        const gameDocument = createWarpDocument();
        let reloads = 0;
        hud.initializeWarpTravel(
            gameDocument,
            async function () {
                return {
                    ok: true,
                    async json() {
                        return {
                            success: true,
                            reload: true,
                            message: "Warped to Forgotten Cave.",
                            character_updates: { gold: 10 },
                        };
                    },
                };
            },
            function () {
                reloads++;
            },
        );

        const card = gameDocument.elements.warpDestinationList.children[0];
        const warpButton = card.children[2];
        await gameDocument.elements["left-warp"].click({ target: warpButton });

        assert.equal(gameDocument.elements.warpConfirmation.hidden, false);
        assert.equal(
            gameDocument.elements.warpConfirmationText.textContent,
            "Warp to Forgotten Cave for 10 Gold?",
        );

        await gameDocument.elements.warpConfirmButton.click();

        assert.equal(gameDocument.elements.playerGold.textContent, "10");
        assert.equal(gameDocument.elements.warpConfirmation.hidden, true);
        assert.equal(reloads, 1);
    },

    "allocation controls belong to Main and Details is derived-only"() {
        const mainStart = gameMarkup.indexOf('id="left-main"');
        const detailsStart = gameMarkup.indexOf('id="left-details"');
        const warpStart = gameMarkup.indexOf('id="left-warp"');
        const mainMarkup = gameMarkup.slice(mainStart, detailsStart);
        const detailsMarkup = gameMarkup.slice(detailsStart, warpStart);

        assert.ok(mainMarkup.includes('id="mainStatPoints"'));
        assert.ok(mainMarkup.includes("data-stat-allocate"));
        assert.ok(mainMarkup.includes("data-character-id"));
        assert.equal(detailsMarkup.includes("data-stat-allocate"), false);
        assert.equal(detailsMarkup.includes("Stat Points:"), false);
        assert.ok(detailsMarkup.includes("data-character-stat-path"));
    },

    "inventory shell contains five visual rows"() {
        const inventoryLimit = gameMarkup.match(
            /for \(\$slot = 1; \$slot <= (\d+); \$slot\+\+\)/,
        );

        assert.ok(inventoryLimit, "The inventory placeholder loop must exist.");
        assert.equal(inventoryLimit[1], "25");
    },

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

        assert.equal(gameDocument.elements.mainStatPoints.textContent, "0");
        assert.equal(
            gameDocument.elements["mainStat-strength"].textContent,
            "11",
        );
        assert.equal(gameDocument.derivedStats[0].textContent, "60");
        assert.equal(gameDocument.derivedStats[1].textContent, "1.00");
        assert.equal(gameDocument.derivedStats[2].textContent, "15%");
        assert.equal(
            gameDocument.allocationButtons.every((button) => button.disabled),
            true,
        );
        assert.equal(gameDocument.elements["left-details"].hidden, true);
        assert.equal(gameDocument.elements["left-main"].hidden, false);
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
        assert.equal(gameDocument.elements.mainStatPoints.textContent, "0");
        assert.equal(gameDocument.elements["left-main"].hidden, false);
        assert.equal(gameDocument.elements["left-details"].hidden, true);
        assert.equal(gameDocument.elements.mainAllocationMessage.textContent, "");
        assert.equal(gameDocument.elements.mainAllocationMessage.hidden, true);
    },

    async "HUD allocation errors remain visible on Main"() {
        const gameDocument = createAllocationDocument();

        hud.initializeStatAllocation(gameDocument, async function () {
            return {
                ok: false,
                async json() {
                    return {
                        success: false,
                        message: "Security check failed. Please try again.",
                    };
                },
            };
        });
        await gameDocument.allocationButtons[0].click();

        assert.equal(
            gameDocument.elements.mainAllocationMessage.textContent,
            "Security check failed. Please try again.",
        );
        assert.equal(gameDocument.elements.mainAllocationMessage.hidden, false);
        assert.equal(
            gameDocument.elements.mainAllocationMessage.dataset.messageType,
            "error",
        );
        assert.equal(
            gameDocument.allocationButtons.every((button) => !button.disabled),
            true,
        );
    },

    async "zero stat points prevent allocation requests"() {
        assert.equal(
            typeof hud.initializeStatAllocation,
            "function",
            "The allocation controller initializer must exist.",
        );

        const gameDocument = createAllocationDocument();
        gameDocument.elements.mainStatPoints.textContent = "0";
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
