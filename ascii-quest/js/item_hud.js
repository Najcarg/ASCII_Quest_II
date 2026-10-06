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
        root.ASCIIQuestInventoryController = itemHud.createController({
            document: root.document,
            initialState,
            fetchImplementation: typeof root.fetch === "function" ? root.fetch.bind(root) : null,
            csrfToken: root.ASCII_QUEST_STATE?.csrfToken || "",
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
    const EQUIPMENT_ERROR_MESSAGE = "Unable to change equipment. Please try again.";
    const EQUIPMENT_LABELS = { helm: "Helm", gloves: "Gloves", chest: "Chest", ring: "Ring", weapon: "Weapon", "off-hand": "Off-Hand", amulet: "Amulet", belt: "Belt", charm: "Charm", boots: "Boots" };

    function byId(document, id) {
        return document.getElementById(id);
    }

    function capitalize(value) {
        const text = String(value || "");
        return text === "" ? "" : text[0].toUpperCase() + text.slice(1);
    }

    function updateChampion(document, champion) {
        if (!champion || !champion.stats || !champion.stats.resources) return;
        const currentHp = Number(champion.current_hp);
        const currentMana = Number(champion.current_mana);
        const maximumLife = Number(champion.stats.resources.max_life);
        const maximumMana = Number(champion.stats.resources.max_mana);
        const updateResource = function (valueId, barId, fillId, current, maximum) {
            if (!Number.isFinite(current) || !Number.isFinite(maximum) || maximum < 0) return;
            const value = byId(document, valueId);
            const bar = byId(document, barId);
            const fill = byId(document, fillId);
            if (value) value.textContent = current + "/" + maximum;
            if (bar) {
                bar.setAttribute("aria-valuenow", String(current));
                bar.setAttribute("aria-valuemax", String(maximum));
            }
            if (fill) fill.style.width = String(maximum > 0 ? Math.max(0, Math.min(100, (current / maximum) * 100)) : 0) + "%";
        };
        updateResource("playerHp", "playerHpBar", "playerHpFill", currentHp, maximumLife);
        updateResource("playerMana", "playerManaBar", "playerManaFill", currentMana, maximumMana);

        for (const node of document.querySelectorAll("[data-character-stat-path]")) {
            const path = String(node.dataset.characterStatPath || "").split(".");
            let value = champion.stats;
            for (const key of path) value = value?.[key];
            if (value === undefined) continue;
            node.textContent = node.dataset.characterStatFormat === "rate"
                ? Number(value).toFixed(2)
                : node.dataset.characterStatFormat === "percentage"
                    ? String(value) + "%"
                    : String(value);
        }
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

        const equipment = state?.equipment || {};
        for (const slot of document.querySelectorAll("[data-equipment-slot]")) {
            const slotKey = String(slot.dataset.equipmentSlot || "");
            const item = equipment[slotKey] || null;
            slot.classList.remove(...RARITY_CLASSES, "is-selected");
            delete slot.dataset.itemId;
            slot.textContent = EQUIPMENT_LABELS[slotKey] || slotKey;
            if (item) {
                const rarity = RARITIES.has(item.rarity) ? item.rarity : "normal";
                slot.dataset.itemId = String(item.id);
                slot.textContent = String(item.glyph || "?") + " " + String(item.display_name || "Unknown item");
                slot.classList.add("inventory-rarity-" + rarity);
            }
        }
        const lockReason = byId(document, "equipmentLockReason");
        if (lockReason) {
            lockReason.textContent = state?.mutation_disabled_reason === "champion_dead"
                ? "A DEAD Champion cannot change equipment."
                : state?.mutation_disabled_reason === "victory_loot_pending"
                    ? "Equipment cannot change while victory loot is open."
                    : state?.mutation_disabled_reason === "combat_active"
                        ? "Equipment cannot change during combat."
                        : "";
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
        const affixLines = Array.isArray(item.stat_lines) ? item.stat_lines : [];
        byId(document, "inventoryDetailStats").textContent = [statLine, ...affixLines].join(" · ");
    }

    function createController(options) {
        const document = options.document;
        const fetchImplementation = options.fetchImplementation || null;
        const endpoint = options.endpoint || "inventory_state.php";
        const equipEndpoint = options.equipEndpoint || "equip_item.php";
        const unequipEndpoint = options.unequipEndpoint || "unequip_item.php";
        const csrfToken = options.csrfToken || "";
        const uuidFactory = options.uuidFactory || function () {
            if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
            const bytes = new Uint8Array(16); globalThis.crypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
            return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("").replace(/^(........)(....)(....)(....)(............)$/, "$1-$2-$3-$4-$5");
        };
        let currentState = options.initialState || { items: [], pagination: {} };
        let selectedId = null;
        let selectedItem = null;
        let pending = false;
        let mutation = null;

        function allItems(state) {
            return validItems(state).concat(Object.values(state?.equipment || {}).filter(Boolean));
        }

        function updateAction() {
            const button = byId(document, "equipmentAction");
            if (!button) return;
            button.hidden = !selectedItem;
            button.textContent = selectedItem?.equipped ? "Unequip" : (currentState?.equipment?.[selectedItem?.equipment_slot] ? "Swap" : "Equip");
            button.disabled = !selectedItem || pending || currentState?.equipment_locked === true;
        }

        function applyState(nextState) {
            currentState = nextState || { items: [], pagination: {} };
            if (!allItems(currentState).some((item) => Number(item.id) === selectedId)) {
                selectedId = null;
                selectedItem = null;
                renderDetails(document, null);
            }
            render(document, currentState, pending);
            updateAction();
        }

        function select(itemId) {
            const item = allItems(currentState).find((candidate) => Number(candidate.id) === itemId) || null;
            selectedId = item ? Number(item.id) : null;
            selectedItem = item;
            mutation = null;
            render(document, currentState, pending);
            for (const cell of document.querySelectorAll("[data-inventory-slot]")) {
                if (Number(cell.dataset.itemId) === selectedId) {
                    cell.classList.add("is-selected");
                }
            }
            for (const slot of document.querySelectorAll("[data-equipment-slot]")) {
                if (Number(slot.dataset.itemId) === selectedId) slot.classList.add("is-selected");
            }
            renderDetails(document, item);
            updateAction();
        }

        async function mutateEquipment() {
            if (!selectedItem || pending || currentState?.equipment_locked === true || typeof fetchImplementation !== "function") return;
            const command = selectedItem.equipped ? "unequip" : "equip";
            if (!mutation || mutation.command !== command || mutation.itemId !== Number(selectedItem.id)) {
                mutation = { command, itemId: Number(selectedItem.id), slot: selectedItem.equipment_slot, token: uuidFactory() };
            }
            pending = true; updateAction();
            const body = { csrf_token: csrfToken, item_id: mutation.itemId, request_token: mutation.token };
            if (command === "equip") body.slot = mutation.slot;
            try {
                const response = await fetchImplementation(command === "equip" ? equipEndpoint : unequipEndpoint, {
                    method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" },
                    cache: "no-store", body: JSON.stringify(body),
                });
                const payload = await response.json();
                if (!response.ok || !payload?.state) throw new Error("Invalid equipment response.");
                updateChampion(document, payload.champion);
                pending = false; mutation = null; selectedId = null; selectedItem = null; renderDetails(document, null); applyState(payload.state);
            } catch (error) {
                pending = false; render(document, currentState, pending); updateAction();
                const message = byId(document, "inventoryMessage"); if (message) message.textContent = EQUIPMENT_ERROR_MESSAGE;
            }
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
        for (const slot of document.querySelectorAll("[data-equipment-slot]")) {
            slot.addEventListener("click", function () {
                const itemId = Number(slot.dataset.itemId);
                if (Number.isSafeInteger(itemId) && itemId > 0) select(itemId);
            });
        }
        byId(document, "equipmentAction")?.addEventListener("click", mutateEquipment);
        byId(document, "inventoryPrevious")?.addEventListener("click", function () {
            return loadPage((Number(currentState?.pagination?.page) || 1) - 1);
        });
        byId(document, "inventoryNext")?.addEventListener("click", function () {
            return loadPage((Number(currentState?.pagination?.page) || 1) + 1);
        });

        applyState(currentState);
        return {
            applyState,
            refresh() {
                return loadPage(Number(currentState?.pagination?.page) || 1);
            },
            selectedItemId() { return selectedId; },
        };
    }

    return { render, createController };
});
