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
};

let failures = 0;

for (const [name, test] of Object.entries(tests)) {
    try {
        test();
        console.log(`[PASS] ${name}`);
    } catch (error) {
        failures++;
        console.error(`[FAIL] ${name}: ${error.message}`);
    }
}

console.log(`\n${Object.keys(tests).length - failures} passed`);
console.log(`${failures} failed`);

process.exit(failures === 0 ? 0 : 1);
