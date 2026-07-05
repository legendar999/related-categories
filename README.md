# akvarelatedcategories

SEO internal-linking module for PrestaShop 1.7 / 8 / 9.

Adds two small, crawlable internal-link blocks, de-emphasised at the **bottom** of the
page (never hidden — hiding internal links is cloaking and risks a manual penalty):

- **Category pages** (`displayFooterCategory`): related categories — parent category,
  sibling categories (same parent), and child categories.
- **Product pages** (`displayFooterProduct`): the categories the product belongs to.

Both blocks render as real `<a href>` links with keyword-rich anchor text (the category
name), wrapped in a semantic `<nav aria-label>`, with no `nofollow` — the goal is to let
internal link equity flow to deeper category pages.

## Why

Search engines discover and rank pages partly through internal link structure. Many
PrestaShop catalogs under-link their own category tree, leaving related/sibling/child
categories reachable only through the main menu. This module adds a lightweight,
theme-native internal-linking layer with zero configuration required to get useful
defaults.

## Requirements

- PrestaShop `1.7.0.0` – current (see `config.xml` / module header for the exact range).
- A Bootstrap-5-based theme (e.g. Hummingbird, the PS 1.7+/8/9 default theme) for the
  bundled CSS to inherit theme colors automatically. The module still works on other
  themes; it falls back to sane default colors.

## Install

1. Zip the module folder as `akvarelatedcategories.zip` (the zip's top-level folder must
   be named `akvarelatedcategories`).
2. Upload via **Modules > Module Manager > Upload a module** (or drop the folder into
   `modules/akvarelatedcategories/` and install from the module list).
3. No configuration is required — sensible defaults are applied on install. Open the
   module's **Configure** screen to adjust:
   - Master on/off switch.
   - Category block: max links (default 10), whether to include parent / siblings /
     children, per-language block title.
   - Product block: max category links (default 5), per-language block title.

## Multistore

The module registers its hooks for **every shop** on install, not just the
install-context shop, so it renders correctly on every shop in a multistore setup out
of the box.

## Uninstall

Uninstalling removes only the module's own `Configuration` settings
(`AKVARC_*` keys). Your categories and products are never touched.

## License

[Academic Free License 3.0 (AFL-3.0)](LICENSE) — the standard PrestaShop module license.

## Author

Akva Modules
