<?php
/**
 * akvarelatedcategories upgrade 1.0.3 -> 1.1.0
 *
 * Adds v2: description inner-linking (AkvarcDescriptionLinker) -- auto-links mentions of active
 * category names inside category/product/CMS body text. Registers the three new "filter*Content"
 * hooks for every shop and seeds the new AKVARC_IL_* config keys. The feature itself ships
 * disabled (AKVARC_IL_ENABLED=0): existing installs render byte-identical descriptions until the
 * merchant opts in from the BO config panel.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    if (!$module->registerAllShopsHooks()) {
        return false;
    }

    $defaults = [
        'AKVARC_IL_ENABLED' => '0',
        'AKVARC_IL_CAT' => '1',
        'AKVARC_IL_PROD' => '1',
        'AKVARC_IL_CMS' => '1',
        'AKVARC_IL_MAX' => '3',
        'AKVARC_IL_RANDOM' => '1',
        'AKVARC_IL_MINLEN' => '3',
        'AKVARC_IL_ONCE' => '1',
        'AKVARC_IL_SELF' => '0',
        'AKVARC_IL_DESC_SHORT' => '0',
    ];
    foreach ($defaults as $key => $value) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateGlobalValue($key, $value);
        }
    }

    return true;
}
