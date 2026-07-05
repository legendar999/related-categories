<?php
/**
 * akvarelatedcategories upgrade 1.0.0 -> 1.0.1
 *
 * Fixes the two issues of 1.0.0 on a multistore install:
 *   1. hooks were only registered for the install-context shop, so the block was
 *      invisible on the other shops -> re-register all FO hooks for EVERY shop.
 *   2. non-SL/EN languages fell back to the Slovenian title -> re-apply the block
 *      titles translated for every installed language.
 *
 * Idempotent: registerAllShopsHooks() and applyTitleDefaults() can run repeatedly.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    $ok = $module->registerAllShopsHooks();
    $module->applyTitleDefaults();

    return (bool) $ok;
}
