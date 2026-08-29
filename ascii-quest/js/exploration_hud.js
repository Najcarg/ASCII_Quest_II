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
            hud.initializeTabs(root.document);
        });
    } else {
        hud.initializeTabs(root.document);
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

    return {
        activateTab,
        initializeTabGroup,
        initializeTabs,
        updateResourceBar,
    };
});
