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
            );
        });
    } else {
        hud.initialize(
            root.document,
            typeof root.fetch === "function" ? root.fetch.bind(root) : null,
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

        const statPoints = documentRoot.getElementById("detailStatPoints");
        if (statPoints) {
            statPoints.textContent = String(allocationState.stat_points);
        }

        Object.entries(allocationState.stats.main).forEach(function (entry) {
            const statValue = documentRoot.getElementById(
                "detailStat-" + entry[0],
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
            "detailAllocationMessage",
        );

        if (!messageElement) {
            return;
        }

        messageElement.textContent = message;
        messageElement.dataset.messageType = type;
        messageElement.hidden = message === "";
    }

    function initializeStatAllocation(documentRoot, fetchImplementation) {
        const detailsPanel = documentRoot.getElementById("left-details");
        const statPoints = documentRoot.getElementById("detailStatPoints");
        const buttons = Array.from(
            documentRoot.querySelectorAll("[data-stat-allocate]"),
        );

        if (
            !detailsPanel ||
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
                    detailsPanel.dataset.characterId,
                );
                requestBody.set("csrf_token", detailsPanel.dataset.csrfToken);
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
                    showAllocationMessage(
                        documentRoot,
                        result.message || "Stat point allocated.",
                        "success",
                    );
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

    function initialize(documentRoot, fetchImplementation) {
        initializeTabs(documentRoot);
        initializeStatAllocation(documentRoot, fetchImplementation);
    }

    return {
        activateTab,
        applyAllocationState,
        initialize,
        initializeStatAllocation,
        initializeTabGroup,
        initializeTabs,
        synchronizeResourceDisplay,
        updateResourceBar,
    };
});
