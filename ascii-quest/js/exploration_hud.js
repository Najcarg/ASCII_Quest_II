(function (root, factory) {
    "use strict";

    const hud = factory();

    if (typeof module === "object" && module.exports) {
        module.exports = hud;
    }

    if (!root) {
        return;
    }

    root.ASCIIQuestHud = hud;

    if (root.document.readyState === "loading") {
        root.document.addEventListener("DOMContentLoaded", function () {
            hud.initialize(
                root.document,
                typeof root.fetch === "function" ? root.fetch.bind(root) : null,
                function () {
                    root.location.reload();
                },
            );
        });
    } else {
        hud.initialize(
            root.document,
            typeof root.fetch === "function" ? root.fetch.bind(root) : null,
            function () {
                root.location.reload();
            },
        );
    }
})(typeof window === "undefined" ? null : window, function () {
    "use strict";

    function activateTab(buttons, panels, targetId) {
        buttons.forEach(function (button) {
            const isActive = button.dataset.tabTarget === targetId;

            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-selected", String(isActive));
        });

        panels.forEach(function (panel) {
            panel.hidden = panel.id !== targetId;
        });
    }

    function initializeTabGroup(group) {
        const buttons = Array.from(
            group.querySelectorAll("[data-tab-target]"),
        );
        const panels = Array.from(group.querySelectorAll("[data-tab-panel]"));

        if (buttons.length === 0 || panels.length === 0) {
            return;
        }

        const activeButton =
            buttons.find(function (button) {
                return button.classList.contains("is-active");
            }) || buttons[0];

        activateTab(buttons, panels, activeButton.dataset.tabTarget);

        buttons.forEach(function (button) {
            button.addEventListener("click", function () {
                activateTab(buttons, panels, button.dataset.tabTarget);
            });
        });
    }

    function initializeTabs(documentRoot) {
        documentRoot
            .querySelectorAll("[data-tab-group]")
            .forEach(initializeTabGroup);
    }

    function updateResourceBar(bar, fill, currentValue, maximumValue) {
        if (!bar || !fill) {
            return;
        }

        const current = Number(currentValue);
        const maximum = Number(maximumValue);
        const safeCurrent = Number.isFinite(current) ? Math.max(0, current) : 0;
        const safeMaximum =
            Number.isFinite(maximum) && maximum > 0 ? maximum : 1;
        const percentage = Math.max(
            0,
            Math.min(100, (safeCurrent / safeMaximum) * 100),
        );

        fill.style.width = String(percentage) + "%";
        bar.setAttribute("aria-valuenow", String(safeCurrent));
        bar.setAttribute("aria-valuemax", String(safeMaximum));
    }

    function synchronizeResourceDisplay(
        documentRoot,
        resourceName,
        currentValue,
        maximumValue,
    ) {
        const value = documentRoot.getElementById("player" + resourceName);
        const bar = documentRoot.getElementById(
            "player" + resourceName + "Bar",
        );
        const fill = documentRoot.getElementById(
            "player" + resourceName + "Fill",
        );

        if (!value || !bar || !fill) {
            return false;
        }

        value.textContent = String(currentValue) + "/" + String(maximumValue);
        updateResourceBar(bar, fill, currentValue, maximumValue);

        return true;
    }

    function readCharacterStat(stats, path) {
        return path.split(".").reduce(function (value, key) {
            if (value === null || value === undefined) {
                return undefined;
            }

            return value[key];
        }, stats);
    }

    function formatCharacterStat(value, format) {
        if (format === "percentage") {
            return String(value) + "%";
        }

        if (format === "rate") {
            const rate = Number(value);
            return Number.isFinite(rate) ? rate.toFixed(2) : String(value);
        }

        return String(value);
    }

    function applyAllocationState(documentRoot, allocationState) {
        if (!allocationState || !allocationState.stats) {
            return false;
        }

        const statPoints = documentRoot.getElementById("mainStatPoints");
        if (statPoints) {
            statPoints.textContent = String(allocationState.stat_points);
        }

        Object.entries(allocationState.stats.main).forEach(function (entry) {
            const statValue = documentRoot.getElementById(
                "mainStat-" + entry[0],
            );

            if (statValue) {
                statValue.textContent = String(entry[1]);
            }
        });

        documentRoot
            .querySelectorAll("[data-character-stat-path]")
            .forEach(function (element) {
                const value = readCharacterStat(
                    allocationState.stats,
                    element.dataset.characterStatPath,
                );

                if (value !== undefined) {
                    element.textContent = formatCharacterStat(
                        value,
                        element.dataset.characterStatFormat,
                    );
                }
            });

        synchronizeResourceDisplay(
            documentRoot,
            "Hp",
            allocationState.current_hp,
            allocationState.stats.resources.max_life,
        );
        synchronizeResourceDisplay(
            documentRoot,
            "Mana",
            allocationState.current_mana,
            allocationState.stats.resources.max_mana,
        );

        const noPointsRemaining = Number(allocationState.stat_points) <= 0;
        documentRoot
            .querySelectorAll("[data-stat-allocate]")
            .forEach(function (button) {
                button.disabled = noPointsRemaining;
            });

        return true;
    }

    function setAllocationButtonsDisabled(documentRoot, disabled) {
        documentRoot
            .querySelectorAll("[data-stat-allocate]")
            .forEach(function (button) {
                button.disabled = disabled;
            });
    }

    function showAllocationMessage(documentRoot, message, type) {
        const messageElement = documentRoot.getElementById(
            "mainAllocationMessage",
        );

        if (!messageElement) {
            return;
        }

        messageElement.textContent = message;
        messageElement.dataset.messageType = type;
        messageElement.hidden = message === "";
    }

    function initializeStatAllocation(documentRoot, fetchImplementation) {
        const mainPanel = documentRoot.getElementById("left-main");
        const statPoints = documentRoot.getElementById("mainStatPoints");
        const buttons = Array.from(
            documentRoot.querySelectorAll("[data-stat-allocate]"),
        );

        if (
            !mainPanel ||
            !statPoints ||
            buttons.length === 0 ||
            typeof fetchImplementation !== "function"
        ) {
            return;
        }

        let isAllocating = false;

        setAllocationButtonsDisabled(
            documentRoot,
            Number(statPoints.textContent) <= 0,
        );

        buttons.forEach(function (button) {
            button.addEventListener("click", async function () {
                if (isAllocating || Number(statPoints.textContent) <= 0) {
                    return;
                }

                isAllocating = true;
                setAllocationButtonsDisabled(documentRoot, true);
                showAllocationMessage(documentRoot, "", "");

                const requestBody = new URLSearchParams();
                requestBody.set(
                    "character_id",
                    mainPanel.dataset.characterId,
                );
                requestBody.set("csrf_token", mainPanel.dataset.csrfToken);
                requestBody.set("stat", button.dataset.statAllocate);

                let errorMessage =
                    "Unable to allocate the stat point. Please try again.";

                try {
                    const response = await fetchImplementation(
                        "allocate_stat.php",
                        {
                            method: "POST",
                            headers: { Accept: "application/json" },
                            body: requestBody,
                        },
                    );
                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        if (typeof result.message === "string") {
                            errorMessage = result.message;
                        }

                        throw new Error("Allocation rejected by server.");
                    }

                    applyAllocationState(documentRoot, result.character);
                    showAllocationMessage(documentRoot, "", "");
                } catch (error) {
                    showAllocationMessage(
                        documentRoot,
                        errorMessage,
                        "error",
                    );
                } finally {
                    isAllocating = false;
                    setAllocationButtonsDisabled(
                        documentRoot,
                        Number(statPoints.textContent) <= 0,
                    );
                }
            });
        });
    }

    function buildWarpDestinationView(destinations, currentGold) {
        const gold = Number(currentGold);
        const safeGold = Number.isFinite(gold) ? gold : 0;

        return (Array.isArray(destinations) ? destinations : []).map(
            function (destination) {
                const cost = Math.max(0, Number(destination.cost) || 0);
                let action = "travel";
                let actionLabel = "WARP";
                let disabled = false;

                if (destination.current_location === true) {
                    action = "current";
                    actionLabel = "CURRENT LOCATION";
                    disabled = true;
                } else if (safeGold < cost) {
                    action = "insufficient";
                    actionLabel = "NOT ENOUGH GOLD";
                    disabled = true;
                }

                return {
                    id: String(destination.id),
                    name: String(destination.name),
                    cost,
                    action,
                    actionLabel,
                    disabled,
                };
            },
        );
    }

    function createWarpTravelController(options) {
        let selectedDestination = null;
        let pending = false;

        function notify(name, value) {
            if (typeof options[name] === "function") {
                options[name](value);
            }
        }

        return {
            select(destination) {
                if (!destination || destination.action !== "travel" || pending) {
                    return false;
                }

                selectedDestination = destination;
                notify("onConfirmation", selectedDestination);
                return true;
            },

            cancel() {
                if (pending) {
                    return false;
                }

                selectedDestination = null;
                notify("onConfirmation", null);
                return true;
            },

            async confirm() {
                if (
                    pending ||
                    selectedDestination === null ||
                    typeof options.fetchImplementation !== "function"
                ) {
                    return false;
                }

                pending = true;
                notify("onPending", true);

                const requestBody = new URLSearchParams();
                requestBody.set("csrf_token", String(options.csrfToken || ""));
                requestBody.set("warp_id", selectedDestination.id);
                let errorMessage = "Unable to use that Warp. Please try again.";

                try {
                    const response = await options.fetchImplementation(
                        "travel_warp.php",
                        {
                            method: "POST",
                            headers: { Accept: "application/json" },
                            body: requestBody,
                        },
                    );
                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        if (typeof result.message === "string") {
                            errorMessage = result.message;
                        }
                        throw new Error("Warp travel rejected by server.");
                    }

                    selectedDestination = null;
                    notify("onConfirmation", null);
                    notify("onTravel", result);
                    return true;
                } catch (error) {
                    notify("onError", errorMessage);
                    return false;
                } finally {
                    pending = false;
                    notify("onPending", false);
                }
            },
        };
    }

    function showWarpMessage(documentRoot, message, type) {
        const element = documentRoot.getElementById("warpMessage");
        if (!element) {
            return;
        }

        element.textContent = message;
        element.dataset.messageType = type;
        element.hidden = message === "";
    }

    function renderWarpDestinations(documentRoot, destinations, currentGold) {
        const container = documentRoot.getElementById("warpDestinationList");
        if (!container) {
            return false;
        }

        const views = buildWarpDestinationView(destinations, currentGold);
        container.innerHTML = "";

        if (views.length === 0) {
            const empty = documentRoot.createElement("p");
            empty.className = "warp-empty";
            empty.textContent = "No Warp destinations discovered.";
            container.appendChild(empty);
            return true;
        }

        views.forEach(function (destination) {
            const card = documentRoot.createElement("article");
            card.className = "warp-destination";

            const name = documentRoot.createElement("h3");
            name.textContent = destination.name;
            card.appendChild(name);

            const cost = documentRoot.createElement("p");
            cost.textContent = "Cost: " + destination.cost + " Gold";
            card.appendChild(cost);

            const action = documentRoot.createElement("button");
            action.type = "button";
            action.className = "warp-action warp-action-" + destination.action;
            action.textContent = destination.actionLabel;
            action.disabled = destination.disabled;
            if (destination.action === "travel") {
                action.dataset.warpTravel = destination.id;
                action.dataset.warpName = destination.name;
                action.dataset.warpCost = String(destination.cost);
            }
            card.appendChild(action);
            container.appendChild(card);
        });

        return true;
    }

    function updateWarpDestinations(documentRoot, destinations, currentGold) {
        const panel = documentRoot.getElementById("left-warp");
        if (!panel) {
            return false;
        }

        panel.dataset.destinations = JSON.stringify(destinations || []);
        panel.dataset.currentGold = String(currentGold);
        return renderWarpDestinations(documentRoot, destinations, currentGold);
    }

    function applyWarpUnlockState(result, render) {
        if (
            !result ||
            !Array.isArray(result.destinations) ||
            typeof render !== "function"
        ) {
            return false;
        }

        const gold = result.character_updates?.gold;
        render(result.destinations, gold);
        return true;
    }

    function applyWarpGoldState(
        documentRoot,
        currentGold,
        renderImplementation,
    ) {
        const panel = documentRoot.getElementById("left-warp");
        if (!panel) {
            return false;
        }

        let destinations;
        try {
            destinations = JSON.parse(panel.dataset.destinations || "[]");
        } catch (error) {
            return false;
        }

        panel.dataset.currentGold = String(currentGold);
        if (typeof renderImplementation === "function") {
            renderImplementation(destinations, currentGold);
        } else {
            renderWarpDestinations(documentRoot, destinations, currentGold);
        }

        return true;
    }

    function setWarpTravelPending(documentRoot, isPending) {
        const panel = documentRoot.getElementById("left-warp");
        if (!panel) {
            return false;
        }

        panel.dataset.warpPending = String(isPending === true);
        return true;
    }

    function initializeWarpTravel(
        documentRoot,
        fetchImplementation,
        reloadImplementation,
    ) {
        const panel = documentRoot.getElementById("left-warp");
        const confirmation = documentRoot.getElementById("warpConfirmation");
        const confirmationText = documentRoot.getElementById(
            "warpConfirmationText",
        );
        const confirmButton = documentRoot.getElementById("warpConfirmButton");
        const cancelButton = documentRoot.getElementById("warpCancelButton");
        if (!panel || !confirmation || !confirmButton || !cancelButton) {
            return;
        }

        let destinations = [];
        try {
            destinations = JSON.parse(panel.dataset.destinations || "[]");
        } catch (error) {
            showWarpMessage(documentRoot, "Unable to load Warp destinations.", "error");
        }

        renderWarpDestinations(
            documentRoot,
            destinations,
            panel.dataset.currentGold,
        );

        const controller = createWarpTravelController({
            csrfToken: panel.dataset.csrfToken,
            fetchImplementation,
            onConfirmation(destination) {
                confirmation.hidden = destination === null;
                if (destination !== null && confirmationText) {
                    confirmationText.textContent =
                        "Warp to " +
                        destination.name +
                        " for " +
                        destination.cost +
                        " Gold?";
                }
            },
            onPending(isPending) {
                setWarpTravelPending(documentRoot, isPending);
                confirmButton.disabled = isPending;
                cancelButton.disabled = isPending;
            },
            onError(message) {
                showWarpMessage(documentRoot, message, "error");
            },
            onTravel(result) {
                const goldElement = documentRoot.getElementById("playerGold");
                if (result.character_updates?.gold !== undefined && goldElement) {
                    goldElement.textContent = String(
                        result.character_updates.gold,
                    );
                }
                showWarpMessage(documentRoot, result.message || "Warp complete.", "success");

                if (result.reload && typeof reloadImplementation === "function") {
                    reloadImplementation();
                }
            },
        });

        panel.addEventListener("click", function (event) {
            const button = event.target.closest("[data-warp-travel]");
            if (!button) {
                return;
            }

            showWarpMessage(documentRoot, "", "");
            controller.select({
                id: button.dataset.warpTravel,
                name: button.dataset.warpName,
                cost: Number(button.dataset.warpCost),
                action: "travel",
            });
        });
        confirmButton.addEventListener("click", function () {
            return controller.confirm();
        });
        cancelButton.addEventListener("click", function () {
            controller.cancel();
        });
    }

    function initialize(
        documentRoot,
        fetchImplementation,
        reloadImplementation,
    ) {
        initializeTabs(documentRoot);
        initializeStatAllocation(documentRoot, fetchImplementation);
        initializeWarpTravel(
            documentRoot,
            fetchImplementation,
            reloadImplementation,
        );
    }

    return {
        activateTab,
        applyAllocationState,
        applyWarpGoldState,
        applyWarpUnlockState,
        buildWarpDestinationView,
        createWarpTravelController,
        initialize,
        initializeStatAllocation,
        initializeTabGroup,
        initializeTabs,
        initializeWarpTravel,
        renderWarpDestinations,
        setWarpTravelPending,
        synchronizeResourceDisplay,
        updateResourceBar,
        updateWarpDestinations,
    };
});
