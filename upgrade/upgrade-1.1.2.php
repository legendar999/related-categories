<?php
/**
 * akvarelatedcategories upgrade 1.1.1 -> 1.1.2
 *
 * BO-only fix: replaced the "never link to these categories" native `<select multiple>` picker
 * with a search + add/remove editor (no JS framework, inline vanilla JS). The v1.1.1 multi-select
 * was effectively unusable once the catalogue had 100+ categories -- finding and Ctrl/Cmd-clicking
 * the right ~20 items in a scrolling list is not a workable admin flow. Same POSTed field shape
 * (AKVARC_IL_EXCLUDE_IDS[]), same AKVARC_IL_EXCLUDE_IDS config key -- no data migration needed.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_2($module)
{
    return true;
}
