"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const hudPath = path.join(
    __dirname,
    "..",
    "ascii-quest",
    "js",
    "combat_hud.js",
);
const hud = fs.existsSync(hudPath) ? require(hudPath) : {};
const hudSource = fs.existsSync(hudPath)
    ? fs.readFileSync(hudPath, "utf8")
    : "";
const gameMarkup = fs.readFileSync(
    path.join(__dirname, "..", "ascii-quest", "game.php"),
    "utf8",
);

function combatState(overrides = {}) {
    const state = {
        encounter_id: 91,
        status: "active",
        version: 5,
        server_observed_at: "2026-09-15T12:00:00+00:00",
        timeline: { elapsed_ms: 2500 },
        turn: {
            number: 2,
            started_timeline_ms: 0,
            ends_timeline_ms: 10000,
            player_actions_remaining: 1,
            enemy_actions_remaining: 1,
        },
        champion: { id: 42, current_hp: 176, current_mana: 80 },
        enemy: {
            key: "cave_brute",
            name: "Cave Brute",
            glyph: "B",
            current_hp: 90,
            maximum_hp: 120,
            active_action: null,
        },
        player_attack: {
            key: "prototype_weapon_attack",
            name: "Weapon Attack",
            duration_ms: 1000,
            cooldown_started_timeline_ms: 1000,
            cooldown_ready_timeline_ms: 4000,
            available: false,
            disabled_reason: "cooldown",
        },
        player_skills: [
            {
                slot: "skill_1",
                key: "prototype_flame_strike",
                name: "Flame Strike",
                duration_ms: 1500,
                cooldown_started_timeline_ms: 1000,
                cooldown_ready_timeline_ms: 6000,
                available: false,
                disabled_reason: "cooldown",
            },
        ],
        player_actions: [],
        active_effects: [],
        reaction_prompt: null,
        potion: {
            key: "prototype_health_potion",
            charge_allowance: 1,
            charges_remaining: 1,
        },
        battle_events: [
            {
                sequence_number: 1,
                event_type: "combat_started",
                message: "The Cave Brute engages.",
                emphasis: "warning",
            },
        ],
        loot_phase: null,
    };

    return Object.assign(state, overrides);
}

function deferredResponse() {
    let release;
    const pending = new Promise(function (resolve) {
        release = resolve;
    });

    return { pending, release };
}

function combatDocument() {
    function element() {
        return {
            attributes: {},
            children: [],
            className: "",
            dataset: {},
            disabled: false,
            hidden: false,
            style: {},
            textContent: "",
            appendChild(child) {
                this.children.push(child);
            },
            replaceChildren(...children) {
                this.children = children;
            },
            setAttribute(name, value) {
                this.attributes[name] = String(value);
            },
        };
    }

    const ids = [
        "combatTurnNumber", "combatTurnBar", "combatTurnFill",
        "combatChampionHp", "combatEnemyName", "combatEnemyGlyph",
        "combatEnemyHp", "combatEnemyHpBar", "combatEnemyHpFill", "combatEnemyAction",
        "combatAttackButton", "combatAttackName", "combatAttackStatus",
        "combatCooldownBar", "combatCooldownFill", "combatActionCount",
        "combatSkill1Button", "combatSkill1Name", "combatSkill1Status",
        "combatSkill1CooldownBar", "combatSkill1CooldownFill",
        "combatReactionLayer", "combatBlockButton", "combatEffects",
        "combatBattleEvents", "combatLootRow", "combatMessage",
        "combatPotionButton", "combatPotionCharges", "playerHp",
        "playerHpBar", "playerHpFill",
    ];
    const elements = Object.fromEntries(ids.map((id) => [id, element()]));
    elements.playerHpBar.attributes["aria-valuemax"] = "200";
    elements.playerHpBar.getAttribute = function (name) {
        return this.attributes[name] ?? null;
    };

    return {
        elements,
        createElement: () => element(),
        getElementById(id) {
            return elements[id] || null;
        },
    };
}

const tests = {
    "active combat markup provides the approved center HUD and right Potion control"() {
        assert.equal(gameMarkup.includes("combat-placeholder"), false);
        for (const id of [
            "battleHud",
            "combatChampion",
            "combatEnemy",
            "combatEnemyHpBar",
            "combatTurnBar",
            "combatAttackButton",
            "combatCooldownBar",
            "combatReactionLayer",
            "combatEffects",
            "combatBattleEvents",
            "combatLootRow",
            "combatPotionButton",
            "combatPotionCharges",
            "combatSkill1Button",
            "combatSkill1CooldownBar",
        ]) {
            assert.ok(gameMarkup.includes(`id="${id}"`), `${id} markup exists.`);
        }
        assert.ok(gameMarkup.includes("js/combat_hud.js"));
        assert.equal(gameMarkup.includes(">Attack</span>"), false);
        assert.equal(/id="combatLootRow"[^>]*hidden/.test(gameMarkup), false);
    },

    "weapon presentation and safe disabled reasons come from authoritative state"() {
        assert.equal(typeof hud.buildPresentation, "function");
        assert.equal(typeof hud.disabledReasonText, "function");

        const readyState = combatState({
            player_attack: {
                ...combatState().player_attack,
                name: "Runed Axe",
                available: true,
                disabled_reason: null,
            },
        });
        const ready = hud.buildPresentation(readyState, Date.parse(readyState.server_observed_at));

        assert.equal(ready.attack.name, "Runed Axe");
        assert.equal(ready.attack.available, true);
        assert.equal(ready.attack.disabledText, "Ready");
        assert.deepEqual(
            {
                encounter_inactive: hud.disabledReasonText("encounter_inactive"),
                actor_unavailable: hud.disabledReasonText("actor_unavailable"),
                target_unavailable: hud.disabledReasonText("target_unavailable"),
                actor_busy: hud.disabledReasonText("actor_busy"),
                cooldown: hud.disabledReasonText("cooldown"),
                no_actions: hud.disabledReasonText("no_actions"),
                insufficient_turn_time: hud.disabledReasonText("insufficient_turn_time"),
            },
            {
                encounter_inactive: "Combat unavailable",
                actor_unavailable: "You cannot act",
                target_unavailable: "Target unavailable",
                actor_busy: "Action in progress",
                cooldown: "Weapon recovering",
                no_actions: "No Action available",
                insufficient_turn_time: "Not enough Turn time",
            },
        );
    },

    "Turn and cooldown bars interpolate from server timestamps and reconcile to fresh state"() {
        assert.equal(typeof hud.buildPresentation, "function");
        const initial = combatState();
        const observedAt = Date.parse(initial.server_observed_at);

        const initialView = hud.buildPresentation(initial, observedAt);
        const laterView = hud.buildPresentation(initial, observedAt + 1000);
        const reconciled = hud.buildPresentation(
            combatState({
                server_observed_at: "2026-09-15T12:00:01+00:00",
                timeline: { elapsed_ms: 1000 },
                turn: { ...initial.turn, number: 3, started_timeline_ms: 1000, ends_timeline_ms: 11000 },
                player_attack: {
                    ...initial.player_attack,
                    cooldown_started_timeline_ms: null,
                    cooldown_ready_timeline_ms: null,
                    available: true,
                    disabled_reason: null,
                },
            }),
            observedAt + 1000,
        );

        assert.equal(initialView.turn.progressPercent, 25);
        assert.equal(laterView.turn.progressPercent, 35);
        assert.equal(initialView.attack.cooldownProgressPercent, 50);
        assert.equal(laterView.attack.cooldownProgressPercent, 83.33333333333334);
        assert.equal(reconciled.turn.number, 3);
        assert.equal(reconciled.turn.progressPercent, 0);
        assert.equal(reconciled.attack.cooldownProgressPercent, 100);
    },

    "skill cooldown and active effect duration interpolate as separate authoritative windows"() {
        const initial = combatState({
            active_effects: [
                {
                    key: "prototype_burning",
                    name: "Burning",
                    started_timeline_ms: 2500,
                    ends_timeline_ms: 6500,
                },
            ],
        });
        const observedAt = Date.parse(initial.server_observed_at);
        const initialView = hud.buildPresentation(initial, observedAt);
        const laterView = hud.buildPresentation(initial, observedAt + 1000);

        assert.equal(initialView.skills.length, 1);
        assert.equal(initialView.skills[0].slot, "skill_1");
        assert.equal(initialView.skills[0].name, "Flame Strike");
        assert.equal(initialView.skills[0].disabledText, "Skill recovering");
        assert.equal(initialView.skills[0].cooldownProgressPercent, 30);
        assert.equal(initialView.effects.length, 1);
        assert.equal(initialView.effects[0].name, "Burning");
        assert.equal(initialView.effects[0].durationProgressPercent, 0);
        assert.equal(laterView.skills[0].cooldownProgressPercent, 50);
        assert.equal(laterView.effects[0].durationProgressPercent, 25);
        assert.notEqual(
            initialView.skills[0].cooldownProgressPercent,
            initialView.effects[0].durationProgressPercent,
        );
    },

    "Battle HUD rendering applies authoritative combat presentation to its DOM hooks"() {
        assert.equal(typeof hud.renderCombatHud, "function");
        const documentRoot = combatDocument();
        const state = combatState({
            enemy: {
                ...combatState().enemy,
                active_action: {
                    id: 70,
                    action_kind: "skill",
                    definition_key: "fire_slam",
                    name: "Fire Slam",
                    damage_type: "fire",
                    state: "pending",
                    started_timeline_ms: 3000,
                    resolves_timeline_ms: 5000,
                },
            },
            reaction_prompt: {
                enemy_action_id: 70,
                definition_key: "fire_slam",
                name: "Fire Slam",
                damage_type: "fire",
                resolves_timeline_ms: 5000,
                expires_timeline_ms: 5000,
                block_token: "b".repeat(64),
                x: 0.25,
                y: 0.75,
            },
        });

        hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, block: false, potion: false, refresh: false },
            Date.parse(state.server_observed_at),
        );

        assert.equal(documentRoot.elements.combatTurnNumber.textContent, "2");
        assert.equal(documentRoot.elements.combatTurnFill.style.width, "25%");
        assert.equal(documentRoot.elements.combatAttackName.textContent, "Weapon Attack");
        assert.equal(documentRoot.elements.combatAttackButton.disabled, true);
        assert.equal(documentRoot.elements.combatAttackStatus.textContent, "Weapon recovering");
        assert.equal(documentRoot.elements.combatEnemyName.textContent, "Cave Brute");
        assert.equal(documentRoot.elements.combatEnemyHp.textContent, "90/120");
        assert.equal(documentRoot.elements.combatEnemyAction.textContent, "Fire Slam");
        assert.equal(documentRoot.elements.combatReactionLayer.hidden, false);
        assert.equal(documentRoot.elements.combatBlockButton.style.left, "25%");
        assert.equal(documentRoot.elements.combatBlockButton.style.top, "75%");
        assert.equal(documentRoot.elements.combatPotionCharges.textContent, "1 / 1");
        assert.equal(documentRoot.elements.combatBattleEvents.children.length, 1);
        assert.equal(
            documentRoot.elements.combatBattleEvents.children[0].textContent,
            "The Cave Brute engages.",
        );
    },

    "configured Skill 1 renders its server state and a separate cooldown bar"() {
        const documentRoot = combatDocument();
        const state = combatState({
            active_effects: [
                {
                    key: "prototype_burning",
                    name: "Burning",
                    started_timeline_ms: 2500,
                    ends_timeline_ms: 6500,
                },
            ],
        });

        hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, refresh: false },
            Date.parse(state.server_observed_at),
        );

        assert.equal(documentRoot.elements.combatSkill1Name.textContent, "Flame Strike");
        assert.equal(documentRoot.elements.combatSkill1Status.textContent, "Skill recovering");
        assert.equal(documentRoot.elements.combatSkill1Button.disabled, true);
        assert.equal(documentRoot.elements.combatSkill1CooldownFill.style.width, "30%");
        assert.equal(documentRoot.elements.combatEffects.children.length, 1);
        assert.equal(documentRoot.elements.combatEffects.children[0].children[0].textContent, "Burning");
        assert.equal(
            documentRoot.elements.combatEffects.children[0].children[1].children[0].style.width,
            "0%",
        );
    },

    "available Skill 1 renders Ready and remains enabled"() {
        const documentRoot = combatDocument();
        const state = combatState({
            player_skills: [
                {
                    ...combatState().player_skills[0],
                    cooldown_started_timeline_ms: null,
                    cooldown_ready_timeline_ms: null,
                    available: true,
                    disabled_reason: null,
                },
            ],
        });

        hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, refresh: false },
            Date.parse(state.server_observed_at),
        );

        assert.equal(documentRoot.elements.combatSkill1Status.textContent, "Ready");
        assert.equal(documentRoot.elements.combatSkill1Button.disabled, false);
    },

    async "attack intent is pointer-driven idempotent while pending and never fabricates success"() {
        assert.equal(typeof hud.createCombatController, "function");
        const pendingResponse = deferredResponse();
        const requests = [];
        const states = [];
        const initial = combatState({
            player_attack: {
                ...combatState().player_attack,
                available: true,
                disabled_reason: null,
            },
        });
        const returned = combatState({
            timeline: { elapsed_ms: 2600 },
            player_attack: {
                ...initial.player_attack,
                available: false,
                disabled_reason: "actor_busy",
            },
        });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation(url, options) {
                requests.push({ url, options });
                return pendingResponse.pending;
            },
            initialState: initial,
            onState(state) {
                states.push(state);
            },
            requestTokenFactory: () => "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        });

        const first = controller.attack();
        const duplicate = controller.attack();

        assert.equal(requests.length, 1);
        assert.equal(controller.state(), initial, "Authoritative state is unchanged while pending.");
        const body = JSON.parse(requests[0].options.body);
        assert.equal(requests[0].url, "combat_action.php");
        assert.deepEqual(Object.keys(body).sort(), ["action_key", "csrf_token", "request_token"]);
        assert.equal(body.action_key, "prototype_weapon_attack");

        pendingResponse.release({ ok: true, json: async () => returned });
        assert.equal(await first, true);
        assert.equal(await duplicate, false);
        assert.equal(controller.state(), returned);
        assert.equal(states.at(-1), returned);
    },

    async "skill intent uses the existing action endpoint and suppresses duplicate clicks"() {
        const pendingResponse = deferredResponse();
        const requests = [];
        const initial = combatState({
            player_skills: [{
                ...combatState().player_skills[0],
                available: true,
                disabled_reason: null,
            }],
        });
        const returned = combatState({
            version: 6,
            player_skills: [{
                ...initial.player_skills[0],
                available: false,
                disabled_reason: "actor_busy",
            }],
        });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation(url, options) {
                requests.push({ url, options });
                return pendingResponse.pending;
            },
            initialState: initial,
            requestTokenFactory: () => "abababab-abab-4bab-8bab-abababababab",
        });

        const first = controller.skill("skill_1");
        const duplicate = controller.skill("skill_1");

        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, "combat_action.php");
        assert.deepEqual(JSON.parse(requests[0].options.body), {
            csrf_token: "csrf",
            action_key: "prototype_flame_strike",
            request_token: "abababab-abab-4bab-8bab-abababababab",
        });
        assert.equal(controller.state(), initial, "Skill does not fabricate a local result.");

        pendingResponse.release({ ok: true, json: async () => returned });
        assert.equal(await first, true);
        assert.equal(await duplicate, false);
        assert.equal(controller.state(), returned);
    },

    async "Block popup follows authoritative prompt and suppresses duplicate submissions"() {
        const prompt = {
            enemy_action_id: 70,
            definition_key: "fire_slam",
            name: "Fire Slam",
            damage_type: "fire",
            resolves_timeline_ms: 5000,
            expires_timeline_ms: 5000,
            block_token: "b".repeat(64),
            x: 0.25,
            y: 0.75,
        };
        const initial = combatState({ reaction_prompt: prompt });
        const view = hud.buildPresentation(initial, Date.parse(initial.server_observed_at));
        assert.equal(view.block.visible, true);
        assert.equal(view.block.xPercent, 25);
        assert.equal(view.block.yPercent, 75);

        const pendingResponse = deferredResponse();
        const requests = [];
        const returned = combatState({ reaction_prompt: null });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation(url, options) {
                requests.push({ url, options });
                return pendingResponse.pending;
            },
            initialState: initial,
            requestTokenFactory: () => "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
        });
        const first = controller.block();
        const duplicate = controller.block();

        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, "combat_block.php");
        assert.deepEqual(JSON.parse(requests[0].options.body), {
            csrf_token: "csrf",
            enemy_action_id: 70,
            block_token: "b".repeat(64),
            request_token: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
        });
        pendingResponse.release({ ok: true, json: async () => returned });
        assert.equal(await first, true);
        assert.equal(await duplicate, false);
        assert.equal(controller.presentation().block.visible, false);
    },

    async "Potion uses projected charges and never calculates healing or mutates combat state locally"() {
        const initial = combatState();
        const pendingResponse = deferredResponse();
        const requests = [];
        const returned = combatState({
            champion: { ...initial.champion, current_hp: 200 },
            potion: { ...initial.potion, charges_remaining: 0 },
        });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation(url, options) {
                requests.push({ url, options });
                return pendingResponse.pending;
            },
            initialState: initial,
            requestTokenFactory: () => "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
        });

        const first = controller.potion();
        const duplicate = controller.potion();

        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, "combat_potion.php");
        assert.deepEqual(JSON.parse(requests[0].options.body), {
            csrf_token: "csrf",
            request_token: "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
        });
        assert.equal(controller.state().champion.current_hp, 176);
        assert.equal(controller.state().potion.charges_remaining, 1);

        pendingResponse.release({ ok: true, json: async () => returned });
        assert.equal(await first, true);
        assert.equal(await duplicate, false);
        assert.equal(controller.state().champion.current_hp, 200);
        assert.equal(controller.presentation().potion.chargesRemaining, 0);
    },

    async "safe domain and network failures leave combat controls recoverable"() {
        const errors = [];
        let attempts = 0;
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation: async function () {
                attempts++;
                if (attempts === 1) {
                    return {
                        ok: false,
                        json: async () => ({ success: false, message: "Combat action is unavailable." }),
                    };
                }

                throw new Error("private network detail");
            },
            initialState: combatState({
                player_attack: {
                    ...combatState().player_attack,
                    available: true,
                    disabled_reason: null,
                },
            }),
            onError(message) {
                errors.push(message);
            },
            requestTokenFactory: () => "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
        });

        assert.equal(await controller.attack(), false);
        assert.equal(controller.pending().attack, false);
        assert.equal(await controller.attack(), false);
        assert.equal(controller.pending().attack, false);
        assert.deepEqual(errors, [
            "Combat action is unavailable.",
            "Unable to reach the combat server. Please try again.",
        ]);
        assert.equal(errors.join(" ").includes("private network detail"), false);
    },

    async "out-of-order responses cannot replace a newer authoritative combat version"() {
        const refreshResponse = deferredResponse();
        const attackResponse = deferredResponse();
        const initial = combatState({
            player_attack: {
                ...combatState().player_attack,
                available: true,
                disabled_reason: null,
            },
        });
        const newer = combatState({
            version: 6,
            timeline: { elapsed_ms: 3000 },
            player_attack: {
                ...initial.player_attack,
                available: false,
                disabled_reason: "actor_busy",
            },
        });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation(url) {
                return url === "combat_state.php"
                    ? refreshResponse.pending
                    : attackResponse.pending;
            },
            initialState: initial,
            requestTokenFactory: () => "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee",
        });

        const refresh = controller.refresh();
        const attack = controller.attack();
        attackResponse.release({ ok: true, json: async () => newer });
        await attack;
        assert.equal(controller.state(), newer);

        refreshResponse.release({ ok: true, json: async () => initial });
        await refresh;
        assert.equal(controller.state(), newer);
    },

    "Battle Info and effects render only projected server state"() {
        const state = combatState({
            active_effects: [{ key: "test_effect", name: "Test Effect" }],
            battle_events: [
                { sequence_number: 2, event_type: "damage", message: "Cave Brute hits for 12.", emphasis: "danger" },
                { sequence_number: 3, event_type: "potion_used", message: "Potion restores 24 Life.", emphasis: "success" },
            ],
        });
        const view = hud.buildPresentation(state, Date.parse(state.server_observed_at));

        assert.deepEqual(view.effects, [{
            key: "test_effect",
            name: "Test Effect",
            durationProgressPercent: 100,
        }]);
        assert.deepEqual(view.events, state.battle_events);
        assert.equal(view.lootPhase, null);
    },

    "combat presentation ignores hidden enemy timing and introduces no keyboard controls"() {
        const state = combatState();
        state.enemy.cooldown_ready_timeline_ms = 999999;
        state.enemy.next_enemy_decision_timeline_ms = 999999;
        state.next_enemy_decision_timeline_ms = 999999;
        const view = hud.buildPresentation(state, Date.parse(state.server_observed_at));
        const serialized = JSON.stringify(view);

        assert.equal(serialized.includes("cooldown_ready_timeline_ms"), false);
        assert.equal(serialized.includes("next_enemy_decision_timeline_ms"), false);
        assert.equal(hudSource.includes("keydown"), false);
        assert.equal(hudSource.includes("keyup"), false);
        assert.equal(/Arrow(?:Up|Down|Left|Right)|Key[QWER]|Digit[1-9]/.test(hudSource), false);
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
