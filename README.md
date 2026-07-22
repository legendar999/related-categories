# Related Categories

SEO module for PrestaShop that adds internal-link blocks to category and product pages.

## What it does

- **Category pages** — shows a "related categories" block: the parent category, its
  siblings, and its children.
- **Product pages** — shows the categories the product belongs to.
- **Description inner-linking** (v1.1.0, opt-in) — automatically turns mentions of your
  active category names, wherever they appear inside category, product, or CMS page body
  text, into real internal links. No manual keyword list to maintain: the "glossary" is
  just your shop's own active categories. A configurable cap limits how many links get
  injected per page, picked either in reading order or at random, with each category
  linked at most once per page.

All links are real, crawlable `<a href>` (no `nofollow`, never hidden). The footer blocks
are styled as a small, muted block so they stay visually out of the way while still
passing internal link equity to your category tree; inline description links inherit
your theme's normal link styling.

## Requirements

- PrestaShop 1.7, 8, or 9
- Any theme. Bootstrap 5 themes (e.g. Hummingbird, the PS default since 1.7) get
  auto-matched colors from the bundled CSS; other themes fall back to sane defaults.

## Installation

**Easiest:** download the ready-made ZIP from the [Releases page](https://github.com/legendar999/related-categories/releases)
and upload it in Back office > Modules > Upload a module. No renaming needed.

If you install from source instead: the module's technical name is
`akvarelatedcategories` — PrestaShop requires the installed folder/zip to be named
exactly that, regardless of what this repository is called.

1. Download or clone this repository.
2. Zip it up so the top-level folder inside the zip is named `akvarelatedcategories`.
3. In your PrestaShop admin: **Modules > Module Manager > Upload a module**, and select
   the zip.

No configuration is required — sensible defaults are applied automatically.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| Enable module | On | Master on/off switch |
| Category block — max links | 10 | |
| Category block — include parent / siblings / children | All on | Toggle each independently |
| Product block — max links | 5 | |
| Block title | Per language | Shown above each block; auto-filled for 12 languages on install |
| Enable inline linking | **Off** | Master switch for description inner-linking (opt-in) |
| Apply to category / product / CMS | All on | Which content types get scanned, once enabled |
| Max links per page | 3 | Upper bound on injected links per description |
| Choose randomly | On | Random selection among matches vs. always the first ones |
| Minimum name length | 3 | Category names shorter than this are never auto-linked |
| One link per category | On | Never link the same category twice in one description |
| Allow self-link | Off | Whether a category page may link its own name |
| Include short description | Off | Also scan the product's short description |

## Multistore

Hooks are registered for every shop on install, so it works correctly out of the box on
a multistore setup — not just the shop that was active during install.

## Uninstalling

Removes only the module's own configuration. Categories and products are never touched.

## License

[AFL-3.0](LICENSE)
