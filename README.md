# Related Categories

SEO module for PrestaShop that adds internal-link blocks to category and product pages.

## What it does

- **Category pages** — shows a "related categories" block: the parent category, its
  siblings, and its children.
- **Product pages** — shows the categories the product belongs to.

Both blocks are real, crawlable `<a href>` links (no `nofollow`, never hidden), styled
as a small, muted footer so they stay visually out of the way while still passing
internal link equity to your category tree.

## Requirements

- PrestaShop 1.7, 8, or 9
- Any theme. Bootstrap 5 themes (e.g. Hummingbird, the PS default since 1.7) get
  auto-matched colors from the bundled CSS; other themes fall back to sane defaults.

## Installation

The module's technical name is `akvarelatedcategories` — PrestaShop requires the
installed folder/zip to be named exactly that, regardless of what this repository is
called.

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

## Multistore

Hooks are registered for every shop on install, so it works correctly out of the box on
a multistore setup — not just the shop that was active during install.

## Uninstalling

Removes only the module's own configuration. Categories and products are never touched.

## License

[AFL-3.0](LICENSE)
