<?php
/**
 * akvarelatedcategories upgrade 1.1.0 -> 1.1.1
 *
 * Adds a category exclude list for description inner-linking (AKVARC_IL_EXCLUDE_IDS):
 * merchant-picked categories that never become auto-link targets, even if their name
 * appears in body text. Needed because AKVARC_IL_MINLEN (character-length filter) doesn't
 * catch generic spec-style category names that are long enough in characters but still
 * too broad to be useful auto-link anchors (e.g. size/diameter categories). No hook
 * changes; existing behaviour is unaffected until the merchant picks categories in BO.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_1($module)
{
    if (!Configuration::hasKey('AKVARC_IL_EXCLUDE_IDS')) {
        Configuration::updateGlobalValue('AKVARC_IL_EXCLUDE_IDS', '');
    }

    return true;
}
