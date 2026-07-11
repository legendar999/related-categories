<?php
/**
 * akvarelatedcategories upgrade 1.1.2 -> 1.1.3
 *
 * BO-only fix: the exclude-list picker's category query joined `category_lang` without scoping
 * to a shop, so a multistore install (category_lang is keyed id_category+id_lang+id_shop) showed
 * each category name once PER ACTIVE SHOP sharing that language -- 4x duplicates on this install.
 * Fixed with GROUP BY + MIN(name). No config/data change.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_3($module)
{
    return true;
}
