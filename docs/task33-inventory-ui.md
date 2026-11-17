# Task 33: inventory UI and subtype icons

Presentation-only changes. No item/equipment authority, formulas, names, affixes,
DB schema/data, maintenance tools or Task32 behavior changed.

## Rendering

`ascii-quest/js/item_hud.js::renderItemName` owns the one frozen icon mapping.
It reads `base_type` (the public `ItemProjector` projection of authoritative
`snapshot_subtype`), never `display_name` or internal definition/provenance keys:

| Subtype | Icon |
|---|---|
| sword | ⯝ |
| dagger | † |
| mace | 𓉒 |
| wand | ‡ |
| shield | ⛨ |
| gloves | ☞ |
| boots | ⛛ |
| belt | ▬ |
| ring | ◎ |
| amulet | ◈ |
| charm | ❖ |

The helper rebuilds decorative `aria-hidden` symbol and selectable name spans
for inventory, details and equipped names. It does not prefix stored names or
reconstruct affix names. Re-rendering cannot accumulate decorations. Unmapped
subtypes keep their previous inventory/equipment glyph and plain details name.
A literal symbol already in authoritative name data is not stripped or changed.

## Layout

The existing mode-specific information tabs share a lower grid row with Item
Details, constrained to the central map column only (corrected in Task33.1).
Information is the wider left column; Item Details retains
`clamp(220px, 21vw, 300px)`. At 1100px and below these panels stack; at 720px
and below the existing main grid stacks too. A viewport meta tag makes these
breakpoints usable on mobile. Existing log IDs and tab behavior are retained.
Chat remains unimplemented: its disabled, labelled input placeholder fills the
available content width; no chat transport or persistence was added.

At 1440px local browser width after Task33.1, the combined lower row is 848px
wide, matching the map; Info is 540×196.64px and Item Details is 300×196.64px.
The enlarged Info height and log area are retained.

## Original Task33 validation and visual evidence

- `php tests/run.php`: 803 passed, 0 failed.
- `node tests/ItemHudTest.js`: 21 passed; Combat HUD: 32; Exploration HUD: 30.
- `node tests/Task33Test.js`: RED 0 passed / 14 failed before implementation;
  GREEN 14 passed / 0 failed. Covers every mapping in all three views, preserved
  names/affixes, repeat-render icon count, unmapped types and markup location.
- PHP lint: 146 files; JavaScript syntax: 9 files; `git diff --check`: clean.
- `node tests/Task33BrowserTest.js OUTPUT_DIR`: dependency-free Chromium/CDP,
  CLI-only fixture renders the real game template without bootstrap/session/DB.
  Checks alignment, tabs, real DOM selection and mocked pagination/equip/unequip,
  plus no page overflow at 1024, 800, 720, 390 and 320px. Chat input fills its
  content width. Narrow-phone checks use mobile viewport emulation.

Before/after screenshots and raw test output are local artifacts in
`/home/najcarg/Projects/ASCII_Quest_II/task33-evidence/` (not deployment evidence):
`before-desktop.png`, `after-desktop.png`, `after-mobile.png`,
`after-inventory-2x.png`, `after-details-2x.png`, `browser-results.json`.
The baseline fixture used exact base `df9fd19e9377bf368a0aa4c13eb80b897c9869f1`.

### Mace compatibility

Chromium 155 on this Linux workstation rendered **𓉒** in inventory and selected
details using the unchanged configured game font stack
`Georgia, "Times New Roman", serif`. CDP's actual platform-font inspection
identified `NotoSansEgyptianHieroglyphs-Regular` for the glyph (not merely a
`document.fonts.check` claim). Inspected desktop 2× crops show the hieroglyph,
not tofu; bounding rectangles confirm no line-height clipping (9px glyph box
inside the inventory cell; 14px inside the 16.8px details line).

This verifies the game's configured CSS stack locally, **not** the installed
fonts of every production user's browser. Other operating systems without an
Egyptian fallback may show tofu. No replacement symbol or font dependency was
silently introduced; the single mapping is easy to change after an explicit
compatibility decision. Authenticated production and live DB flows were not
accessed. Feature branch only; stop for ChatGPT review before promotion/deploy.

## Task33.1: centre-only lower panels

Base: `f46c8d7fc26b89fa36050b3b58d09b9f5bb22bca`.

The actual `.game-layout` has three columns: left HUD, `minmax(0, 1fr)` map,
right Items. `.hud-lower-row` changes from `grid-column: 2 / -1` to `2 / 3`.
Its internal `minmax(0, 1fr) clamp(220px, 21vw, 300px)` split is unchanged.

A column-only correction was insufficient: the 843.63px Items sidebar sized
row one, leaving 323.67px between the map bottom and lower row. Above 720px,
both sidebars now span grid rows `1 / 3`, with Items explicitly in column 3;
`grid-template-rows: auto 1fr` puts surplus sidebar height in row two rather
than between map and lower panels. The lower row follows the map with exactly
the existing 8px gap. These placement rules do not apply to the mobile grid.
No template, rendering, icon mapping, game logic, chat or Server Info code changed.

Database-free Chromium results at **1440×900 CSS pixels**, landscape:

- Map and lower row: left 266px, right 1114px, width 848px.
- Map bottom 599.95px; lower row top 607.95px: 8px gap.
- Info: 540×196.64px; Item Details: 300×196.64px, beside Info.
- Items: left 1122px, top 72px, width 300px, height 843.63px: unchanged from base.
- Screenshot visually inspected: lower panels align with the map, not Items.

Validation (all GREEN unless marked expected RED):

- Full PHP: **803 passed, 0 failed**.
- Focused Task33 icon/markup: **14 passed, 0 failed**.
- Item HUD: **21**; Combat HUD: **32**; Exploration HUD: **30**; all 0 failed.
- Updated Task33/33.1 browser: **11 viewport layouts**, **3 information tabs**,
  **1 tall-sidebar stress case**, synthetic inventory/equipped selection and
  pagination, **2 actual font checks**, **2 glyph-fit checks**, all passed.
  Widths: 1440, 1920, 1280, 1101, 1100, 1024, 800, 721, 720, 390, 320px.
  Centre-only edges and immediate vertical placement checked above 720px;
  stacked lower panels at/below 1100px, main stack at/below 720px. No page
  horizontal overflow at any tested width. A synthetic 1400px-tall Items panel
  still leaves only 8px beneath the map.
- PHP lint: **146 files**; JS syntax: **9 files**; `git diff --check`: clean.
- Expected RED before fix: lower right 1422px != map right 1114px.
- Expected RED with column-only fix: immediate-map vertical assertion failed.

Local artifacts (not production evidence):
`/home/najcarg/Projects/ASCII_Quest_II/task33-1-evidence/`.
Desktop screenshot: `green/after-desktop.png` (full-page capture from 1440×900
viewport); mobile: `green/after-mobile.png`; results: `green/browser-results.json`.
`browser-red.log` and `browser-column-only-red.log` capture the two expected
failures; `browser-green.log` and suite logs capture final successful runs.
The existing CLI-only real-template fixture and synthetic fetch responses make
no network application requests, DB connections or persistent mutations.
Authenticated production, other browsers and OS font fallbacks remain untested.
No main promotion, deployment, DB work or Task32 cleanup was performed.

## Task33.2: shared desktop bottom edge

Base: `0b183249a147410f730ec852e94fbeb6accec7bb`.

The parent grid deliberately keeps `align-items: start` so the map retains its
intrinsic height. Task33.1's spanning sidebars and `auto 1fr` tracks already put
surplus height in the lower track, but the lower row did not stretch into it.
Above 1100px, `.hud-lower-row` and `.hud-right-panel` now use
`align-self: stretch`. The left sidebar already stretches. The lower row's
existing nested grid stretches both Info and Item Details without distributing
or centring their content. Stretching Items also handles the case where centre
content determines the grid height. No fixed frame heights, map sizing, column
widths, template, rendering or runtime JavaScript changes were introduced.
At/below 1100px the new rule does not apply.

Database-free Chromium geometry (CSS px; every listed bottom equals the left,
Items, lower row, Info and Item Details bottoms exactly: **0px difference**):

| Viewport | Map bottom | Lower row top | Shared bottom | Lower row width |
|---|---:|---:|---:|---:|
| 1920×1100 | 648 | 656 | 915.625 | 1184 |
| 1440×900 | 599.953125 | 607.953125 | 915.625 | 848 |
| 1440×1100 | 599.953125 | 607.953125 | 915.625 | 848 |
| 1280×1100 | 551.90625 | 559.90625 | 884.453125 | 741.609375 |
| 1101×1100 | 528 | 536 | 846.875 | 630.640625 |

All five tall-Items stress cases (1400px synthetic minimum height) have shared
bottom **1472px**, with the same **8px** map-to-lower-row gap. A vertical
scrollbar can naturally reduce available width in stress cases; geometry is
compared with the exact base under the same stress, not a different scroll state.

The browser suite can record baseline geometry using `--record-baseline
--root=BASE_CHECKOUT` and compare a normal run in the same output directory.
An archive of the exact base was used here. Exact comparisons passed for:

- Both sidebars, map frame, actual `.game-map`, and first map cell rectangles.
- Lower-row/Info/Details horizontal geometry and tops; top content offsets.
- All measured geometry at/below 1100px, proving no forced tablet/mobile heights.

At 1440×900 Items remains `(1122,72)`, **300×843.625px**; map remains `(266,72)`,
**848×527.953125px**. Info is now **540×307.671875px**, Details
**300×307.671875px**, and both end at 915.625px. Their content remains at the
original top offsets (Info tabs 6px, Details heading 9px).

Validation:

- TDD RED against exact base: left bottom 915.625 != lower-row bottom 804.59375.
- GREEN: full PHP **803**; Task33 **14**; Item HUD **21**; Combat HUD **32**;
  Exploration HUD **30**, all zero failures.
- Task33/33.1/33.2 browser: **14 viewport layouts**, **3 tabs**, **5 tall-sidebar
  stress cases**, existing synthetic selection/equipment/pagination, **2 font**
  and **2 glyph-fit** checks. No application API requests or persistent mutations.
- Viewports: 1440×900, plus widths 1920, 1440, 1280, 1101, 1100, 1099, 1024,
  800, 721, 720, 719, 390, 320 at height 1100. No horizontal overflow.
  Info/Details stack at/below 1100; main stack remains at/below 720.
- PHP lint **146 files**, JS syntax **9 files**, `git diff --check`: passed.
- A Chromium shutdown/profile-removal race was observed after all browser
  assertions passed; bounded filesystem retries were added to test cleanup.
  The final browser run exited successfully.

Local evidence directory:
`/home/najcarg/Projects/ASCII_Quest_II/task33-2-evidence/`.
Screenshot `browser/after-desktop.png` is a full-page capture from a **1440×900**
viewport, visually inspected: all three frame bottoms align; lower panels stay
immediately beneath the unchanged map and content stays at the top. Mobile:
`browser/after-mobile.png`. Exact rectangles: `browser/geometry.json` and
`browser/baseline-geometry.json`; results: `browser/browser-results.json`.
Baseline/RED/GREEN and regression logs are also in this evidence directory.

Residual limitations: actual user screenshot was unavailable; evidence uses the
real template with a synthetic local state, not an authenticated production
session. Other browsers/OS font stacks remain untested. No production access,
main promotion, deployment, DB changes, migrations or Task32 cleanup performed.

## Task33.3: inner fill and context Details

Information/Battle Info now fill the existing frame with a top-aligned flex log;
Server Info and Chat use the same active-panel fill. The single lower-right
Details panel follows Items / Skill Tree / Passive Tree with independent,
presentation-only selection hooks and safe return-to-item restoration.
Task33.1/33.2 outer grid geometry and breakpoints are unchanged.

See [Task33.3 implementation, API, validation and committed screenshots](testing/task33-3-details-context-panel.md).
