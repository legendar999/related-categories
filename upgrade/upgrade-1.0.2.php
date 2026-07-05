<?php
/**
 * akvarelatedcategories upgrade 1.0.1 -> 1.0.2
 *
 * SEO/a11y refinement only (no DB change): the block title is now a styled <p>
 * instead of an <h2> (keeps the small related-links footer out of the page
 * heading outline), and the muted link/title colour was darkened to clear WCAG AA.
 * Both are pure front-office rendering/CSS changes, so this upgrade is a no-op
 * other than bumping the recorded module version.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    return true;
}
