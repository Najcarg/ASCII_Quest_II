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
const explorationHud = require(path.join(
    __dirname,
    "..",
    "ascii-quest",
    "js",
    "exploration_hud.js",
));
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
        const listeners = {};
        const classes = new Set();

        return {
            attributes: {},
            children: [],
            className: "",
            classList: {
                add(...names) {
                    names.forEach((name) => classes.add(name));
                },
                contains(name) {
                    return classes.has(name);
                },
                remove(...names) {
                    names.forEach((name) => classes.delete(name));
                },
            },
            dataset: {},
            disabled: false,
            hidden: false,
            style: {},
            textContent: "",
            appendChild(child) {
                this.children.push(child);
            },
            addEventListener(type, listener) {
                listeners[type] = listener;
            },
            click() {
                return listeners.click?.();
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
        "battleHud",
        "combatTurnNumber", "combatTurnBar", "combatTurnFill",
        "combatChampionHp", "combatEnemyName", "combatEnemyGlyph",
        "combatEnemyHp", "combatEnemyHpBar", "combatEnemyHpFill", "combatEnemyAction",
        "combatAttackButton", "combatAttackName", "combatAttackStatus",
        "combatCooldownBar", "combatCooldownFill", "combatActionCount",
        "combatSkill1Button", "combatSkill1Name", "combatSkill1Status",
        "combatSkill1CooldownBar", "combatSkill1CooldownFill",
        "combatSkill2Button", "combatSkill2Name", "combatSkill2Status",
        "combatSkill3Button", "combatSkill3Name", "combatSkill3Status",
        "combatUltimateButton", "combatUltimateName", "combatUltimateStatus",
        "combatReactionLayer", "combatBlockButton", "combatEffects",
        "combatBattleEvents", "combatLootRow", "combatMessage",
        "combatPotionButton", "combatPotionCharges", "playerHp",
        "playerHpBar", "playerHpFill",
        "combatActivePanel", "combatVictoryPanel", "combatVictoryGold",
        "combatVictoryExperience", "combatPhysicalDrops", "combatCloseButton",
        "combatDefeatState",
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

function combatTabGroup() {
    function tabElement(id, target, active = false) {
        const listeners = {};
        const classes = new Set(active ? ["hud-tab", "is-active"] : ["hud-tab"]);

        return {
            id,
            dataset: target ? { tabTarget: target } : {},
            hidden: false,
            attributes: {},
            classList: {
                contains(name) {
                    return classes.has(name);
                },
                toggle(name, enabled) {
                    enabled ? classes.add(name) : classes.delete(name);
                },
            },
            setAttribute(name, value) {
                this.attributes[name] = String(value);
            },
            addEventListener(type, listener) {
                listeners[type] = listener;
            },
            click() {
                return listeners.click?.();
            },
        };
    }

    const buttons = [
        tabElement("combatBattleInfoTab", "combat-battle-info", true),
        tabElement("combatServerInfoTab", "combat-server-info"),
        tabElement("combatChatTab", "combat-chat"),
    ];
    const panels = [
        tabElement("combat-battle-info"),
        tabElement("combat-server-info"),
        tabElement("combat-chat"),
    ];

    return {
        buttons,
        panels,
        querySelectorAll(selector) {
            return selector === "[data-tab-target]" ? buttons : panels;
        },
    };
}

const tests = {
    "combat lower tabs start on Battle Info and retain Server Info and Chat placeholders"() {
        const combatStart = gameMarkup.indexOf('id="battleHud"');
        const explorationStart = gameMarkup.indexOf("<?php else:", combatStart);
        const combatMarkup = gameMarkup.slice(combatStart, explorationStart);
        const battleIndex = combatMarkup.indexOf(">Battle Info</button>");
        const serverIndex = combatMarkup.indexOf(">Server Info</button>");
        const chatIndex = combatMarkup.indexOf(">Chat</button>");

        assert.ok(combatMarkup.includes('class="hud-bottom-panel" data-tab-group'));
        assert.ok(battleIndex >= 0, "Battle Info is a combat tab.");
        assert.ok(battleIndex < serverIndex && serverIndex < chatIndex, "Combat tab order is authoritative.");
        assert.match(
            combatMarkup,
            /class="hud-tab is-active"[\s\S]*?aria-selected="true"[\s\S]*?data-tab-target="combat-battle-info"[\s\S]*?>Battle Info<\/button>/,
        );
        assert.match(combatMarkup, /id="combat-battle-info"[\s\S]*?data-tab-panel/);
        assert.match(combatMarkup, /id="combat-server-info"[\s\S]*?Server information will appear here in a later milestone\./);
        assert.match(combatMarkup, /id="combat-chat"[\s\S]*?Chat will be implemented in a later milestone\./);
    },

    "active combat markup provides all center controls while right Loadout stays display-only"() {
        assert.equal(gameMarkup.includes("combat-placeholder"), false);
        const commandPanel = gameMarkup.match(
            /<section class="combat-command-panel"[\s\S]*?<\/section>/,
        )?.[0] || "";
        const loadoutPanel = gameMarkup.match(
            /<h2>Loadout<\/h2>[\s\S]*?<\/section>/,
        )?.[0] || "";
        for (const id of [
            "battleHud",
            "combatChampion",
            "combatEnemy",
            "combatEnemyHpBar",
            "combatTurnBar",
            "combatReactionLayer",
            "combatEffects",
            "combatBattleEvents",
            "combatLootRow",
        ]) {
            assert.ok(gameMarkup.includes(`id="${id}"`), `${id} markup exists.`);
        }
        for (const id of [
            "combatAttackButton",
            "combatCooldownBar",
            "combatPotionButton",
            "combatPotionCharges",
            "combatSkill1Button",
            "combatSkill1CooldownBar",
            "combatSkill2Button",
            "combatSkill3Button",
            "combatUltimateButton",
        ]) {
            assert.ok(commandPanel.includes(`id="${id}"`), `${id} is a center combat control.`);
        }
        assert.equal(loadoutPanel.includes("<button"), false);
        assert.equal(loadoutPanel.includes('id="combatSkill1Button"'), false);
        assert.equal(loadoutPanel.includes('id="combatPotionButton"'), false);
        assert.ok(loadoutPanel.includes('data-loadout-slot="skill_1"'));
        assert.ok(loadoutPanel.includes('data-loadout-slot="potion"'));
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
                weapon_unavailable: hud.disabledReasonText("weapon_unavailable"),
            },
            {
                encounter_inactive: "Combat unavailable",
                actor_unavailable: "You cannot act",
                target_unavailable: "Target unavailable",
                actor_busy: "Action in progress",
                cooldown: "Weapon recovering",
                no_actions: "No Action available",
                insufficient_turn_time: "Not enough Turn time",
                weapon_unavailable: "Equip a weapon",
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

    "unconfigured center Skill 2 Skill 3 and Ultimate slots render Empty and disabled"() {
        const documentRoot = combatDocument();
        const state = combatState();

        hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, refresh: false },
            Date.parse(state.server_observed_at),
        );

        for (const prefix of ["combatSkill2", "combatSkill3", "combatUltimate"]) {
            assert.equal(documentRoot.elements[`${prefix}Name`].textContent, "Empty");
            assert.equal(documentRoot.elements[`${prefix}Status`].textContent, "Unavailable");
            assert.equal(documentRoot.elements[`${prefix}Button`].disabled, true);
            assert.equal(
                documentRoot.elements[`${prefix}Button`].classList.contains(
                    "combat-action-unavailable",
                ),
                true,
            );
        }
    },

    "ready weapon Flame Strike and Potion render red presentation while enabled"() {
        const documentRoot = combatDocument();
        const state = combatState({
            player_attack: {
                ...combatState().player_attack,
                available: true,
                disabled_reason: null,
            },
            player_skills: [
                {
                    ...combatState().player_skills[0],
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

        for (const id of [
            "combatAttackButton",
            "combatSkill1Button",
            "combatPotionButton",
        ]) {
            assert.equal(documentRoot.elements[id].disabled, false);
            assert.equal(
                documentRoot.elements[id].classList.contains("combat-action-ready"),
                true,
            );
        }
    },

    "temporary weapon and skill reasons render gold standby presentation while disabled"() {
        for (const reason of [
            "cooldown",
            "actor_busy",
            "no_actions",
            "insufficient_turn_time",
        ]) {
            const documentRoot = combatDocument();
            const state = combatState({
                player_attack: {
                    ...combatState().player_attack,
                    available: false,
                    disabled_reason: reason,
                },
                player_skills: [
                    {
                        ...combatState().player_skills[0],
                        available: false,
                        disabled_reason: reason,
                    },
                ],
            });

            hud.renderCombatHud(
                documentRoot,
                state,
                { attack: false, skill: false, block: false, potion: false, refresh: false },
                Date.parse(state.server_observed_at),
            );

            for (const id of ["combatAttackButton", "combatSkill1Button"]) {
                assert.equal(documentRoot.elements[id].disabled, true, `${reason} disables ${id}`);
                assert.equal(
                    documentRoot.elements[id].classList.contains("combat-action-standby"),
                    true,
                    `${reason} marks ${id} as standby`,
                );
            }
        }
    },

    "Potion without charges renders gray unavailable presentation"() {
        const documentRoot = combatDocument();
        const state = combatState({
            potion: {
                ...combatState().potion,
                charges_remaining: 0,
            },
        });

        hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, refresh: false },
            Date.parse(state.server_observed_at),
        );

        assert.equal(documentRoot.elements.combatPotionButton.disabled, true);
        assert.equal(
            documentRoot.elements.combatPotionButton.classList.contains(
                "combat-action-unavailable",
            ),
            true,
        );
    },

    "defeat and victory loot render every ordinary combat command unavailable"() {
        for (const status of ["defeated", "victory_loot"]) {
            const documentRoot = combatDocument();
            const state = combatState({
                status,
                player_attack: {
                    ...combatState().player_attack,
                    available: true,
                    disabled_reason: null,
                },
                player_skills: [
                    {
                        ...combatState().player_skills[0],
                        available: true,
                        disabled_reason: null,
                    },
                ],
                loot_phase: status === "victory_loot"
                    ? { rewards: { gold: 25, experience: 40 }, physical_drops: [] }
                    : null,
            });

            hud.renderCombatHud(
                documentRoot,
                state,
                { attack: false, skill: false, block: false, potion: false, close: false, refresh: false },
                Date.parse(state.server_observed_at),
            );

            for (const id of [
                "combatAttackButton",
                "combatSkill1Button",
                "combatSkill2Button",
                "combatSkill3Button",
                "combatUltimateButton",
                "combatPotionButton",
            ]) {
                assert.equal(documentRoot.elements[id].disabled, true, `${status} disables ${id}`);
                assert.equal(
                    documentRoot.elements[id].classList.contains(
                        "combat-action-unavailable",
                    ),
                    true,
                    `${status} marks ${id} unavailable`,
                );
            }
        }
    },

    "server-rendered Skill 1 respects authoritative availability before JavaScript paints"() {
        const commandPanel = gameMarkup.match(
            /<section class="combat-command-panel"[\s\S]*?<\/section>/,
        )?.[0] || "";

        assert.ok(gameMarkup.includes("$combatSkillOneAvailable"));
        assert.ok(gameMarkup.includes("$combatSkillOneStatus"));
        assert.match(
            commandPanel,
            /id="combatSkill1Button"[^>]*<\?= \$combatSkillOneAvailable \? "" : "disabled" \?>/,
        );
        assert.ok(commandPanel.includes('id="combatSkill1Status"><?= e($combatSkillOneStatus) ?>'));
    },

    "secure request UUID falls back to getRandomValues when randomUUID is unavailable"() {
        assert.equal(typeof hud.createRequestToken, "function");
        const cryptoSource = {
            getRandomValues(bytes) {
                for (let index = 0; index < bytes.length; index++) {
                    bytes[index] = index;
                }
                return bytes;
            },
        };

        assert.equal(
            hud.createRequestToken(cryptoSource),
            "00010203-0405-4607-8809-0a0b0c0d0e0f",
        );
    },

    async "READY center Weapon Attack click sends intent and reconciles returned state"() {
        const documentRoot = combatDocument();
        const initial = combatState({
            player_attack: {
                ...combatState().player_attack,
                available: true,
                disabled_reason: null,
            },
        });
        const returned = combatState({
            version: 6,
            player_attack: {
                ...initial.player_attack,
                available: false,
                disabled_reason: "actor_busy",
            },
        });
        const requests = [];
        const gameState = { mode: "combat", combat: initial };

        hud.initializeCombatHud(documentRoot, gameState, {
            csrfToken: "csrf",
            fetchImplementation: async (url, options) => {
                requests.push({ url, options });
                return { ok: true, json: async () => returned };
            },
            now: () => Date.parse(initial.server_observed_at),
            requestTokenFactory: () => "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        });

        await documentRoot.elements.combatAttackButton.click();

        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, "combat_action.php");
        assert.equal(JSON.parse(requests[0].options.body).action_key, "prototype_weapon_attack");
        assert.equal(gameState.combat, returned);
        assert.equal(documentRoot.elements.combatAttackButton.disabled, true);
        assert.equal(documentRoot.elements.combatAttackStatus.textContent, "Action in progress");
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

    "Battle Info renders ordered text with only fixed allowlisted emphasis classes"() {
        const documentRoot = combatDocument();
        const state = combatState({
            active_effects: [{ key: "test_effect", name: "Test Effect" }],
            battle_events: [
                { sequence_number: 1, message: "Critical hit.", emphasis: "critical" },
                { sequence_number: 2, message: "Attack blocked.", emphasis: "blocked" },
                { sequence_number: 3, message: "Level gained.", emphasis: "level_up" },
                { sequence_number: 4, message: "Enemy defeated.", emphasis: "dead" },
                { sequence_number: 5, message: '<script>alert(1)</script>', emphasis: '"><script>bad()</script>' },
                { sequence_number: 6, message: "Prototype key stays ordinary.", emphasis: "constructor" },
            ],
        });

        const view = hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, refresh: false },
            Date.parse(state.server_observed_at),
        );

        assert.deepEqual(view.effects, [{
            key: "test_effect",
            name: "Test Effect",
            durationProgressPercent: 100,
        }]);
        assert.deepEqual(view.events, state.battle_events);
        assert.deepEqual(
            documentRoot.elements.combatBattleEvents.children.map((entry) => entry.textContent),
            [
                "Critical hit.",
                "Attack blocked.",
                "Level gained.",
                "Enemy defeated.",
                "<script>alert(1)</script>",
                "Prototype key stays ordinary.",
            ],
        );
        assert.deepEqual(
            documentRoot.elements.combatBattleEvents.children.map((entry) => entry.className),
            [
                "game-log-entry game-log-entry-critical",
                "game-log-entry game-log-entry-blocked",
                "game-log-entry game-log-entry-level-up",
                "game-log-entry game-log-entry-dead",
                "game-log-entry",
                "game-log-entry",
            ],
        );
        assert.equal("innerHTML" in documentRoot.elements.combatBattleEvents.children[4], false);
        assert.equal(view.lootPhase, null);
    },

    "victory loot freezes combat timing and renders rewards apart from physical drops"() {
        const documentRoot = combatDocument();
        const state = combatState({
            status: "victory_loot",
            enemy: { ...combatState().enemy, current_hp: 0, active_action: null },
            player_attack: {
                ...combatState().player_attack,
                available: false,
                disabled_reason: "encounter_inactive",
            },
            reaction_prompt: null,
            loot_phase: {
                rewards: { gold: 25, experience: 40 },
                physical_drops: [],
            },
        });
        const observedAt = Date.parse(state.server_observed_at);
        const view = hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, close: false, refresh: false },
            observedAt + 5000,
        );

        assert.equal(view.timelineMs, 2500, "terminal combat timeline does not interpolate");
        assert.equal(view.turn.progressPercent, 25, "Turn Bar remains frozen at victory");
        assert.equal(documentRoot.elements.combatActivePanel.hidden, true);
        assert.equal(documentRoot.elements.combatVictoryPanel.hidden, false);
        assert.equal(documentRoot.elements.combatVictoryGold.textContent, "25");
        assert.equal(documentRoot.elements.combatVictoryExperience.textContent, "40");
        assert.equal(documentRoot.elements.combatPhysicalDrops.textContent, "No physical item drops.");
        assert.equal(documentRoot.elements.combatAttackButton.disabled, true);
        assert.equal(documentRoot.elements.combatPotionButton.disabled, true);
        assert.equal(documentRoot.elements.combatReactionLayer.hidden, true);
        assert.equal(documentRoot.elements.combatBlockButton.disabled, true);
        assert.equal(documentRoot.elements.combatCloseButton.disabled, false);
        assert.deepEqual(view.lootPhase.physicalDrops, []);
    },

    async "defeat freezes chronology and disables every combat mutation while tabs remain"() {
        const documentRoot = combatDocument();
        const requests = [];
        const state = combatState({
            status: "defeated",
            champion: { ...combatState().champion, current_hp: 0 },
            player_attack: {
                ...combatState().player_attack,
                available: false,
                disabled_reason: "encounter_inactive",
            },
            reaction_prompt: null,
            loot_phase: null,
        });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation: async (url) => {
                requests.push(url);
                return { ok: true, json: async () => state };
            },
            initialState: state,
            requestTokenFactory: () => "13131313-1313-4313-8313-131313131313",
        });
        const view = hud.renderCombatHud(
            documentRoot,
            state,
            { attack: false, skill: false, block: false, potion: false, close: false, refresh: false },
            Date.parse(state.server_observed_at) + 5000,
        );

        assert.equal(view.timelineMs, 2500, "defeat does not interpolate live combat time");
        assert.equal(view.champion.currentHp, 0);
        assert.equal(documentRoot.elements.combatAttackButton.disabled, true);
        assert.equal(documentRoot.elements.combatSkill1Button.disabled, true);
        assert.equal(documentRoot.elements.combatPotionButton.disabled, true);
        assert.equal(documentRoot.elements.combatReactionLayer.hidden, true);
        assert.equal(documentRoot.elements.combatBlockButton.disabled, true);
        assert.equal(documentRoot.elements.combatDefeatState.hidden, false);
        assert.equal(await controller.attack(), false);
        assert.equal(await controller.skill("skill_1"), false);
        assert.equal(await controller.block(), false);
        assert.equal(await controller.potion(), false);
        assert.deepEqual(requests, []);
        assert.ok(gameMarkup.includes('id="combat-battle-info"'));
        assert.ok(gameMarkup.includes('id="combat-server-info"'));
        assert.ok(gameMarkup.includes('id="combat-chat"'));
    },

    async "victory close sends only CSRF then returns to exploration"() {
        const requests = [];
        let closed = 0;
        const state = combatState({
            status: "victory_loot",
            player_attack: { ...combatState().player_attack, available: true },
            loot_phase: {
                rewards: { gold: 25, experience: 40 },
                physical_drops: [],
            },
        });
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            fetchImplementation: async (url, options) => {
                requests.push([url, options]);
                return { ok: true, json: async () => ({ closed: true }) };
            },
            initialState: state,
            requestTokenFactory: () => "12121212-1212-4212-8212-121212121212",
            onClosed() {
                closed++;
            },
        });

        assert.equal(await controller.attack(), false, "stale attack availability cannot bypass victory");
        assert.equal(await controller.skill("skill_1"), false, "skills are inert during victory");
        assert.equal(await controller.block(), false, "Block is inert during victory");
        assert.equal(await controller.potion(), false, "Potion is inert during victory");
        assert.equal(await controller.close(), true);
        assert.equal(requests.length, 1);
        assert.equal(requests[0][0], "combat_close.php");
        assert.deepEqual(JSON.parse(requests[0][1].body), {
            csrf_token: "csrf",
        });
        assert.equal(closed, 1);
    },

    "victory markup keeps explicit close and Battle Info tabs without draggable rewards"() {
        for (const id of [
            "combatActivePanel",
            "combatVictoryPanel",
            "combatVictoryGold",
            "combatVictoryExperience",
            "combatPhysicalDrops",
            "combatCloseButton",
        ]) {
            assert.ok(gameMarkup.includes(`id="${id}"`), `${id} markup exists.`);
        }
        assert.match(gameMarkup, /id="combatCloseButton"[^>]*type="button"/);
        assert.equal(/(?:Gold|EXP)[\s\S]{0,120}draggable/.test(gameMarkup), false);
        assert.ok(gameMarkup.includes(">Battle Info</button>"));
        assert.ok(gameMarkup.includes(">Server Info</button>"));
        assert.ok(gameMarkup.includes(">Chat</button>"));
    },

    async "switching combat tabs leaves polling and timer reaction rendering active"() {
        const documentRoot = combatDocument();
        const tabs = combatTabGroup();
        const pollCallbacks = [];
        const frameCallbacks = [];
        let nowMs = Date.parse(combatState().server_observed_at);
        const returned = combatState({
            version: 6,
            timeline: { elapsed_ms: 3500 },
            reaction_prompt: null,
        });
        const initial = combatState({
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

        explorationHud.initializeTabGroup(tabs);
        hud.initializeCombatHud(documentRoot, { mode: "combat", combat: initial }, {
            csrfToken: "csrf",
            fetchImplementation: async () => ({ ok: true, json: async () => returned }),
            now: () => nowMs,
            requestTokenFactory: () => "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
            schedulePoll(callback) {
                pollCallbacks.push(callback);
            },
            scheduleFrame(callback) {
                frameCallbacks.push(callback);
            },
        });

        tabs.buttons[1].click();
        tabs.buttons[2].click();
        assert.equal(tabs.buttons[2].attributes["aria-selected"], "true");
        assert.equal(tabs.panels[2].hidden, false);
        assert.equal(documentRoot.elements.combatTurnFill.style.width, "25%");
        assert.equal(documentRoot.elements.combatCooldownFill.style.width, "50%");
        assert.equal(documentRoot.elements.combatReactionLayer.hidden, false);

        nowMs += 1000;
        frameCallbacks.shift()();
        assert.equal(documentRoot.elements.combatTurnFill.style.width, "35%");
        assert.equal(documentRoot.elements.combatCooldownFill.style.width, "83.33333333333334%");
        await pollCallbacks[0]();
        assert.equal(documentRoot.elements.combatReactionLayer.hidden, true);
        assert.equal(tabs.buttons[2].attributes["aria-selected"], "true");
        assert.equal(tabs.panels[2].hidden, false);
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

    "victory physical drop renders safe name rarity level affixes and Claim state"() {
        const documentRoot = combatDocument();
        const state = combatState({
            status: "victory_loot",
            loot_phase: {
                rewards: { gold: 25, experience: 40 },
                physical_drops: [{
                    id: 7,
                    claim_state: "unclaimed",
                    item: {
                        id: 55,
                        display_name: "<img src=x> Hunter Axe of Health",
                        rarity: "rare",
                        item_level: 10,
                        base_type: "axe",
                        affixes: [{ position: "prefix", display_fragment: "Hunter" }],
                        stat_lines: ["+7 Damage", "+5 Maximum Life"],
                    },
                }],
            },
        });
        hud.renderCombatHud(documentRoot, state, { claimDropId: null }, Date.parse(state.server_observed_at));
        const cards = documentRoot.elements.combatPhysicalDrops.children;
        assert.equal(cards.length, 1);
        assert.equal(cards[0].children[0].textContent, "<img src=x> Hunter Axe of Health");
        assert.equal(cards[0].children[1].textContent, "Rare · Item level 10 · axe");
        assert.equal(cards[0].children[2].textContent, "+7 Damage · +5 Maximum Life");
        assert.equal(cards[0].children[3].textContent, "Claim");
        assert.equal(cards[0].children[3].disabled, false);
    },

    async "claim retry reuses one request token and reconciles claimed identity"() {
        const requests = [];
        let attempts = 0;
        const state = combatState({
            status: "victory_loot",
            loot_phase: { rewards: { gold: 25, experience: 40 }, physical_drops: [{
                id: 7, claim_state: "unclaimed", item: { id: 55, display_name: "Axe", rarity: "normal", item_level: 1, base_type: "axe" },
            }] },
        });
        const claimed = { id: 7, claim_state: "claimed", item: state.loot_phase.physical_drops[0].item };
        const controller = hud.createCombatController({
            csrfToken: "csrf",
            initialState: state,
            requestTokenFactory: () => "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
            async fetchImplementation(url, options) {
                requests.push([url, JSON.parse(options.body)]);
                attempts++;
                if (attempts === 1) {
                    throw new Error("lost response");
                }
                return { ok: true, json: async () => ({ drop: claimed }) };
            },
        });
        assert.equal(await controller.claim(7), false);
        assert.equal(await controller.claim(7), true);
        assert.equal(requests[0][0], "item_claim.php");
        assert.equal(requests[0][1].request_token, requests[1][1].request_token);
        assert.equal(controller.state().loot_phase.physical_drops[0].claim_state, "claimed");
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
