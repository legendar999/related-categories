<?php
/**
 * akvarelatedcategories upgrade 1.0.2 -> 1.0.3
 *
 * i18n only (no DB change): the module's default (English) source strings were
 * previously Slovenian, so every admin -- regardless of employee language -- saw
 * Slovenian BO/FO copy. English is now the source language, with a proper
 * translations/sl.php file shipping the Slovenian text back for SL-language admins.
 * Also makes the FO block-title fallback (only used if a language was never
 * backfilled with a translated title) resolve per the CURRENT shop language
 * instead of always defaulting to Slovenian.
 * Pure code/translation change; nothing to migrate.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    return true;
}
