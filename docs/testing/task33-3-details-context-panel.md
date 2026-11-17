# Task33.3 — Information fill and shared context Details

Base: `4410d10f4f9e9f169c35bd03e496bab08a2f771f`.
Branch: `feature/ui-task-33-3-details-context-panel`.

Presentation only. No promotion, deployment, production access, database work,
migrations, item/equipment authority changes, new mechanics, or Task32 cleanup.

## Inner stretch, not new outer geometry

The existing `.hud-bottom-panel` is now a column flex container. Its tabs cannot
shrink. Only the visible tab panel participates in flex layout and takes the
remaining height (`flex: 1 1 auto; min-height: 0`). The Information/Battle Info
`.game-log` is another growing column, with a nonshrinking title and a growing,
vertically scrolling messages area. `135px` is the messages area's flex basis
and minimum, preserving the original intrinsic small-screen frame size, **not**
a fixed height or maximum. No viewport-sized heights were introduced.

Server Info and Chat use the same visible-panel fill rule. Their placeholder
text/input stay at the top; hidden panels remain `display: none`. The log keeps
its original 4px top margin, and its bottom reaches the frame's content bottom
(6px inside the outer border). Long messages scroll internally without growing
the map, either sidebar, the lower row, or Details.

No outer grid placement, tracks, padding, gaps, map dimensions, sidebar widths,
Task33.1 row spans, Task33.2 desktop stretching, or breakpoints were changed:

- Above 1100px: centre-only lower row, wider Information beside Details, equal
  Info/Details heights, all desktop frame bottoms aligned.
- At/below 1100px: lower panels stack naturally.
- At/below 720px: the main layout also stacks.

At the prior production-like **1440×900 CSS pixel** viewport with Items active,
exact-base rectangles are unchanged:

| Element | x | y | width | height | bottom |
|---|---:|---:|---:|---:|---:|
| Map | 266 | 72 | 848 | 527.953125 | 599.953125 |
| Lower row | 266 | 607.953125 | 848 | 307.671875 | 915.625 |
| Information | 266 | 607.953125 | 540 | 307.671875 | 915.625 |
| Details | 814 | 607.953125 | 300 | 307.671875 | 915.625 |
| Items | 1122 | 72 | 300 | 843.625 | 915.625 |

Previously the log stopped at y=798.59375, leaving 111.03125px unused. It now
ends at y=909.625 (the content bottom). Tabs retain their 6px top offset;
Details heading retains its 9px offset. Log title/messages remain top-aligned.

As in the exact base, choosing Skill Tree/Passive Tree hides the tall inventory
and can naturally shorten the whole desktop frame: at 1440×900 both lower
panels are then 196.640625px tall, ending at 804.59375. All desktop rectangles
still match the base for that same tab. On stacked layouts, the newly requested
short skill/passive placeholder naturally makes Details shorter than the old
multi-line selected item. Tests permit **only** those content-driven height/bottom
reductions (Details, lower row, spanning left sidebar); map, Info, horizontal
geometry, tops and breakpoints still match the base. No forced mobile height
was added. Info is about 197px high on phones, not viewport-height.

## One Details panel and public hooks

The existing `.item-details-panel` and `itemDetailsTitle` remain the single
physical panel. Existing top-right buttons now carry `data-details-mode`:

| Tab | Mode | Title | Empty text |
|---|---|---|---|
| Items | `item` | Item Details | Select an inventory or equipped item. |
| Skill Tree | `skill` | Skill Details | Select a skill to view its details. |
| Passive Tree | `passive` | Passive Details | Select a passive skill to view its details. |

`ASCIIQuestItemHud.createDetailsController(document)` owns presentation mode
and three independent client-side selections. It delegates item rendering to
the unchanged `renderDetails` / `renderItemName` helpers: authoritative name,
rarity, item level, base type, stats, and subtype icons are not reconstructed.
A single additional hidden content section serves both skill and passive data.

Future tree UI code can use the public inventory controller:

```js
const details = window.ASCIIQuestInventoryController;
details.setSkillDetails({
    name: "Selected skill name",
    description: "Presentation supplied by the future tree UI",
    stat_lines: ["Optional display line"],
});
details.setPassiveDetails({ name: "Selected passive name" });
// Clear a selection:
details.setSkillDetails(null);
details.setPassiveDetails(null);
```

These setters retain the model without switching tabs or invoking a server.
They accept a nonblank string `name`, optional `description`, optional
`stat_lines`; invalid/empty selections produce the exact placeholder. Input
lines are copied, and all fields use `textContent`, never HTML. These are display
models, not new skill, passive, allocation, loadout or combat mechanics.

The low-level presentation hooks `setDetailsMode('item'|'skill'|'passive')`
(boolean success) and `detailsMode()` are also available on both controllers.
They control Details only; normal navigation still belongs to the existing
shared tab controller. Invalid modes leave the current mode unchanged.

Inventory/equipped selection remains owned by the inventory controller. State
refresh resolves a retained ID against the **current** inventory/equipment
projection so returning to Items cannot render a stale/removed item. Missing
selection returns to the normal item placeholder. Pagination and equipment
requests still occur only through their existing explicit controls, never
through a tab change or Details hook.

## RED / GREEN and regression results

All final required checks passed:

| Check | Result |
|---|---|
| `php tests/run.php` | **803 passed, 0 failed** |
| `node tests/Task33Test.js` | **14 passed, 0 failed** |
| `node tests/Task33DetailsTest.js` | **11 passed, 0 failed** |
| `node tests/ItemHudTest.js` | **21 passed, 0 failed** |
| `node tests/CombatHudTest.js` | **32 passed, 0 failed** |
| `node tests/ExplorationHudTest.js` | **30 passed, 0 failed** |
| PHP lint | **146 files**, no errors |
| JavaScript syntax | **10 files**, no errors |
| `git diff --check` | Passed |

Before implementation, the initial Details tests produced **2 passed / 6
failed** (missing mode/API behavior); the expanded final suite produces 11/0.
The browser RED assertion measured the 111.03125px unused log area against the
exact base. The corrected browser run passes structural fill, top alignment,
long-log overflow, and exact-base outer geometry checks.

The dependency-free Chromium/CDP browser suite now covers:

- **14 exploration viewport layouts**: 1440×900, plus widths 1920, 1440, 1280,
  1101, 1100, 1099, 1024, 800, 721, 720, 719, 390 and 320 at height 1100.
- **6 real combat-template layouts**: 1440×900 and widths 1101, 1100, 720, 390,
  320 at height 1100. The CLI-only combat fixture deliberately omits combat
  polling/commands; it tests real markup/CSS, not a running encounter.
- All three bottom tabs and all three Details modes at every viewport;
  centre-only placement, aligned desktop bottoms, equal desktop panel heights,
  correct responsive stacking, no horizontal page/inner overflow, top-aligned
  titles/placeholders/messages, disabled full-width chat input, and bounded
  phone/tablet panel height.
- **139 exact-base geometry snapshots**: 113 fully identical; 26 stacked
  skill/passive cases have only the documented natural content-height shrink.
- **5 tall-sidebar cases** (1400px Items): one-grid-gap placement, aligned frame
  bottoms, and inner log fill retained.
- **11 long-log cases**: 160 synthetic messages scroll inside the panel, start
  at the top padding and leave every outer rectangle unchanged, including phones
  and the combat template.
- Real DOM inventory/equipped selection, tab-return restoration, unavailable
  selection fallback, text-only future hooks, pagination and mocked equipment
  operations; exactly the original four explicit mock requests, no tab requests.
- **2 actual platform-font checks**, **2 glyph-fit checks**, retained icons.
- CDP observes **zero real inventory/equipment API requests**. No application
  server, authenticated session, production connection, or database is used.

Reproduce against an archive of the exact accepted base (not a mutable checkout):

```sh
BASE=/tmp/task33-3-base
OUT=/tmp/task33-3-browser
mkdir -p "$BASE"
git archive 4410d10f4f9e9f169c35bd03e496bab08a2f771f | tar -x -C "$BASE"
node tests/Task33BrowserTest.js "$OUT" --record-baseline --root="$BASE"
node tests/Task33BrowserTest.js "$OUT"
```

## Screenshot evidence and limitations

Committed local-fixture screenshots, manually visually inspected:

- [Items desktop](task33-3/after-desktop.png): 1440×900 viewport, full-page
  capture; inner log reaches the bottom while all frame bottoms align.
- [Skill desktop](task33-3/after-skill-desktop.png): same viewport, one panel,
  correct title and exact empty skill text.
- [Passive desktop](task33-3/after-passive-desktop.png): same viewport, one panel,
  correct title and exact empty passive text.
- [Combat desktop](task33-3/after-combat-desktop.png): same viewport, Battle Info
  uses the identical structural fill without changing the combat/map frame.
- [Mobile](task33-3/after-mobile.png): 390px emulated mobile viewport; normal
  stacked content, no horizontal overflow or enormous Information panel.

Full-page desktop image width can exclude the browser's 15px vertical scrollbar;
1440×900 is the emulated **viewport**, not a promise of PNG dimensions.
The user's original screenshot was not available; dimensions follow the existing
Task33.1/33.2 evidence. All names/data are synthetic local fixture values.

Raw logs, before image, 2× crops, `browser-results.json`, `geometry.json` and
`baseline-geometry.json` are local artifacts under
`/home/najcarg/Projects/ASCII_Quest_II/task33-3-evidence/` (browser assets in
`browser/`). These are not production/deployment evidence.

Residual risks: authenticated production flows, other browsers and other OS font
fallbacks were not exercised. Future skill/passive UIs must provide their own
selected display models; no real tree data source was introduced. Manual product
review of the committed screenshots is still required before publication.

Feature-only commit/push; stop for ChatGPT GitHub review. Main and production
remain untouched.
