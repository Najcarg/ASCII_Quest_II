# ASCII Quest II — Combat Milestone 1 Foundation Design

**Date:** 31 August 2026
**Status:** Approved source design; repository-adapted specification awaiting design review
**Milestone:** Combat Milestone 1 — Foundation

## 1. Purpose

Combat Milestone 1 adds the smallest durable, server-authoritative combat foundation that can prove ASCII Quest II's approved pointer-driven battle model without redesigning exploration or prematurely building the complete item, skill, enemy, or progression systems.

The milestone must establish:

- a resumable active encounter;
- configurable real-time turns and per-turn Action allowances;
- manual player weapon attacks;
- a single Cave Brute test enemy;
- player cooldown, cast, effect, Block, and potion boundaries;
- exactly-once victory rewards and permanent death;
- a combat center inside the accepted exploration HUD shell;
- server-side locks against using exploration or management routes to escape combat.

Browser animation is presentation. PHP, MariaDB, `CharacterStats`, and focused combat-domain services remain authoritative.

## 2. Current repository findings

### 2.1 Existing runtime architecture

The current application is server-rendered PHP with PDO/MariaDB, vanilla JavaScript, CSS, and JSON maps. There is no framework, ORM, package manager, service container, background worker, or real-time server.

`ascii-quest/game.php` currently:

- requires `user_id` and `character_id` in the session;
- loads the selected Champion, class, current map, map objects, overrides, and Warp definitions;
- obtains all derived Champion values from `CharacterStats`;
- renders the accepted permanent three-column exploration HUD;
- exposes a small `ASCII_QUEST_STATE` presentation object;
- loads `exploration_hud.js` and `game_controls.js` with `filemtime()` cache versions.

The center column currently contains the map and the lower Information / Server Info / Chat tabs. Combat must replace only that center state while keeping the accepted side panels and lower-tab location.

### 2.2 Existing client interfaces

`ascii-quest/js/exploration_hud.js` is a testable UMD-style module. It owns:

- tab switching;
- HP/Mana visual synchronization;
- in-HUD stat allocation;
- Warp destination display, confirmation, and travel requests.

`ascii-quest/js/game_controls.js` owns:

- map rendering;
- keyboard and adjacent-click exploration movement;
- the shared `E` interaction request;
- periodic map-override synchronization;
- HP, Mana, and Gold presentation updates.

There is no combat mode, combat timer, enemy rendering, action request, or battle client module. Combat controls must be a separate pointer-only module. Exploration movement and interaction listeners must not run while combat is active.

The existing adjacent-click movement contract is intentionally preserved outside combat for this milestone. Keyboard and adjacent-click movement use the same authoritative endpoint, the same enemy-range trigger, and the same active-combat lock. No exploration movement path remains usable once combat starts.

### 2.3 Existing server authority and security patterns

State-changing JSON endpoints use the selected session Champion and verify ownership. `allocate_stat.php`, `unlock_warp.php`, and `travel_warp.php` demonstrate the current JSON and CSRF conventions. `WarpService` and `WarpRepository` demonstrate row locking, transactions, authoritative cost/location lookup, and atomic state updates.

Important gaps that combat must close:

- `move_character.php`, `interact.php`, and `sync_map_state.php` know nothing about active combat;
- `allocate_stat.php` and Warp endpoints know nothing about active combat;
- `select_character.php` has no CSRF token and no active-combat or dead-state guard;
- `character_select.php` has no DEAD or resume-combat presentation;
- `delete_character.php` can currently delete the selected Champion;
- visiting Main Menu or logging out does not alter Champion state, but there is no durable encounter to resume after login.

Client-side disabled buttons are insufficient. A shared server-side combat access guard must protect every affected state-changing route.

### 2.4 Existing character and resource authority

`ascii-quest/lib/CharacterStats.php` and `ascii-quest/config/character_stats.php` are the only approved source of derived Champion statistics. `characters.current_hp` and `characters.current_mana` are persistent live resources. Maximum Life and Maximum Mana are derived and must never be recalculated in JavaScript.

Entering combat, leaving combat, refreshing, changing equipment, or recalculating a maximum must not restore HP or Mana.

The existing CharacterStats foundation exposes values including Action, Melee Damage, Spell Power, Toughness, Accuracy, Critical Chance, Critical Damage, resistances, and rates. It does not define final attack, mitigation, enemy Block, player Block, equipment, or skill-resolution formulas. Combat Milestone 1 must not silently invent permanent formulas for those systems.

### 2.5 Existing world, enemy, equipment, and inventory state

Map JSON currently represents layout, transitions, traps, chests, and one optional Warp. Neither map contains an enemy or encounter object. There is no enemy registry, combat page, or battle engine in the current implementation.

The right HUD's Equipment paper doll, five Loadout slots, and 25-cell Inventory are presentation placeholders only. There is no item definition, owned-item, inventory, equipped-item, skill-assignment, or potion-assignment backend. The testable combat domain can define the approved equipment snapshot and current-defense boundaries, but real equipment dragging/swapping cannot be activated safely until an authoritative item/equipment design exists.

### 2.5.1 Approved Cave Brute encounter representation

Combat Milestone 1 adds the smallest server-readable representation needed for one stationary Cave Brute:

- one stable encounter-instance ID;
- the authoritative map key;
- the Cave Brute enemy-definition key;
- one integer `x`/`y` map coordinate;
- one display glyph;
- `stationary = true`;
- fighting range `1`;
- range shape `orthogonal`.

The definition is version-controlled and validated against the selected map: the enemy coordinate must be in bounds, on a normal floor, and must not overlap a transition, Warp, chest, trap, or other map object. The enemy coordinate is occupied and non-walkable. At least one orthogonally adjacent valid floor tile must exist.

Milestone 1 has zero or one prototype enemy definition on its selected test map. It does not introduce database-backed enemy placement, enemy movement, patrol state, or a general spawn system. The later Enemy Creation Milestone replaces/generalizes this definition boundary without changing the combat service's authoritative encounter contract.

### 2.6 Existing database and migration conventions

Migrations live in `database/migrations/`, create/use `schema_migrations`, perform schema preflights, use a stored procedure for one-time execution, and intentionally are not applied by the development agent.

Migration 002 records the inspected parent-key compatibility by using:

```sql
character_warps.character_id INT UNSIGNED
```

against `characters.id`. Migration 003 must independently preflight the live `characters.id` definition before creating combat foreign keys. No migration is created or applied during this design run.

### 2.7 Existing tests

`php tests/run.php` is a native test runner currently covering `CharacterStats`, `CharacterStatAllocator`, and Warp behavior. `node tests/ExplorationHudTest.js` uses Node, fake DOM objects, and VM execution of the production JavaScript. The known pre-combat baseline is 49 PHP tests and 25 HUD tests.

Combat tests must follow these existing dependency-free patterns.

## 3. Authoritative combat philosophy

Exploration remains keyboard-driven. Combat is a mouse/pointer-driven reaction game.

Combat must not use:

- WASD;
- Arrow Keys;
- Q/W/E/R;
- number-key skill activation;
- automatic player attacks.

Every player offensive action starts only from a deliberate pointer click accepted by the server. The player must divide attention among enemy telegraphs, their own attacks and cooldowns, the Turn Bar, equipment, Inventory, Potion, active effects, Battle Info, and Chat.

Combat does not pause because the player opens Chat or Server Info, inspects an item, reads Battle Info, swaps equipment, or visits Main Menu while connected. Refresh and short synchronization gaps advance from durable server state. A longer disconnect never resets or escapes combat, but disconnected catch-up is capped by the rule in Section 5.4 rather than simulating unlimited wall-clock time.

### 3.1 Combat encounter trigger

Combat does not require pressing `E`. Exploration interaction remains separate from encounter detection.

Combat begins automatically when the Champion enters an enemy's fighting range. For the stationary Combat Milestone 1 Cave Brute:

- fighting range is exactly one tile;
- only direct orthogonal neighbors are in range;
- directly above, below, left, or right triggers combat;
- diagonal adjacency does not trigger combat;
- the enemy's own tile is occupied and cannot become the Champion's position.

The authoritative range predicate is:

```text
abs(champion_x - enemy_x) + abs(champion_y - enemy_y) == 1
```

#### Normal movement into range

When a movement request would place the Champion on a valid floor tile in fighting range, the server performs one coordinated operation:

1. authenticate the session and selected Champion;
2. lock the Champion, then the owning Account combat mutex, then check any existing active encounter;
3. validate the requested movement using the existing collision rules;
4. resolve and persist the Champion's final valid floor position;
5. evaluate the authoritative Cave Brute position/range;
6. create the encounter if none exists, or return the already-created encounter for an idempotent retry;
7. commit the movement and encounter transition together;
8. return sanitized combat-start state to the browser.

The browser immediately locks exploration input and switches/reloads the center into the authoritative combat scene. The server lock applies even if presentation has not finished switching.

#### Attempted movement onto the enemy tile

If the requested target is the Cave Brute coordinate:

- the target is treated as occupied;
- the Champion remains on the previous valid floor coordinate;
- no Champion/enemy coordinate overlap is written;
- attempted direct contact starts or resumes the authoritative encounter;
- the Cave Brute remains at its configured coordinate.

Because one exploration move changes position by one orthogonal tile, direct contact originates from a valid orthogonally adjacent Champion position. A diagonal or distant position does not start combat merely because the enemy exists nearby.

#### Atomicity and retry behavior

Movement resolution and encounter creation share one transaction/lock boundary. The unique active-encounter constraint is the final database guard. Repeated movement requests, two browser tabs, or a retried response may return the same encounter but cannot create a second active encounter or recreate Cave Brute HP/state.

After encounter creation, all later movement requests are rejected by the active-combat guard. Refresh discovers and resumes the same stored encounter, including its existing enemy HP and action state.

#### Future Enemy Creation scope

The later Enemy Creation Milestone may generalize enemy movement, fight/aggro ranges, enemies entering Champion range, ranged enemies, different range shapes/distances, patrol/chase behavior, multiple enemy types, bosses, and Slayers. None of those systems is implemented or pre-modeled beyond the clean position/range boundary needed by this stationary Cave Brute.

## 4. Superseded Action model

### SUPERSEDED FOR CURRENT COMBAT DESIGN

- Action = independent concurrent action bars.

### CURRENT APPROVED MODEL

- Combat has configurable real-time turn windows.
- The initial turn duration is 10 seconds.
- Action is the maximum number of offensive or skill actions an actor may begin in the current turn.
- Unused Actions are lost at the turn boundary and never carry over.
- Each actor's allowance resets from its authoritative Action value at the start of each turn.
- The player and enemy may execute actions concurrently relative to each other.
- Each actor's own actions execute sequentially; an actor cannot have two of its own attacks/casts executing at once.
- An action may start only if it can finish before the current turn ends.

This specification supersedes the older independent-action-bar description wherever it appears in historical design material. Unrelated historical documents are not rewritten in this milestone.

## 5. Turn and timing semantics

### 5.1 Central configuration

`TURN_DURATION_SECONDS` is represented by one version-controlled server configuration value, initially `10.0`. Prototype durations, cooldowns, decision intervals, rewards, and provisional resolver values also live in the same combat configuration. JavaScript receives server-provided timing state; it must not define authoritative copies.

### 5.2 Turn identity

Every encounter stores:

- a positive turn number;
- the turn start position on the authoritative logical combat timeline;
- player Actions remaining;
- enemy Actions remaining.

The turn end is `turn_started_timeline_ms + configured duration`. At a boundary, unused Actions are discarded and the next turn resets each allowance. The engine can advance across boundaries inside the permitted synchronization window without granting carry-over.

### 5.3 Start acceptance

An offensive action starts only when all are true under the encounter lock:

- encounter status is active;
- actor is alive;
- actor has at least one Action remaining;
- actor has no unresolved action of its own;
- the selected action is off cooldown;
- its duration is less than or equal to authoritative time remaining in the turn;
- the request belongs to the selected Champion and active encounter;
- all action-specific prerequisites pass.

Acceptance immediately:

- consumes one Action;
- persists the action and its server-created identity;
- stores logical-timeline start, resolve, and cooldown-ready positions;
- starts cooldown at the action's logical start position;
- stores any required offensive snapshot;
- returns the authoritative state.

Cooldown completion never bypasses an exhausted Action allowance.

### 5.4 Request-driven authoritative clock and disconnected catch-up

No background server is introduced. Combat uses two related server-authoritative values:

- `last_synchronized_at`: UTC wall time of the last completed authoritative synchronization;
- `timeline_elapsed_ms`: a monotonically increasing logical combat timeline used by turns, action resolution, cooldowns, effects, Block windows, and enemy decisions.

Central combat configuration defines:

```text
MAX_DISCONNECTED_CATCHUP_SECONDS = 5
```

The value is read from one authoritative configuration entry and is never scattered as a literal through services, repositories, endpoints, or JavaScript.

Every combat state read or command calculates:

```text
wall_elapsed = server_now - last_synchronized_at
processed_elapsed = min(max(wall_elapsed, 0), MAX_DISCONNECTED_CATCHUP_SECONDS)
target_timeline = timeline_elapsed_ms + processed_elapsed
```

It then uses this order:

1. lock the selected/owned Champion row;
2. lock the owning Account row as the narrow combat mutex;
3. lock the active Combat Encounter row if one exists;
4. calculate the capped target logical timeline;
5. process due Combat Action resolutions and Combat Events in logical-timeline order;
6. process victory or death immediately when HP reaches zero;
7. process crossed turn boundaries, discarding unused Actions;
8. run due Cave Brute decisions only through the capped target timeline;
9. persist the resulting logical timeline and state;
10. set `last_synchronized_at` to the actual current server wall time, even when part of a long absence was intentionally skipped;
11. commit and return a sanitized client projection.

Consequences:

- a two-second absence advances two seconds;
- a five-second absence advances five seconds;
- a 30-second or three-hour absence advances at most five seconds;
- the next normal poll advances only the new time since reconnect, not another chunk of the previously skipped absence;
- no player offensive action is synthesized during catch-up;
- a legitimate enemy action that reduces Champion HP to zero inside the processed window still causes permanent death;
- encounter identity and all persisted HP, Mana, enemy HP, turn/Action, cooldown, potion, Block, reward, death, and Battle Info state remain intact.

The logical timeline avoids rebasing every outstanding cooldown/action deadline after a capped absence. Audit timestamps such as creation, update, reward, death, and completion remain UTC wall-clock timestamps; combat scheduling uses logical milliseconds.

An additional per-synchronization event-count safety bound may prevent pathological processing loops, but it must not be used to replay the skipped portion of a disconnected interval across rapid follow-up polls.

## 6. Cooldowns, actions, and effects

Action duration and cooldown are separate.

For an action with a two-second duration and six-second cooldown:

```text
t=0  action starts; cooldown starts
t=2  effect resolves
t=6  action becomes ready
```

There is no automatic recovery timer after resolution. Enemy cooldown data remains server-side and is excluded from the client projection. Player cooldown start/end values are returned for rendering.

Timed effects have their own persisted start/end state and visible duration bars. Their duration is not inferred from a skill cooldown. The foundation supports multiple simultaneous effects, even if the prototype proves it with only one centrally configured test effect.

## 7. Player weapon attack and offensive snapshots

The equipped weapon provides the normal attack. There is no permanent Attack slot in the right Loadout.

The center-left combat area presents the current weapon, attack name, cooldown bar, and why it is ready or unavailable. The attack starts only through a pointer click and never automatically.

At start, the server snapshots every offensive value needed for later resolution, including at minimum:

- weapon/attack definition key;
- damage type;
- prototype base or resolved offensive amount needed by the provisional resolver;
- accuracy, critical chance, and flat critical damage inputs if used by the prototype;
- any offensive effect identifiers required at resolution.

The in-flight action resolves from that immutable server-created snapshot. Changing equipment affects future action snapshots only.

The repository currently has no equipment backend. Milestone 1 therefore defines and tests a `CombatEquipmentProvider` boundary. Its production foundation can expose one configured prototype weapon until the separate item/equipment design supplies authoritative equipped records. It must not pretend the empty paper-doll cells are real equipment.

## 8. Skill Loadout and active-effect foundation

The existing Loadout remains exactly:

- Skill 1;
- Skill 2;
- Skill 3;
- Ultimate;
- Potion.

No normal Attack slot is added. Empty or unavailable slots remain visibly empty/disabled. A loaded prototype skill, if used to prove cooldown/effect behavior, is defined by server configuration and not presented as the finished class catalogue.

Each skill UI supports distinct states:

- READY;
- cooling down;
- no Actions remaining;
- insufficient turn time;
- actor already executing;
- encounter not active;
- future server-supplied disabled reasons.

The server, not JavaScript, decides whether the command is acceptable.

## 9. Equipment and defense boundary

The approved future combat contract is:

- all ten normal equipment slots may be swapped with pointer controls;
- swapping consumes no Action;
- swapping adds no artificial cooldown;
- swapping does not pause combat;
- in-flight player offense uses its start snapshot;
- incoming enemy damage uses the Champion's current defensive equipment/stat state when the hit resolves.

`CharacterStats` remains the derived-stat authority. Combat code may request a current calculated defensive view but must not duplicate its formulas.

Because no owned-item/equipment backend exists, real persistence and live swapping are explicitly deferred to the Item / Equipment system. This milestone implements the domain interface and regression tests for snapshot-versus-current semantics, does not invent fake owned items, and preserves the current visual slots for later activation.

## 10. Potion foundation

Potion use:

- is pointer-clicked;
- is instant;
- uses no Action;
- has no cast duration;
- has no ordinary cooldown;
- uses one authoritative per-encounter charge;
- does not pause combat.

The combat encounter snapshots the allowed potion definition key and charge allowance when it starts. Swapping or re-equipping an equivalent potion can never recreate spent charges. Each request carries an opaque idempotency key. Replaying the same request returns the recorded result; it does not heal twice.

The prototype potion values are centrally configured and clearly marked as foundation-only because no item/loadout backend exists. Current HP is capped by the server-provided Maximum Life from `CharacterStats`; using or equipping a potion does not alter Maximum Life or restore Mana unless its authoritative prototype definition explicitly says so.

## 11. Cave Brute test enemy

Milestone 1 has one server-defined enemy key: `cave_brute`.

Its centralized prototype definition includes:

- name and ASCII representation;
- Maximum HP;
- Action value `2`;
- normal attack `smash`, Physical, approximately 1.5 seconds, approximately 3-second cooldown;
- active skill `fire_slam`, Fire, approximately 2 seconds, approximately 6-second cooldown;
- minimal defensive Block capability;
- fixed foundation-only damage/reward values required to prove victory and death.

The exact prototype amounts are configuration values, not permanent balance formulas.

Enemy AI runs only under the encounter transaction:

1. if the skill is ready, one Action remains, enough turn time remains, and the enemy is idle, start the skill;
2. otherwise, if the normal attack is ready, one Action remains, enough turn time remains, and the enemy is idle, start the normal attack;
3. otherwise wait until the earliest relevant cooldown, resolution, or turn boundary.

The enemy cannot overlap its own actions or exceed its allowance. Enemy cooldown values and next-ready logical-timeline positions are never included in client state. Enemy action telegraphs are included only after an action actually starts.

## 12. Block reaction boundary

When an eligible enemy action starts, the server creates one Block opportunity bound to that enemy action. The client receives:

- enemy action identity;
- attack/skill name;
- relevant damage type information;
- telegraph expiry/resolution time;
- a single-use Block token;
- a randomized normalized arena position.

The server generates safe normalized placement coordinates. JavaScript converts those to arena coordinates and clamps against the measured popup dimensions so the popup remains fully inside the combat arena and outside fixed side panels.

A Block request is accepted at most once for that enemy action and only before the server expiry. Replaying the same request is idempotent; a different second attempt is rejected. Block consumes no Action and remains available after player Actions are exhausted.

The Block outcome is resolved through a focused `BlockResolver` interface. Randomized prompt placement and any prototype defensive rolls use an injected `CombatRandomSource`, so server production uses cryptographic randomness while tests remain deterministic. Any Milestone 1 reduction/success value is centralized and labeled provisional. Final shield, rate, success, and amount formulas remain deferred.

If no timely attempt is accepted, the incoming attack resolves without the active Block benefit. Existing passive defensive values may still be supplied to the provisional damage boundary. Enemy Block is a minimal automatic server-side roll through a separate `EnemyDefenseResolver` boundary. It has no reaction button and exposes no roll/chance to the browser.

## 13. Combat center and HUD integration

`game.php` keeps the current left and right HUD panels. It renders one of two mutually exclusive center states:

- exploration center: current map and Information tabs;
- combat center: current battle scene and Battle Info tabs.

The combat center contains:

- larger Champion ASCII/Unicode representation;
- larger Cave Brute ASCII representation;
- enemy name and HP;
- current Turn indicator and visible Turn Bar;
- current weapon attack and player cooldown;
- active effect duration bars;
- random Block popup inside the arena;
- bottom physical-drop row, initially allowed to be empty/placeholders;
- Battle Info / Server Info / Chat in the existing lower information region.

The style remains black/dark, monospaced, restrained, and framed with thin amber/brown borders. It must not become a colorful card UI or introduce large illustrated assets.

Battle Info is automatically selected when the encounter first renders. The player may switch tabs without setting a pause state or stopping connected combat polling. Battle Info is historical; immediate reaction information remains in the arena.

The current Chat tab is only a placeholder. Milestone 1 preserves it as selectable and non-pausing, but a functioning chat backend is outside the repository and cannot be claimed as implemented.

## 14. Client/server contract

Combat JavaScript sends intent only:

- request current state;
- start weapon action by action key;
- start a configured skill by slot/key;
- attempt Block using the server token/action identity;
- use the combat potion;
- close the post-victory encounter when allowed.

Every state-changing request includes:

- the session CSRF token;
- an opaque client-generated request token;
- the intended action identity only.

It never sends trusted damage, HP, enemy HP, cooldown completion, Actions remaining, Block outcome, potion charges, Gold, EXP, equipment statistics, coordinates for the Block popup, or reward amounts.

The sanitized response contains only player-visible data. In particular, it omits enemy cooldown fields, future AI decision timeline positions, server-only snapshots, and provisional hidden rolls.

## 15. Durable combat-state model

### 15.1 Why persistence is required

JavaScript-only state would let refresh escape the encounter, reset enemy HP and potion charges, duplicate rewards, pause the enemy, or revive a Champion. Active combat therefore belongs in MariaDB.

### 15.2 Proposed tables

Migration 003 introduces three focused tables and small Champion lifecycle columns.

#### `combat_encounters`

One row records the durable encounter aggregate:

- encounter ID and owning Champion ID;
- enemy definition key and enemy current/maximum HP snapshot;
- lifecycle status: active, victory loot, defeated, or closed;
- nullable active-slot marker with a unique `(character_id, active_slot)` key so only one non-closed encounter exists per Champion while completed history can remain;
- logical combat timeline, last wall-clock synchronization time, turn number, logical turn start, and remaining Actions;
- next enemy decision position on the logical timeline;
- prototype potion key, allowance, and remaining charges;
- Gold/EXP reward snapshot and exactly-once issuance timestamp;
- death-processing timestamp and killer enemy key;
- completion timestamps and a monotonically increasing version.

#### `combat_actions`

One row per accepted player action, enemy action, Block attempt, or potion command records:

- encounter and optional parent enemy-action identity;
- actor, command/action kind, and definition key;
- nullable client request token with a unique `(encounter_id, request_token)` key;
- state plus logical start, resolve, cooldown-ready, and completion positions;
- explicit server-created offensive snapshot fields needed by the prototype;
- Block eligibility/token/expiry/attempt and normalized popup position;
- resolved amounts required for audit and idempotent replay.

This table permits one in-flight player and one in-flight enemy action at the same time while enforcing sequential behavior per actor in the service.

#### `combat_events`

An ordered append-only event row supports Battle Info and refresh:

- encounter ID and sequence number;
- stable event type;
- restrained server-created message and optional emphasis class;
- creation timestamp;
- unique `(encounter_id, sequence_number)`.

The browser never supplies log messages.

### 15.3 Champion lifecycle columns

`characters` gains the smallest permanent-death state:

- `life_state`, default `alive`, allowed foundation values `alive` and `dead`;
- nullable `died_at`.

Killer and encounter context remain on the durable encounter row. That row is the explicit future Slayer-processing input. No Slayer/Court/Bounty table or logic is added.

### 15.4 Migration safety

Migration 003 must:

- require migrations 001 and 002;
- abort if combat tables/columns exist without its migration record;
- verify `characters.id` is exactly the expected unsigned integer type before creating matching foreign keys;
- use InnoDB and explicit indexes/foreign keys;
- preserve existing Champions, map state, Warp unlocks, HP, Mana, Gold, and XP;
- add no default healing or encounter rows;
- be accompanied by read-only verification SQL;
- never be applied by the implementation agent without separate approval.

## 16. Transaction, concurrency, and idempotency rules

Every combat transaction and combat-aware exploration transaction that enforces account-wide combat exclusion uses this authoritative lock order wherever the rows exist:

1. selected/owned Champion row;
2. owning Account row (`users.id`) as a combat mutex;
3. active Combat Encounter row, if one exists;
4. Combat Action and Combat Event rows as needed.

Champion is always first because movement can begin combat before an encounter row exists. The Account row is a narrow mutex only for combat entry, combat mutation, and account/Champion management decisions that must exclude another owned Champion's unresolved encounter; it is not a general application locking policy. Focused same-Champion repository operations that do not make an account-wide exclusion decision may retain the simpler Champion then Encounter then Action/Event order. No repository or service may use encounter-first locking or choose a different order for convenience. After acquiring the required locks, the service synchronizes due state before validating a new combat command.

Protections include:

- ownership derived from session user and selected Champion;
- CSRF for all state-changing combat requests and combat-aware selection/deletion requests touched by this milestone;
- request-token uniqueness for retry-safe commands;
- one unresolved action per actor enforced by locked-state checks and an appropriate database uniqueness strategy/index where possible;
- Block bound to one enemy action and accepted once;
- potion charge decrement and HP update in the same transaction;
- victory transition, reward updates, and `rewards_issued_at` in one transaction;
- death state and encounter defeat in one transaction;
- non-negative, bounded HP/enemy HP/action/charge updates;
- response replay from persisted results for duplicate request tokens;
- no browser-trusted wall times, logical-timeline positions, or outcomes.

All random tokens, normalized prompt positions, and provisional Block rolls originate from a server-side `CombatRandomSource`. A deterministic fake is injected in tests; the browser never supplies or rerolls these values.

The unique active encounter marker and row locks protect against two tabs starting or mutating competing encounters.

## 17. Victory and loot phase

When enemy HP reaches zero:

- it becomes dead immediately;
- no further enemy decision/action can begin;
- outstanding enemy offense that has not already resolved is cancelled according to the centralized foundation rule;
- the Turn Bar stops;
- player offensive controls disable;
- the encounter moves atomically to `victory_loot`;
- configured Gold and raw EXP are added exactly once;
- the reward event is appended once.

Gold and EXP are not draggable loot. Physical drops use the bottom battle loot row. Milestone 1 may produce no physical item drops because no item backend exists, but it must preserve the safe post-victory phase and must not auto-return to exploration.

Closing the victory phase is an explicit server command. Only then does the encounter become closed and exploration unlock. Refresh before closure returns to the same loot phase without awarding again.

No XP-to-level formula is approved in the current repository. Milestone 1 may add the configured raw EXP reward exactly once, but must not invent level thresholds or automatic level/stat-point awards.

## 18. Permanent Champion death and Slayer hook

When current HP reaches zero:

- `characters.current_hp` becomes exactly zero;
- `characters.life_state` becomes `dead` with `died_at`;
- the encounter becomes defeated and records its killer enemy key and death-processing timestamp;
- combat stops and cannot be resumed as an active fight;
- no healing, resurrection, respawn, Warp, or map return occurs;
- the Champion row remains.

Character Selection displays a clear DEAD state, removes/disables Enter Dungeon for that Champion, and preserves the historical card. `select_character.php`, `game.php`, movement, interactions, Warp, stat allocation, and combat entry reject dead Champions server-side.

A Champion whose `life_state` is `dead` is permanent history and must not be manually deletable. `delete_character.php` rejects the request server-side, the Champion row remains, and its defeated encounter/killer context remains available for world and future Slayer history. Migration 003 deliberately retains `ON DELETE CASCADE` for the ordinary Champion-deletion path: the runtime guard prevents DEAD deletion, while otherwise eligible living Champions may still be deleted without leaving orphaned combat rows.

The completed encounter plus killer key is the explicit future Slayer hook. A future service can consume that durable result exactly once. Milestone 1 does not implement Slayer qualification, bounty, Court, or special AI.

## 19. Refresh, navigation, and combat locks

### 19.1 Refresh and login

Refreshing `game.php` discovers the selected Champion's non-closed encounter and renders combat/loot/death state instead of the map. Enemy HP, player HP/Mana, turn state, actions, cooldowns, potion charges, effects, Block state, rewards, and Battle Info come from MariaDB.

`game.php` makes a read-only combat-mode decision and must not hold the Account/combat mutex while rendering HTML. An already-rendered exploration page can therefore become stale if another request starts combat. This is safe because every subsequent state-changing exploration or account-management request acquires the atomic Champion, Account, and Encounter guard before mutation; stale UI cannot successfully mutate exploration state.

Refresh, a short network interruption, a browser crash, closing/reopening the browser, and logout/login all resume through the same durable encounter and capped synchronization path. None recreates the encounter or restores resources.

Logging out never closes the encounter. After login, character selection must make the active encounter visible and force resumption before another Champion can be selected. The first state request processes only the elapsed logical combat time permitted by the centralized five-second catch-up cap, then resumes normal connected progression.

### 19.2 Main Menu

Opening Main Menu is allowed as navigation but does not pause or clear combat. It must prominently offer Resume Battle. The account may create another Champion while one Champion has a non-closed encounter; creation neither synchronizes away, resets, pauses, nor closes that encounter.

Character Selection continues to show every Champion, including a newly created one. While an unresolved encounter exists, only the fighting Champion exposes Resume Battle. Other Champions cannot be selected or Enter Dungeon until the encounter closes. The fighting Champion cannot be deleted. After closure, ordinary selection becomes available again for eligible living Champions; DEAD Champions remain visible but cannot be selected, entered, or deleted.

This provides no free escape while avoiding a fragile browser-history trap.

### 19.3 Required locks

While encounter status is active or victory loot is unclosed:

- movement is rejected;
- map interactions and transitions are rejected;
- Warp unlock and travel are rejected;
- stat allocation is rejected;
- Champion selection/change is rejected;
- deletion of the active Champion is rejected;
- a second encounter is rejected;
- exploration map synchronization does not mutate overrides;
- stat-allocation controls and Warp travel are disabled in combat presentation, in addition to server checks.

Information, Details, Battle Info, Server Info, Chat tab selection, combat commands, valid Block, potion use, and eventually valid item/equipment interaction remain allowed.

## 20. Escape

No approved Escape implementation exists in the repository. Combat Milestone 1 adds no Escape button, roll, timer, or keyboard behavior. Permanent death does not depend on Escape. Escape remains deferred for a separate design.

## 21. No-free-healing invariant

Combat entry copies no maximum into current resources. Combat exit copies no maximum into current resources. Refresh uses stored current values. Equipment-provider recalculation may change a maximum later but must not change current HP/Mana as a side effect.

The same invariant applies to stat allocation: it is locked during the encounter and, once available outside combat, preserves current resources exactly as it does now.

## 22. Testing architecture

### 22.1 PHP domain tests

Pure domain tests use an injected fake clock and fake repositories/providers. They cover turn rollover, Actions, cooldown start, sequential actor actions, concurrent actors, insufficient time, snapshots, current defense, enemy AI, Block, potion, victory, death, rewards, and refresh/catch-up.

Encounter-trigger tests additionally cover orthogonal range entry, diagonal non-entry, direct attempted contact with an occupied enemy tile, coordinate non-overlap, duplicate movement retry, post-start movement locking, refresh resume, and preservation of existing enemy HP/state.

Disconnected-catch-up tests use an injected wall clock and prove:

- two seconds absent advances exactly two logical seconds;
- five seconds absent advances exactly five logical seconds;
- 30 seconds absent advances no more than five logical seconds;
- a multi-hour absence advances no more than five logical seconds;
- an immediate follow-up poll does not replay skipped absence;
- encounter/resources/enemy/turn/Action/cooldown/potion/Block/reward/death/Battle Info state does not reset;
- no player offensive action is synthesized;
- legitimate death inside the processed window remains permanent.

Character-management tests prove creation remains available, a newly created/other Champion cannot be selected or Enter Dungeon during the unresolved encounter, the fighting Champion/encounter remains untouched, Resume Battle remains available, and ordinary selection returns after closure. Permanent-death tests also prove a DEAD Champion's delete request is rejected and both the Champion row and defeated encounter/killer history remain.

### 22.2 Repository and endpoint contract tests

Dependency-free fakes and migration-structure checks cover ownership, transactions, lock order, idempotency keys, sanitized projections, active encounter uniqueness, route locks, and exact SQL migration constraints. No live database is required in the unit suite.

Lock-order tests require Champion first, the owning Account combat mutex second when account-wide exclusion is required, active encounter afterward, and action/event rows last. Real `CombatRepository` coverage must include selecting one owned Champion while another owned Champion holds the active encounter. Focused same-Champion repository tests may omit the Account mutex only when they do not enforce an account-wide decision. Tests must reject or detect Account/Encounter locking before the owned Champion lock.

### 22.3 JavaScript tests

A new Node test imports the production combat HUD module and uses fake DOM/fetch/clock primitives. It covers Turn Bar rendering, player cooldowns, disabled reasons, hidden enemy cooldowns, bounded Block popup placement, one-click command pending state, Battle Info selection, Chat non-pausing behavior, combat-center structure, and absence of exploration keyboard movement.

Existing PHP and HUD suites run after every meaningful task.

## 23. Scope exclusions

Milestone 1 does not add:

- full class skill trees or catalogues;
- full inventory, item generation, affixes, rarity, or drag/drop;
- real owned equipment persistence without separate approval;
- multiple enemies, personalities, weighted AI, elites, bosses, Slayers, or World Bosses;
- Court/Bounty systems;
- final attack, mitigation, shield, or Block balance formulas;
- XP progression thresholds;
- Escape;
- a chat backend;
- sound/music;
- mobile combat controls;
- PvP;
- frameworks or new dependencies.

## 24. Approved scope boundaries

The following decisions are authoritative for Combat Milestone 1:

1. **Encounter entry:** entering the stationary Cave Brute's one-tile orthogonal fighting range starts combat automatically. Pressing `E` is not involved. Attempted direct contact starts combat without coordinate overlap.
2. **Real equipment swapping:** item ownership, equipment persistence, and live swapping are deferred until the Item / Equipment system exists. This milestone preserves and tests the offensive-snapshot/current-defense interface contract but does not invent a fake item backend.
3. **Functional Chat:** the Chat tab remains visible, selectable, and non-pausing. A functional Chat backend is outside this milestone.
4. **Existing adjacent-click exploration movement:** it remains unchanged outside combat for this milestone. It uses the same authoritative movement endpoint and therefore triggers combat under the same range/contact rules. Every movement input path is locked during combat.
5. **Character creation during combat:** the account may create another Champion. The unresolved encounter and fighting Champion remain untouched; the new/other Champion cannot be selected for gameplay until the encounter closes, and Character Selection continues to offer Resume Battle for the fighter.
6. **Disconnected catch-up:** refresh/reconnect resumes durable state and processes at most five seconds since the last completed authoritative synchronization. It never replays the remainder of a long absence through repeated immediate polls.

Prototype fixed attack, damage, potion, Block, and reward values will be proposed in centralized configuration during implementation review. They are explicitly not final balance rules.

## 25. Acceptance gate

Combat Milestone 1 is ready for live testing only when:

- migration 003 has been reviewed but not applied by Codex;
- the stationary Cave Brute definition validates against its selected map;
- orthogonal movement/contact starts exactly one encounter while diagonal position does not;
- direct enemy-tile contact never overlaps Champion and enemy coordinates;
- active combat survives refresh and login without reset or pause exploits;
- disconnected catch-up uses the centralized five-second cap and preserves all encounter state;
- every account-wide combat transaction locks Champion, then Account mutex, then encounter, then action/event rows;
- Champion creation remains available without enabling Champion switching or altering the active encounter;
- player actions are pointer-only, manual, sequential, server-accepted, and Action-limited;
- Cave Brute follows the approved hidden-cooldown, skill-first logic;
- Block prompt and potion commands are idempotent and use no Action;
- player-visible cooldown/effect/Turn state renders in the current HUD shell;
- enemy cooldown state is absent from client output;
- movement, interaction, Warp, stat allocation, Champion switching, deletion, and duplicate combat entry are server-locked;
- victory rewards are exactly once and the loot phase persists;
- death is permanent, selection shows DEAD, manual deletion is rejected, and the Champion plus killer context remain for Slayer processing;
- HP/Mana are never restored by combat lifecycle or refresh;
- all new and existing automated suites, syntax checks, migration checks, and manual browser checks pass.

## 26. Final authority map

```text
Pointer intent
      │
      ▼
CSRF + session ownership + request token
      │
      ▼
CombatService transaction
      ├── authoritative clock / turn engine
      ├── CharacterStats
      ├── combat definition registry
      ├── server random source
      ├── equipment snapshot/current-defense boundary
      ├── provisional player Block/enemy defense/damage boundaries
      └── MariaDB encounter/action/event records
      │
      ▼
Sanitized player-visible projection
      │
      ▼
Vanilla JavaScript rendering in the existing HUD shell
```

No browser value crosses upward as an authoritative game result.
