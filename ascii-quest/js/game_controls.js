/*
|--------------------------------------------------------------------------
| ASCII Quest - Game Controls
|--------------------------------------------------------------------------
| Purpose:
|   Controls map rendering, movement, interaction key and mouse movement.
|
| This file is normal JavaScript for now.
| Later we can convert it to TypeScript.
|
| Data comes from game.php through:
|   window.ASCII_QUEST_STATE
*/

const gameState = window.ASCII_QUEST_STATE;

const gameMap = document.getElementById("gameMap");
const gameLogMessages = document.getElementById("gameLogMessages");
const playerPosition = document.getElementById("playerPosition");
const playerGold = document.getElementById("playerGold");
const playerHp = document.getElementById("playerHp");
const playerHpBar = document.getElementById("playerHpBar");
const playerHpFill = document.getElementById("playerHpFill");

let isMoving = false;
/*
|--------------------------------------------------------------------------
| Sync map state from server
|--------------------------------------------------------------------------
| Used for temporary map changes.
|
| Example:
|   trap changes from ^ to v
|   after 5 minutes server time, server tells browser:
|   v should become ^ again
*/
async function syncMapState() {
    try {
        const response = await fetch("sync_map_state.php", {
            method: "POST",
        });

        const result = await response.json();

        if (!result.success || !result.tile_updates) {
            return;
        }

        if (result.tile_updates.length === 0) {
            return;
        }

        result.tile_updates.forEach(function (tileUpdate) {
            setMapGlyphAt(
                Number(tileUpdate.x),
                Number(tileUpdate.y),
                tileUpdate.glyph,
            );
        });

        renderMap();
    } catch (error) {
        console.error("Map sync error:", error);
    }
}
/*
|--------------------------------------------------------------------------
| Helper: keep value inside min/max
|--------------------------------------------------------------------------
*/
function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/*
|--------------------------------------------------------------------------
| Calculate viewport position
|--------------------------------------------------------------------------
| Full map can be bigger than visible screen.
| This keeps player near centre when possible.
*/
function getViewportStart() {
    const halfWidth = Math.floor(gameState.viewportWidth / 2);
    const halfHeight = Math.floor(gameState.viewportHeight / 2);

    const maxStartX = Math.max(0, gameState.mapWidth - gameState.viewportWidth);
    const maxStartY = Math.max(
        0,
        gameState.mapHeight - gameState.viewportHeight,
    );

    return {
        x: clamp(gameState.playerX - halfWidth, 0, maxStartX),
        y: clamp(gameState.playerY - halfHeight, 0, maxStartY),
    };
}

/*
|--------------------------------------------------------------------------
| Get internal map glyph
|--------------------------------------------------------------------------
| Example:
|   # = wall
|   . = floor
|   > = stairs down
*/
function getTileGlyph(x, y) {
    if (y < 0 || y >= gameState.mapRows.length) {
        return " ";
    }

    const row = gameState.mapRows[y];

    if (x < 0 || x >= row.length) {
        return " ";
    }

    return row[x];
}

/*
|--------------------------------------------------------------------------
| Change one tile in browser map
|--------------------------------------------------------------------------
| Used when PHP returns tile updates.
| Example:
|   + closed door becomes / open door
*/
function setMapGlyphAt(x, y, glyph) {
    if (y < 0 || y >= gameState.mapRows.length) {
        return;
    }

    const row = gameState.mapRows[y];

    if (x < 0 || x >= row.length) {
        return;
    }

    gameState.mapRows[y] = row.substring(0, x) + glyph + row.substring(x + 1);
}

/*
|--------------------------------------------------------------------------
| Create one visual map cell
|--------------------------------------------------------------------------
| The map stores simple internal symbols.
| The screen shows Unicode display glyphs from SQL tile_types.
*/
function createMapCell(mapX, mapY) {
    const isPlayer = mapX === gameState.playerX && mapY === gameState.playerY;
    const tileGlyph = getTileGlyph(mapX, mapY);

    const tileInfo = gameState.tileTypes[tileGlyph] || {
        display_glyph: tileGlyph,
        css_class: "tile-unknown",
        name: "Unknown",
    };

    const cell = document.createElement("div");

    cell.dataset.mapX = String(mapX);
    cell.dataset.mapY = String(mapY);

    if (isPlayer) {
        cell.className = "map-cell tile-player tile-player-move";
        cell.textContent = gameState.playerGlyph || "@";
        cell.title = `${gameState.playerName} x${mapX} y${mapY}`;
    } else {
        cell.className = "map-cell " + (tileInfo.css_class || "tile-unknown");
        cell.textContent = tileInfo.display_glyph || tileGlyph || " ";
        cell.title = `${tileInfo.name || "Unknown"} x${mapX} y${mapY}`;
    }

    return cell;
}

/*
|--------------------------------------------------------------------------
| Render visible map viewport
|--------------------------------------------------------------------------
| Builds the new map first, then replaces the old one.
*/
function renderMap() {
    const start = getViewportStart();
    const fragment = document.createDocumentFragment();

    for (let screenY = 0; screenY < gameState.viewportHeight; screenY++) {
        for (let screenX = 0; screenX < gameState.viewportWidth; screenX++) {
            const mapX = start.x + screenX;
            const mapY = start.y + screenY;

            fragment.appendChild(createMapCell(mapX, mapY));
        }
    }

    gameMap.innerHTML = "";
    gameMap.appendChild(fragment);

    playerPosition.textContent = `${gameState.playerX}, ${gameState.playerY}`;
}

/*
|--------------------------------------------------------------------------
| Convert keyboard key to direction
|--------------------------------------------------------------------------
*/
function getDirectionFromKey(key) {
    const lowerKey = key.toLowerCase();

    if (key === "ArrowUp" || lowerKey === "w") {
        return "up";
    }

    if (key === "ArrowDown" || lowerKey === "s") {
        return "down";
    }

    if (key === "ArrowLeft" || lowerKey === "a") {
        return "left";
    }

    if (key === "ArrowRight" || lowerKey === "d") {
        return "right";
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Add message to message log
|--------------------------------------------------------------------------
*/
function addLogMessage(message, type = "info") {
    const entry = document.createElement("div");

    entry.className = "game-log-entry game-log-" + type;
    entry.textContent = message;

    gameLogMessages.appendChild(entry);

    while (gameLogMessages.children.length > 8) {
        gameLogMessages.removeChild(gameLogMessages.firstElementChild);
    }

    gameLogMessages.scrollTop = gameLogMessages.scrollHeight;
}
/*
|--------------------------------------------------------------------------
| Apply character updates from PHP
|--------------------------------------------------------------------------
| Used when PHP changes character stats without page reload.
|
| Examples:
|   character_updates.gold
|   character_updates.current_hp
|   character_updates.max_hp
*/
function applyCharacterUpdates(characterUpdates) {
    if (!characterUpdates) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Gold update
    |--------------------------------------------------------------------------
    */
    if (characterUpdates.gold !== undefined && playerGold) {
        playerGold.textContent = String(characterUpdates.gold);
    }

    /*
    |--------------------------------------------------------------------------
    | HP update
    |--------------------------------------------------------------------------
    */
    if (characterUpdates.current_hp !== undefined && playerHp) {
        const currentHp = String(characterUpdates.current_hp);
        const maxHp = String(characterUpdates.max_hp ?? "");

        if (maxHp !== "") {
            playerHp.textContent = currentHp + "/" + maxHp;

            if (window.ASCIIQuestHud) {
                window.ASCIIQuestHud.updateResourceBar(
                    playerHpBar,
                    playerHpFill,
                    characterUpdates.current_hp,
                    characterUpdates.max_hp,
                );
            }
        } else {
            playerHp.textContent = currentHp;
        }
    }
}
/*
|--------------------------------------------------------------------------
| Move character
|--------------------------------------------------------------------------
| Browser asks PHP to move.
| PHP validates collision and saves position.
*/
async function moveCharacter(direction) {
    if (isMoving) {
        return;
    }

    isMoving = true;

    try {
        const formData = new FormData();
        formData.append("direction", direction);

        const response = await fetch("move_character.php", {
            method: "POST",
            body: formData,
        });

        const result = await response.json();

        if (!result.success) {
            const messages = result.messages || [
                result.message || "Movement failed.",
            ];

            messages.forEach(function (message) {
                addLogMessage(message, "warning");
            });

            return;
        }

        if (result.tile_updates) {
            result.tile_updates.forEach(function (tileUpdate) {
                setMapGlyphAt(
                    Number(tileUpdate.x),
                    Number(tileUpdate.y),
                    tileUpdate.glyph,
                );
            });
        }
        applyCharacterUpdates(result.character_updates);
        gameState.playerX = Number(result.pos_x);
        gameState.playerY = Number(result.pos_y);

        const messages = result.messages || [result.message || "Moved."];

        messages.forEach(function (message) {
            addLogMessage(message, "success");
        });

        renderMap();
    } catch (error) {
        console.error("Movement error:", error);
        addLogMessage("Movement error.", "danger");
    } finally {
        isMoving = false;
    }
}

/*
|--------------------------------------------------------------------------
| Interact with current tile
|--------------------------------------------------------------------------
| E key.
| Current use:
|   stairs > and <
*/
/*
|--------------------------------------------------------------------------
| Interact with current tile
|--------------------------------------------------------------------------
| E key.
|
| Current use:
|   stairs > and <
|   chests O
*/
async function interactWithCurrentTile() {
    if (isMoving) {
        return;
    }

    isMoving = true;

    try {
        const response = await fetch("interact.php", {
            method: "POST",
        });

        const result = await response.json();

        /*
        |--------------------------------------------------------------------------
        | Apply tile updates
        |--------------------------------------------------------------------------
        | Example:
        |   chest O becomes opened chest o
        */
        if (result.tile_updates) {
            result.tile_updates.forEach(function (tileUpdate) {
                setMapGlyphAt(
                    Number(tileUpdate.x),
                    Number(tileUpdate.y),
                    tileUpdate.glyph,
                );
            });

            renderMap();
        }

        /*
        |--------------------------------------------------------------------------
        | Apply character updates
        |--------------------------------------------------------------------------
        | Example:
        |   gold changes after opening chest
        */
        applyCharacterUpdates(result.character_updates);

        const messages = result.messages || [
            result.message || "Interaction complete.",
        ];

        messages.forEach(function (message) {
            addLogMessage(message, result.success ? "success" : "warning");
        });

        /*
        |--------------------------------------------------------------------------
        | Map transition
        |--------------------------------------------------------------------------
        | We reload only when changing maps.
        */
        if (result.success && result.reload) {
            setTimeout(function () {
                window.location.reload();
            }, 350);
        }
    } catch (error) {
        console.error("Interaction error:", error);
        addLogMessage("Interaction error.", "danger");
    } finally {
        isMoving = false;
    }
}

/*
|--------------------------------------------------------------------------
| Mouse movement
|--------------------------------------------------------------------------
| Click an adjacent tile to move there.
| This is simple mouse support.
*/
function getDirectionFromAdjacentTile(targetX, targetY) {
    const dx = targetX - gameState.playerX;
    const dy = targetY - gameState.playerY;

    if (dx === 0 && dy === -1) {
        return "up";
    }

    if (dx === 0 && dy === 1) {
        return "down";
    }

    if (dx === -1 && dy === 0) {
        return "left";
    }

    if (dx === 1 && dy === 0) {
        return "right";
    }

    return null;
}

gameMap.addEventListener("click", function (event) {
    const cell = event.target.closest(".map-cell");

    if (!cell) {
        return;
    }

    const mapX = Number(cell.dataset.mapX);
    const mapY = Number(cell.dataset.mapY);

    const direction = getDirectionFromAdjacentTile(mapX, mapY);

    if (!direction) {
        addLogMessage("Click an adjacent tile to move.", "info");
        return;
    }

    moveCharacter(direction);
});

/*
|--------------------------------------------------------------------------
| Keyboard listener
|--------------------------------------------------------------------------
| Movement:
|   Arrow Keys / W A S D
|
| Interaction:
|   E
*/
document.addEventListener("keydown", function (event) {
    const key = event.key.toLowerCase();

    if (key === "e") {
        event.preventDefault();
        interactWithCurrentTile();
        return;
    }

    const direction = getDirectionFromKey(event.key);

    if (!direction) {
        return;
    }

    event.preventDefault();
    moveCharacter(direction);
});

/*
|--------------------------------------------------------------------------
| First draw
|--------------------------------------------------------------------------
*/
renderMap();

gameState.initialMessages.forEach(function (message) {
    addLogMessage(message, "info");
});
/*
|--------------------------------------------------------------------------
| Periodic map sync
|--------------------------------------------------------------------------
| Checks server every 15 seconds.
| Trap reset still uses server time; this only updates browser display.
*/
setInterval(syncMapState, 15000);
