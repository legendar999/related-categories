<?php
/**
 * akvarelatedcategories upgrade 1.1.3 -> 1.2.0
 *
 * FEATURE: click tracking on the generated internal links (SEO audit gap -- the link blocks and
 * inline auto-links were plain <a> tags with zero instrumentation, so internal click-through data
 * was invisible).
 *   - Every anchor now carries stable data attributes:
 *       data-akvarc-category-id  (target category id)
 *       data-akvarc-source       ('related_block' for renderBlock links, 'description_link' for the
 *                                 AkvarcDescriptionLinker inline auto-links)
 *       data-akvarc-variant      ('category' | 'product' | 'cms' -- the page type it renders on)
 *   - New views/js/front.js: one delegated click listener that pushes
 *       { event: 'related_category_click', category_id, source, variant }
 *     to window.dataLayer (defensive try/catch, no consent logic -- the shared GTM container owned
 *     + consent-gated by akvamarketing is already loaded on every page). Enqueued as a raw,
 *     versioned <script defer> in hookDisplayHeader (same CCC-safe mechanism as the CSS), on
 *     category/product pages plus CMS pages when inline description-linking is active.
 *
 * Code + asset change only. No config keys, no DB, no schema change -- this upgrade just registers
 * the new disk version.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    return true;
}
