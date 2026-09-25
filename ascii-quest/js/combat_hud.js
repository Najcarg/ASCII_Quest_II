(function (root, factory) {
    "use strict";

    const combatHud = factory();

    if (typeof module === "object" && module.exports) {
        module.exports = combatHud;
    }

    if (!root) {
        return;
    }

    root.ASCIIQuestCombatHud = combatHud;

    function initialize() {
        const gameState = root.ASCII_QUEST_STATE;
        if (!gameState || gameState.mode !== "combat") {
            return;
        }

        combatHud.initializeCombatHud(root.document, gameState, {
            fetchImplementation:
                typeof root.fetch === "function" ? root.fetch.bind(root) : null,
            now: () => Date.now(),
            requestTokenFactory() {
                return combatHud.createRequestToken(root.crypto);
            },
            scheduleFrame:
                typeof root.requestAnimationFrame === "function"
                    ? root.requestAnimationFrame.bind(root)
                    : null,
            schedulePoll:
                typeof root.setInterval === "function"
                    ? root.setInterval.bind(root)
                    : null,
        });
    }

    if (root.document.readyState === "loading") {
        root.document.addEventListener("DOMContentLoaded", initialize);
    } else {
        initialize();
    }
})(typeof window === "undefined" ? null : window, function () {
    "use strict";

    const ERROR_FALLBACK = "Unable to reach the combat server. Please try again.";
    const SKILL_CONTROLS = [
        { slot: "skill_1", id: "combatSkill1" },
        { slot: "skill_2", id: "combatSkill2" },
        { slot: "skill_3", id: "combatSkill3" },
        { slot: "ultimate", id: "combatUltimate" },
    ];

    function createRequestToken(cryptoSource) {
        if (typeof cryptoSource?.randomUUID === "function") {
            return cryptoSource.randomUUID();
        }
        if (typeof cryptoSource?.getRandomValues !== "function") {
            throw new Error("Secure combat request tokens are unavailable.");
        }

        const bytes = cryptoSource.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0"));

        return [
            hex.slice(0, 4).join(""),
            hex.slice(4, 6).join(""),
            hex.slice(6, 8).join(""),
            hex.slice(8, 10).join(""),
            hex.slice(10, 16).join(""),
        ].join("-");
    }

    function clamp(value, minimum, maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }

    function progressPercent(startTimelineMs, endTimelineMs, timelineMs) {
        const start = Number(startTimelineMs);
        const end = Number(endTimelineMs);
        const current = Number(timelineMs);
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
            return 100;
        }

        return clamp(((current - start) / (end - start)) * 100, 0, 100);
    }

    function interpolatedTimeline(state, wallNowMs) {
        const authoritativeTimeline = Number(state?.timeline?.elapsed_ms) || 0;
        const observedAtMs = Date.parse(state?.server_observed_at || "");
        const nowMs = Number(wallNowMs);
        if (!Number.isFinite(observedAtMs) || !Number.isFinite(nowMs)) {
            return authoritativeTimeline;
        }

        return authoritativeTimeline + Math.max(0, nowMs - observedAtMs);
    }

    function disabledReasonText(reason) {
        const messages = {
            encounter_inactive: "Combat unavailable",
            actor_unavailable: "You cannot act",
            target_unavailable: "Target unavailable",
            actor_busy: "Action in progress",
            cooldown: "Weapon recovering",
            no_actions: "No Action available",
            insufficient_turn_time: "Not enough Turn time",
        };

        return reason === null || reason === undefined
            ? "Ready"
            : messages[reason] || "Combat action unavailable";
    }

    function skillDisabledReasonText(reason) {
        if (reason === null || reason === undefined) {
            return "Ready";
        }

        return reason === "cooldown"
            ? "Skill recovering"
            : disabledReasonText(reason);
    }

    function buildPresentation(state, wallNowMs) {
        const timelineMs = interpolatedTimeline(state, wallNowMs);
        const attack = state?.player_attack || {};
        const cooldownStart = attack.cooldown_started_timeline_ms;
        const cooldownReady = attack.cooldown_ready_timeline_ms;
        const cooldownProgress =
            cooldownStart === null ||
            cooldownStart === undefined ||
            cooldownReady === null ||
            cooldownReady === undefined
                ? 100
                : progressPercent(cooldownStart, cooldownReady, timelineMs);
        const prompt = state?.reaction_prompt;
        const skills = Array.isArray(state?.player_skills)
            ? state.player_skills.map(function (skill) {
                  const cooldownStart = skill?.cooldown_started_timeline_ms;
                  const cooldownReady = skill?.cooldown_ready_timeline_ms;

                  return {
                      slot: String(skill?.slot || ""),
                      key: String(skill?.key || ""),
                      name: String(skill?.name || ""),
                      available: skill?.available === true,
                      disabledReason: skill?.disabled_reason ?? null,
                      disabledText: skillDisabledReasonText(skill?.disabled_reason),
                      cooldownProgressPercent:
                          cooldownStart === null ||
                          cooldownStart === undefined ||
                          cooldownReady === null ||
                          cooldownReady === undefined
                              ? 100
                              : progressPercent(cooldownStart, cooldownReady, timelineMs),
                  };
              })
            : [];
        const effects = Array.isArray(state?.active_effects)
            ? state.active_effects.map(function (effect) {
                  return {
                      key: String(effect?.key || ""),
                      name: String(effect?.name || ""),
                      durationProgressPercent: progressPercent(
                          effect?.started_timeline_ms,
                          effect?.ends_timeline_ms,
                          timelineMs,
                      ),
                  };
              })
            : [];

        return {
            status: String(state?.status || ""),
            timelineMs,
            turn: {
                number: Number(state?.turn?.number) || 0,
                actionsRemaining: Number(state?.turn?.player_actions_remaining) || 0,
                progressPercent: progressPercent(
                    state?.turn?.started_timeline_ms,
                    state?.turn?.ends_timeline_ms,
                    timelineMs,
                ),
            },
            champion: {
                currentHp: Number(state?.champion?.current_hp) || 0,
            },
            enemy: {
                name: String(state?.enemy?.name || "Enemy"),
                glyph: String(state?.enemy?.glyph || "?"),
                currentHp: Number(state?.enemy?.current_hp) || 0,
                maximumHp: Number(state?.enemy?.maximum_hp) || 0,
                activeActionName: String(state?.enemy?.active_action?.name || ""),
            },
            attack: {
                name: String(attack.name || ""),
                available: attack.available === true,
                disabledReason: attack.disabled_reason ?? null,
                disabledText: disabledReasonText(attack.disabled_reason),
                cooldownProgressPercent: cooldownProgress,
            },
            skills,
            block:
                prompt === null || prompt === undefined
                    ? { visible: false, name: "", xPercent: 0, yPercent: 0 }
                    : {
                          visible: true,
                          name: String(prompt.name || "Block"),
                          xPercent: clamp(Number(prompt.x) * 100, 0, 100),
                          yPercent: clamp(Number(prompt.y) * 100, 0, 100),
                      },
            potion: {
                chargeAllowance: Number(state?.potion?.charge_allowance) || 0,
                chargesRemaining: Number(state?.potion?.charges_remaining) || 0,
            },
            effects,
            events: Array.isArray(state?.battle_events)
                ? state.battle_events
                : [],
            lootPhase: state?.loot_phase ?? null,
        };
    }

    function createCombatController(options) {
        let authoritativeState = options.initialState;
        const pendingState = {
            attack: false,
            skill: false,
            block: false,
            potion: false,
            refresh: false,
        };
        const now = typeof options.now === "function" ? options.now : () => Date.now();

        function notifyPending() {
            if (typeof options.onPending === "function") {
                options.onPending({ ...pendingState });
            }
        }

        function reportError(message) {
            if (typeof options.onError === "function") {
                options.onError(message);
            }
        }

        async function request(kind, url, payload) {
            if (pendingState[kind] || typeof options.fetchImplementation !== "function") {
                return false;
            }

            pendingState[kind] = true;
            notifyPending();
            try {
                const requestOptions = { method: "GET", headers: { Accept: "application/json" } };
                if (payload !== null) {
                    requestOptions.method = "POST";
                    requestOptions.headers["Content-Type"] = "application/json";
                    requestOptions.body = JSON.stringify(payload);
                }
                const response = await options.fetchImplementation(url, requestOptions);
                const result = await response.json();
                if (!response.ok || result?.success === false) {
                    reportError(
                        typeof result?.message === "string"
                            ? result.message
                            : ERROR_FALLBACK,
                    );
                    return false;
                }

                const currentVersion = Number(authoritativeState?.version);
                const resultVersion = Number(result?.version);
                const isStale =
                    Number.isFinite(currentVersion) &&
                    Number.isFinite(resultVersion) &&
                    resultVersion < currentVersion;
                if (!isStale) {
                    authoritativeState = result;
                    if (typeof options.onState === "function") {
                        options.onState(authoritativeState);
                    }
                }

                return true;
            } catch (error) {
                reportError(ERROR_FALLBACK);
                return false;
            } finally {
                pendingState[kind] = false;
                notifyPending();
            }
        }

        return {
            state: () => authoritativeState,
            pending: () => ({ ...pendingState }),
            presentation: () => buildPresentation(authoritativeState, now()),
            attack() {
                const attack = authoritativeState?.player_attack;
                if (attack?.available !== true || pendingState.attack) {
                    return Promise.resolve(false);
                }

                return request("attack", "combat_action.php", {
                    csrf_token: options.csrfToken,
                    action_key: attack.key,
                    request_token: options.requestTokenFactory(),
                });
            },
            skill(slot) {
                const skills = Array.isArray(authoritativeState?.player_skills)
                    ? authoritativeState.player_skills
                    : [];
                const skill = skills.find((candidate) => candidate?.slot === slot);
                if (skill?.available !== true || pendingState.skill) {
                    return Promise.resolve(false);
                }

                return request("skill", "combat_action.php", {
                    csrf_token: options.csrfToken,
                    action_key: skill.key,
                    request_token: options.requestTokenFactory(),
                });
            },
            block() {
                const prompt = authoritativeState?.reaction_prompt;
                if (!prompt || pendingState.block) {
                    return Promise.resolve(false);
                }

                return request("block", "combat_block.php", {
                    csrf_token: options.csrfToken,
                    enemy_action_id: prompt.enemy_action_id,
                    block_token: prompt.block_token,
                    request_token: options.requestTokenFactory(),
                });
            },
            potion() {
                if (
                    Number(authoritativeState?.potion?.charges_remaining) <= 0 ||
                    pendingState.potion
                ) {
                    return Promise.resolve(false);
                }

                return request("potion", "combat_potion.php", {
                    csrf_token: options.csrfToken,
                    request_token: options.requestTokenFactory(),
                });
            },
            refresh() {
                return request("refresh", "combat_state.php", null);
            },
        };
    }

    function setText(documentRoot, id, value) {
        const element = documentRoot.getElementById(id);
        if (element) {
            element.textContent = String(value);
        }
    }

    function setProgress(documentRoot, barId, fillId, percentage, valueNow) {
        const bar = documentRoot.getElementById(barId);
        const fill = documentRoot.getElementById(fillId);
        if (fill) {
            fill.style.width = String(percentage) + "%";
        }
        if (bar) {
            bar.setAttribute("aria-valuenow", String(valueNow ?? percentage));
        }
    }

    function replaceMessages(documentRoot, id, values, emptyMessage, classPrefix) {
        const container = documentRoot.getElementById(id);
        if (!container) {
            return;
        }

        const entries = values.length === 0 ? [{ message: emptyMessage }] : values;
        const elements = entries.map(function (entry) {
            const element = documentRoot.createElement("div");
            const emphasis = ["info", "success", "warning", "danger"].includes(
                entry.emphasis,
            )
                ? entry.emphasis
                : "info";
            element.className = classPrefix + " " + classPrefix + "-" + emphasis;
            element.textContent = String(entry.message ?? entry.name ?? entry.key ?? "");
            return element;
        });
        container.replaceChildren(...elements);
    }

    function renderEffects(documentRoot, effects) {
        const container = documentRoot.getElementById("combatEffects");
        if (!container) {
            return;
        }
        if (effects.length === 0) {
            const empty = documentRoot.createElement("div");
            empty.className = "combat-effect combat-effect-info";
            empty.textContent = "No active effects";
            container.replaceChildren(empty);
            return;
        }

        const entries = effects.map(function (effect) {
            const item = documentRoot.createElement("div");
            item.className = "combat-effect";
            const name = documentRoot.createElement("span");
            name.className = "combat-effect-name";
            name.textContent = effect.name || effect.key;
            const bar = documentRoot.createElement("span");
            bar.className = "combat-progress combat-effect-bar";
            bar.setAttribute("role", "progressbar");
            bar.setAttribute("aria-valuenow", String(effect.durationProgressPercent));
            const fill = documentRoot.createElement("span");
            fill.className = "combat-progress-fill";
            fill.style.width = String(effect.durationProgressPercent) + "%";
            bar.appendChild(fill);
            item.appendChild(name);
            item.appendChild(bar);

            return item;
        });
        container.replaceChildren(...entries);
    }

    function renderCombatHud(documentRoot, state, pending, wallNowMs) {
        const view = buildPresentation(state, wallNowMs);
        setText(documentRoot, "combatTurnNumber", view.turn.number);
        setText(documentRoot, "combatActionCount", view.turn.actionsRemaining);
        setProgress(
            documentRoot,
            "combatTurnBar",
            "combatTurnFill",
            view.turn.progressPercent,
        );
        setText(documentRoot, "combatChampionHp", view.champion.currentHp);
        setText(documentRoot, "combatEnemyName", view.enemy.name);
        setText(documentRoot, "combatEnemyGlyph", view.enemy.glyph);
        setText(
            documentRoot,
            "combatEnemyAction",
            view.enemy.activeActionName || "—",
        );
        setText(
            documentRoot,
            "combatEnemyHp",
            view.enemy.currentHp + "/" + view.enemy.maximumHp,
        );
        const enemyHpPercent =
            view.enemy.maximumHp > 0
                ? clamp((view.enemy.currentHp / view.enemy.maximumHp) * 100, 0, 100)
                : 0;
        setProgress(
            documentRoot,
            "combatEnemyHpBar",
            "combatEnemyHpFill",
            enemyHpPercent,
            view.enemy.currentHp,
        );
        const enemyHpBar = documentRoot.getElementById("combatEnemyHpBar");
        if (enemyHpBar) {
            enemyHpBar.setAttribute("aria-valuemax", String(view.enemy.maximumHp));
        }

        setText(documentRoot, "combatAttackName", view.attack.name);
        setText(documentRoot, "combatAttackStatus", view.attack.disabledText);
        setProgress(
            documentRoot,
            "combatCooldownBar",
            "combatCooldownFill",
            view.attack.cooldownProgressPercent,
        );
        const attackButton = documentRoot.getElementById("combatAttackButton");
        if (attackButton) {
            attackButton.disabled = !view.attack.available || pending.attack;
            attackButton.dataset.disabledReason = view.attack.disabledReason || "";
        }

        for (const control of SKILL_CONTROLS) {
            const skill = view.skills.find((candidate) => candidate.slot === control.slot);
            const button = documentRoot.getElementById(control.id + "Button");
            setText(documentRoot, control.id + "Name", skill?.name || "Empty");
            setText(
                documentRoot,
                control.id + "Status",
                skill?.disabledText || "Unavailable",
            );
            setProgress(
                documentRoot,
                control.id + "CooldownBar",
                control.id + "CooldownFill",
                skill?.cooldownProgressPercent ?? 0,
            );
            if (button) {
                button.disabled = !skill || !skill.available || pending.skill;
                button.dataset.disabledReason = skill?.disabledReason || "";
            }
        }

        const reactionLayer = documentRoot.getElementById("combatReactionLayer");
        const blockButton = documentRoot.getElementById("combatBlockButton");
        if (reactionLayer) {
            reactionLayer.hidden = !view.block.visible;
        }
        if (blockButton) {
            blockButton.disabled = !view.block.visible || pending.block;
            blockButton.style.left = String(view.block.xPercent) + "%";
            blockButton.style.top = String(view.block.yPercent) + "%";
            blockButton.setAttribute(
                "aria-label",
                view.block.name === "" ? "Block incoming attack" : "Block " + view.block.name,
            );
        }

        setText(
            documentRoot,
            "combatPotionCharges",
            view.potion.chargesRemaining + " / " + view.potion.chargeAllowance,
        );
        const potionButton = documentRoot.getElementById("combatPotionButton");
        if (potionButton) {
            potionButton.disabled = view.potion.chargesRemaining <= 0 || pending.potion;
        }

        renderEffects(documentRoot, view.effects);
        replaceMessages(
            documentRoot,
            "combatBattleEvents",
            view.events,
            "The battle is underway.",
            "game-log-entry",
        );
        const playerHp = documentRoot.getElementById("playerHp");
        const playerHpBar = documentRoot.getElementById("playerHpBar");
        const playerHpFill = documentRoot.getElementById("playerHpFill");
        const maximumHp = Number(playerHpBar?.getAttribute?.("aria-valuemax"));
        if (playerHp) {
            playerHp.textContent = Number.isFinite(maximumHp) && maximumHp > 0
                ? view.champion.currentHp + "/" + maximumHp
                : String(view.champion.currentHp);
        }
        if (playerHpFill && Number.isFinite(maximumHp) && maximumHp > 0) {
            playerHpFill.style.width =
                String(clamp((view.champion.currentHp / maximumHp) * 100, 0, 100)) + "%";
        }
        if (playerHpBar) {
            playerHpBar.setAttribute("aria-valuenow", String(view.champion.currentHp));
        }

        return view;
    }

    function showMessage(documentRoot, message, type) {
        const element = documentRoot.getElementById("combatMessage");
        if (!element) {
            return;
        }
        element.textContent = message;
        element.dataset.messageType = type;
        element.hidden = message === "";
    }

    function renderInterpolatedProgress(documentRoot, state, wallNowMs) {
        const view = buildPresentation(state, wallNowMs);
        setProgress(
            documentRoot,
            "combatTurnBar",
            "combatTurnFill",
            view.turn.progressPercent,
        );
        setProgress(
            documentRoot,
            "combatCooldownBar",
            "combatCooldownFill",
            view.attack.cooldownProgressPercent,
        );
        for (const control of SKILL_CONTROLS) {
            const skill = view.skills.find((candidate) => candidate.slot === control.slot);
            setProgress(
                documentRoot,
                control.id + "CooldownBar",
                control.id + "CooldownFill",
                skill?.cooldownProgressPercent ?? 0,
            );
        }
        renderEffects(documentRoot, view.effects);
    }

    function initializeCombatHud(documentRoot, gameState, options) {
        const rootElement = documentRoot.getElementById("battleHud");
        if (!rootElement || gameState?.mode !== "combat" || !gameState.combat) {
            return null;
        }

        const now = typeof options.now === "function" ? options.now : () => Date.now();
        let pending = {
            attack: false,
            skill: false,
            block: false,
            potion: false,
            refresh: false,
        };
        let controller;
        const paint = function () {
            renderCombatHud(documentRoot, controller.state(), pending, now());
        };
        controller = createCombatController({
            csrfToken: gameState.csrfToken,
            fetchImplementation: options.fetchImplementation,
            initialState: gameState.combat,
            now,
            requestTokenFactory: options.requestTokenFactory,
            onState(state) {
                gameState.combat = state;
                showMessage(documentRoot, "", "");
                paint();
            },
            onPending(nextPending) {
                pending = nextPending;
                paint();
            },
            onError(message) {
                showMessage(documentRoot, message, "error");
            },
        });

        documentRoot.getElementById("combatAttackButton")?.addEventListener(
            "click",
            () => controller.attack(),
        );
        documentRoot.getElementById("combatBlockButton")?.addEventListener(
            "click",
            () => controller.block(),
        );
        for (const control of SKILL_CONTROLS) {
            documentRoot.getElementById(control.id + "Button")?.addEventListener(
                "click",
                () => controller.skill(control.slot),
            );
        }
        documentRoot.getElementById("combatPotionButton")?.addEventListener(
            "click",
            () => controller.potion(),
        );
        paint();

        if (typeof options.schedulePoll === "function") {
            options.schedulePoll(() => controller.refresh(), 1000);
        }
        if (typeof options.scheduleFrame === "function") {
            const animate = function () {
                renderInterpolatedProgress(
                    documentRoot,
                    controller.state(),
                    now(),
                );
                options.scheduleFrame(animate);
            };
            options.scheduleFrame(animate);
        }

        return controller;
    }

    return {
        buildPresentation,
        createRequestToken,
        createCombatController,
        disabledReasonText,
        initializeCombatHud,
        interpolatedTimeline,
        progressPercent,
        renderCombatHud,
        skillDisabledReasonText,
    };
});
