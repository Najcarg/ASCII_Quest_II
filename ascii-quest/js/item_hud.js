(function (root, factory) {
    "use strict";

    const itemHud = factory();
    if (typeof module === "object" && module.exports) {
        module.exports = itemHud;
    }
    if (!root) {
        return;
    }
    root.ASCIIQuestItemHud = itemHud;

    function initialize() {
        const initialState = root.ASCII_QUEST_STATE?.inventory;
        if (!initialState) {
            return;
        }
        itemHud.createController({
            document: root.document,
            initialState,
            fetchImplementation: typeof root.fetch === "function" ? root.fetch.bind(root) : null,
        });
    }

    if (root.document.readyState === "loading") {
        root.document.addEventListener("DOMContentLoaded", initialize);
    } else {
        initialize();
    }
})(typeof window === "undefined" ? null : window, function () {
    "use strict";

    const RARITIES = new Set(["normal", "magic", "rare"]);
    const RARITY_CLASSES = [
        "inventory-rarity-normal",
        "inventory-rarity-magic",
        "inventory-rarity-rare",
    ];
    const ERROR_MESSAGE = "Unable to load inventory. Please try again.";

    function byId(document, id) {
        return document.getElementById(id);
    }

    function capitalize(value) {
        const text = String(value || "");
        return text === "" ? "" : text[0].toUpperCase() + text.slice(1);
    }

    function validItems(state) {
        return Array.isArray(state?.items) ? state.items.slice(0, 25) : [];
    }

    function render(document, state, pending = false) {
        const cells = Array.from(document.querySelectorAll("[data-inventory-slot]"));
        const items = validItems(state);
        cells.forEach(function (cell, index) {
            const item = items[index];
            cell.classList.remove(...RARITY_CLASSES, "is-selected");
            delete cell.dataset.itemId;
            cell.textContent = "";
            cell.setAttribute("aria-label", "Empty inventory slot " + (index + 1));
            if (!item) {
                return;
            }
            const rarity = RARITIES.has(item.rarity) ? item.rarity : "normal";
            cell.dataset.itemId = String(item.id);
            cell.textContent = String(item.glyph || "?") + " " + String(item.display_name || "Unknown item");
            cell.classList.add("inventory-rarity-" + rarity);
            cell.setAttribute("aria-label", String(item.display_name || "Unknown item"));
        });

        const empty = byId(document, "inventoryEmpty");
        if (empty) {
            empty.hidden = items.length !== 0;
            empty.textContent = items.length === 0 ? "Inventory is empty." : "";
        }
        const message = byId(document, "inventoryMessage");
        if (message) {
            message.textContent = "";
        }

        const pagination = state?.pagination || {};
        const page = Number(pagination.page) || 1;
        const totalPages = Math.max(1, Number(pagination.total_pages) || 1);
        const label = byId(document, "inventoryPageLabel");
        if (label) {
            label.textContent = "Page " + page + " of " + totalPages;
        }
        const previous = byId(document, "inventoryPrevious");
        const next = byId(document, "inventoryNext");
        if (previous) {
            previous.disabled = pending || pagination.has_previous !== true;
        }
        if (next) {
            next.disabled = pending || pagination.has_next !== true;
        }
    }

    function renderDetails(document, item) {
        const details = byId(document, "inventoryDetails");
        if (!details) {
            return;
        }
        details.hidden = !item;
        if (!item) {
            return;
        }
        byId(document, "inventoryDetailName").textContent = String(item.display_name || "Unknown item");
        byId(document, "inventoryDetailMeta").textContent = [
            capitalize(item.rarity),
            "Item level " + Number(item.item_level || 0),
            capitalize(item.base_type),
        ].join(" · ");
        const stats = item.base_stats || {};
        let statLine = "No base stat bonus";
        if (Number(stats.damage_max) > 0) {
            statLine = capitalize(stats.damage_type) + " damage " + Number(stats.damage_min) + "–" + Number(stats.damage_max);
        } else if (Number(stats.toughness) > 0) {
            statLine = "Toughness +" + Number(stats.toughness);
        }
        byId(document, "inventoryDetailStats").textContent = statLine;
    }

    function createController(options) {
        const document = options.document;
        const fetchImplementation = options.fetchImplementation || null;
        const endpoint = options.endpoint || "inventory_state.php";
        let currentState = options.initialState || { items: [], pagination: {} };
        let selectedId = null;
        let pending = false;

        function applyState(nextState) {
            currentState = nextState || { items: [], pagination: {} };
            if (!validItems(currentState).some((item) => Number(item.id) === selectedId)) {
                selectedId = null;
                renderDetails(document, null);
            }
            render(document, currentState, pending);
        }

        function select(itemId) {
            const item = validItems(currentState).find((candidate) => Number(candidate.id) === itemId) || null;
            selectedId = item ? Number(item.id) : null;
            render(document, currentState, pending);
            for (const cell of document.querySelectorAll("[data-inventory-slot]")) {
                if (Number(cell.dataset.itemId) === selectedId) {
                    cell.classList.add("is-selected");
                }
            }
            renderDetails(document, item);
        }

        async function loadPage(page) {
            if (pending || typeof fetchImplementation !== "function") {
                return;
            }
            pending = true;
            render(document, currentState, pending);
            try {
                const response = await fetchImplementation(endpoint + "?page=" + page, {
                    method: "GET",
                    headers: { Accept: "application/json" },
                    cache: "no-store",
                });
                const payload = await response.json();
                if (!response.ok || !payload || !Array.isArray(payload.items)) {
                    throw new Error("Invalid inventory response.");
                }
                pending = false;
                applyState(payload);
            } catch (error) {
                pending = false;
                render(document, currentState, pending);
                const message = byId(document, "inventoryMessage");
                if (message) {
                    message.textContent = ERROR_MESSAGE;
                }
            }
        }

        for (const cell of document.querySelectorAll("[data-inventory-slot]")) {
            cell.addEventListener("click", function () {
                const itemId = Number(cell.dataset.itemId);
                if (Number.isSafeInteger(itemId) && itemId > 0) {
                    select(itemId);
                }
            });
        }
        byId(document, "inventoryPrevious")?.addEventListener("click", function () {
            return loadPage((Number(currentState?.pagination?.page) || 1) - 1);
        });
        byId(document, "inventoryNext")?.addEventListener("click", function () {
            return loadPage((Number(currentState?.pagination?.page) || 1) + 1);
        });

        applyState(currentState);
        return {
            applyState,
            selectedItemId() { return selectedId; },
        };
    }

    return { render, createController };
});
