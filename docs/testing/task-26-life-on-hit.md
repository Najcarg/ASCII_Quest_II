# Task 26 — Activate Life on Hit only

Base: `16731fbf18b4bfc796c0a7d03f427169579bea5e`
Branch: `feature/combat-task-26-life-on-hit`

The explicit Task 26 brief is authoritative; `docs/tasks/current-task.md` still
contains historical Task 19. Read alongside Task 24/25 testing notes and the
item-system design's offensive-snapshot, current-defense and maximum-resource
sections. This change introduces no migration, schema edit, database operation,
bootstrap, production access, main promotion or deployment.

## Rules, authority and HP cap

The only amount is the flat integer `stats.utility.life_on_hit` from server
`CharacterStats`, with equipped affixes combined by `EquipmentStatAggregator`.
There is no client-supplied amount, duplicated affix parsing, random healing,
damage multiplier or percentage lifesteal. CharacterStats already clamps negative
aggregated Life on Hit to zero; multiple modifiers/items sum once.

For an alive Champion with `old_hp > 0` and a qualifying resolved contact:

```text
loh = stats.utility.life_on_hit                       # nonnegative integer
healed = min(loh, max(0, current_max_life - old_hp))
new_hp = old_hp + healed
```

For normal capped state this is exactly
`new_hp = min(current_max_life, old_hp + max(0, life_on_hit))`.
The subtraction-before-addition form also cannot reduce HP in an anomalous
already-over-cap state and avoids adding past the cap. It does not replace the
existing equipment-service downward HP/Mana clamping. Full Life, zero amount,
or over-cap state yields no gain and no recovery event. Only `current_hp` is
written; Mana, Maximum Life, Maximum Mana and all other character fields stay
unchanged. Dead life state or zero HP never revives.

## Action and outcome policy

Only the existing normal `weapon` action qualifies, including Task 24 explicit
unarmed (no item identity) and real equipped weapons. Skills never qualify:
Flame Strike HIT, CRITICAL, BLOCK, and its resolved visual effect cannot heal.
Enemy actions and reactions cannot trigger Champion Life on Hit.

Successful contact comes **only** from the explicit authoritative damage outcome:

| Outcome / state | Heal eligible? |
| --- | --- |
| HIT | yes |
| CRITICAL | yes, once, not multiplied |
| Enemy BLOCK / CRITICAL + blocked normal attack | yes, once |
| Zero applied damage with any of the above outcomes | yes |
| MISS, DODGE, unresolved, cancelled, failed start | no |
| Previously resolved/replayed action or duplicate request | no new heal |
| Killing blow, while Champion alive | yes, before victory |
| Dead/zero-HP Champion | no |

Task 25's hit → enemy Block → critical calculations/outcomes are unchanged.
Cave Brute's current Block is percentage mitigation (50%, at its existing 20%
chance), not contact denial. It remains landed contact when rounding removes
all numeric damage: base 1 minus rounded prevention 1 is a legitimate zero
BLOCK. Base-zero HIT, CRITICAL, BLOCK, and critical+Block likewise qualify.
This interpretation was confirmed through supervisor coordination against the
explicit zero-damage rule. No separate full-contact-denial mechanic exists in
the current resolver; none is invented. Never infer contact from `damage > 0`.
A future genuine contact-denial Block needs an explicit policy/outcome before
being integrated; this task does not redesign Block.

## Stat timing and stability

Use **resolution-time** current stats through the existing
`PersistentCombatEquipmentProvider::characterStats()` boundary. It locks equipped
items and invokes the shared aggregator/CharacterStats. As in CombatService,
providers without that optional method use base `CharacterStats::calculate()`;
the production provider always has the equipment-aware method. No provider
interface or immutable offensive snapshots are changed.

Inspection found only typed existing offensive snapshots (weapon identity,
damage/type, Accuracy, critical chances/damage and duration timestamps), not a
clean generic persisted utility snapshot mechanism. Reusing an offensive field
or storing utility in event text would corrupt its semantics. Resolution-time
reads therefore use the explicitly allowed schema-free policy instead.

`EquipmentService::assertMutable()` rejects equip/unequip in both `active` and
`victory_loot`; `CombatAccessGuard::STAT_ALLOCATE` also rejects unresolved combat.
These existing restrictions keep equipment Life on Hit and maximum Life stable
during an encounter. Character/account locks preserve the authoritative mutation
boundary. Tests deliberately mutate an injected equipment reader to prove the
resolution-time boundary, not to enable live mid-combat equipment changes. If a
future task permits such changes, utility timing must be considered explicitly.

## Transaction, chronology and idempotency

CombatService/CombatAccessGuard already own the transaction and lock Champion,
account encounter and pending action rows. CombatActionResolver now:

1. Resolves hit → enemy Block → critical from immutable offense as before.
2. Applies enemy HP damage to the returned encounter state.
3. On explicit eligible normal contact, calculates current shared utility/cap
   and persists positive healing with the existing locked HP compare-and-swap.
4. Persists action resolution/damage and emits the unchanged attack outcome.
5. Emits `player_life_on_hit` text (`You recover N Life.`) only for actual `N > 0`.
6. Returns the updated locked character and encounter to the synchronizer.

The synchronizer sees healed HP before later enemy damage in the same catch-up,
and before terminal victory/rewards. Defeat is still processed before due
contacts for zero HP; lethal earlier enemy actions cancel pending player attacks.
Enemy HP synchronization, Champion healing/incoming damage, resolved action,
events and terminal rewards all commit or roll back in the **same existing
transaction**. No nested transaction or separate healing receipt is introduced.

Only locked pending actions are resolved. The existing pending→resolved state
transition and request-token replay handling mean repeated polls, duplicate
starts, fresh service instances, refresh and Close cannot repeat healing or
rewards. A failed transaction has no durable heal; retry may use new random
rolls under the existing nontransactional random-source policy.

The existing safe Battle Info event projection displays server text, while the
existing authoritative `champion.current_hp` projection immediately reflects
persisted HP and survives refresh. No HUD changes, browser HP arithmetic,
inventory UI changes or new public snapshot fields are needed.

## RED / GREEN evidence

Before changes:

```sh
php tests/run.php                   # 358 passed, 0 failed
node tests/ItemHudTest.js            # 21 passed, 0 failed
node tests/CombatHudTest.js          # 32 passed, 0 failed
node tests/ExplorationHudTest.js     # 30 passed, 0 failed
```

Added 17 Task 26 tests before production edits, then:

```sh
php tests/run.php                   # RED: 365 passed, 10 failed
```

Failures demonstrated missing +5/capped/zero-contact/critical/Block/weapon/unarmed
healing, no killing-blow/replay HP gain, no aggregated/current-stat healing, and
no HP persistence failure on an eligible contact. Seven exclusion tests were
already GREEN. After the resolver-only production change:

```sh
php tests/run.php                   # GREEN: 375 passed, 0 failed
```

Expanded to 20 Task 26 tests for real equipment mutation blocking, multiple
affixes including persisted integer strings, heal→enemy damage chronology and
shared rollback, and direct dead-life-state protection. The direct resolver
fixture initially failed existing encounter/action lock preconditions; added
those required fixture locks (no production lock changes). Final verification:

```sh
php tests/run.php
# 378 passed, 0 failed
node tests/ItemHudTest.js
# 21 passed, 0 failed
node tests/CombatHudTest.js
# 32 passed, 0 failed
node tests/ExplorationHudTest.js
# 30 passed, 0 failed
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
# all 121 PHP files passed
find . -name '*.js' -not -path './.git/*' -print0 | xargs -0 -n1 node --check
# all 7 JavaScript files passed
git diff --check
# passed
```

New coverage includes LOH 0/negative/5, partial cap/full/over-cap, Mana/maximums
unchanged, explicit HIT/CRITICAL/BLOCK/critical+Block, MISS, zero-damage contact,
base1 rounded-to-zero Block, enemy HIT/DODGE/BLOCK exclusion, weapon/unarmed,
skill/effect exclusion, unresolved/cancelled/resolved/failed start, pending and
resolved duplicate tokens, repeated sync/fresh service refresh, killing-blow
healing before one reward, Close replay, lethal enemy cancellation/permadeath,
zero HP/no revival and inconsistent dead positive-HP protection, aggregation,
resolution-time utility/current maximum with immutable offense, and failures
at HP compare-and-swap, action persistence, attack event, recovery event, encounter
sync and victory event. Each injected failure rolls back state and retries to
one heal. Chronological heal then incoming damage rolls back together as well.

The full regression run retains Task 24 Strength5 → unarmed5 / mace10–12;
Task 25 Accuracy/critical/dodge/Block/legacy NULL snapshots; attack/cast rates;
equipment HP/Mana caps; loot, inventory, equipment, ownership/security, timeline,
resources, victory and permanent-death behavior.

## Deferred mechanics

Mana on Hit; Life per Kill; Mana per Kill; regeneration; lifesteal%; status
healing; healing amplification; overheal/shields. No unrelated status, damage,
rate, inventory, reward or Block rules are activated.

## Manual testing still required

No browser or real database testing was performed; automated tests use existing
deterministic in-memory PDO/equipment fixtures. No production access occurred.

- Equip Life on Hit outside combat; verify authoritative stats and +5 per normal
  weapon/unarmed HIT, one gain per CRITICAL/Block, no gain on MISS or Flame Strike.
- At nearly/full Life check capped recovery text/no zero-noise; verify Mana and
  maximum resources unchanged, refresh/two-tab replay without duplicate gain.
- Verify killing-blow HP before victory, one reward/loot result and unchanged
  Close/Continue; check lethal earlier enemy attacks still cause permanent death.
- Smoke-test login/selection, exploration, movement/transitions/stairs, traps,
  chests/map overrides, inventory/equipment and HP/Mana refresh persistence.

Stop after feature-only push for ChatGPT GitHub review. No main promotion or
deployment is authorized.
