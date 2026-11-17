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

The existing mode-specific information tabs now share a lower grid row with
Item Details, spanning the central game and Items columns. Information is the
wider left column; the right column matches the Items width. Below 1100px these
panels stack; below 720px the existing main grid stacks too. A viewport meta tag
makes these breakpoints usable on mobile. Existing log IDs and tab behavior are
retained. Chat remains unimplemented: its disabled, labelled input placeholder
fills the available content width; no chat transport or persistence was added.

At 1440px local browser width, information grew from 830×114.64px to
848×196.64px, and the combined lower row aligns exactly with game+Items edges.

## Validation and visual evidence

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
