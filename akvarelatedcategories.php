<?php
/**
 * akvarelatedcategories -- SEO internal-linking module.
 *
 * Adds two crawlable internal-link blocks, de-emphasised at the BOTTOM of the page:
 *   - Category archive (displayFooterCategory): up to N "related categories" =
 *     parent (nadkategorija) + siblings (sokategorije) + children (podkategorije).
 *   - Product page (displayFooterProduct): up to M categories the product belongs to.
 *
 * SEO design: real <a href> links with keyword-rich anchors (the category names), wrapped in a
 * semantic <nav aria-label>, no nofollow (we WANT internal equity to flow). The block is muted +
 * small + placed low, NOT hidden -- truly hiding internal links (display:none / 1px / colour on
 * background) is cloaking and risks a manual penalty, so we de-emphasise instead.
 *
 * FO: Hummingbird-native styling (Bootstrap-5 CSS variables) loaded as a RAW <link> in
 * displayHeader with ?v=<version> -- this BYPASSES PrestaShop CCC (Combine-Compress-Cache),
 * which otherwise serves a stale combined file for addCSS/addJS assets.
 * BO: native PS9 panel/form chrome.
 *
 * No external API, no DB writes beyond the module's own Configuration keys, no core override.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Akvarelatedcategories extends Module
{
    public function __construct()
    {
        $this->name = 'akvarelatedcategories';
        $this->tab = 'seo';
        $this->version = '1.0.2';
        $this->author = 'Akva Modules';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Povezane kategorije (SEO notranje povezave)');
        $this->description = $this->l('SEO notranje povezave: pod vsako kategorijo prikaže povezane kategorije (nadkategorija, sokategorije, podkategorije), pod izdelkom pa kategorije, v katere spada. Diskretno na dnu, polno indeksabilno.');
        $this->confirmUninstall = $this->l('Odstraniti modul? Odstranijo se le njegove nastavitve; kategorije in izdelki ostanejo nedotaknjeni.');
    }

    // ------------------------------------------------------------------
    // Install / uninstall
    // ------------------------------------------------------------------

    /** Hooks this module renders on every page type. */
    private const HOOKS = ['displayHeader', 'displayFooterCategory', 'displayFooterProduct'];

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }
        // Register for EVERY shop, not just the install-context shop: a module
        // installed while a single shop is selected otherwise gets hook_module
        // rows for that shop only and stays invisible on the others (the bug this
        // version fixes). registerAllShopsHooks() is idempotent + multishop-safe.
        if (!$this->registerAllShopsHooks()) {
            return false;
        }
        $this->installDefaults();

        return true;
    }

    /**
     * (Re)register every FO hook for ALL shops. Safe to call repeatedly (the BO
     * upgrade path calls it too). Returns false only on a hard failure.
     */
    public function registerAllShopsHooks(): bool
    {
        $shopList = Shop::getCompleteListOfShopsID();
        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook, $shopList ?: null)) {
                return false;
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        foreach ([
            'AKVARC_ENABLED', 'AKVARC_CAT_MAX', 'AKVARC_PROD_MAX',
            'AKVARC_INC_PARENT', 'AKVARC_INC_SIBLINGS', 'AKVARC_INC_CHILDREN',
            'AKVARC_CAT_TITLE', 'AKVARC_PROD_TITLE',
        ] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function installDefaults(): void
    {
        Configuration::updateGlobalValue('AKVARC_ENABLED', '1');
        Configuration::updateGlobalValue('AKVARC_CAT_MAX', '10');
        Configuration::updateGlobalValue('AKVARC_PROD_MAX', '5');
        Configuration::updateGlobalValue('AKVARC_INC_PARENT', '1');
        Configuration::updateGlobalValue('AKVARC_INC_SIBLINGS', '1');
        Configuration::updateGlobalValue('AKVARC_INC_CHILDREN', '1');

        $this->applyTitleDefaults();
    }

    /**
     * (Re)write the per-language block titles, translated for every installed
     * language (not just SL/EN). Public so the BO upgrade path can re-apply it to
     * an existing install. Overwrites existing values on purpose -- the goal is to
     * replace the old SL-fallback titles with correct translations.
     */
    public function applyTitleDefaults(): void
    {
        $catTitle = [];
        $prodTitle = [];
        foreach (Language::getLanguages(false) as $lang) {
            $t = self::titlesForIso((string) ($lang['iso_code'] ?? ''), (string) ($lang['language_code'] ?? ''));
            $catTitle[(int) $lang['id_lang']] = $t['cat'];
            $prodTitle[(int) $lang['id_lang']] = $t['prod'];
        }
        Configuration::updateGlobalValue('AKVARC_CAT_TITLE', $catTitle);
        Configuration::updateGlobalValue('AKVARC_PROD_TITLE', $prodTitle);
    }

    /**
     * Customer-facing block titles for one language, keyed by ISO-639-1. Handles
     * the country-like iso codes some PS installs carry (si=Slovenian, cz=Czech).
     * Falls back to English (never to Slovenian) for an unmapped language.
     *
     * @return array{cat:string,prod:string}
     */
    private static function titlesForIso(string $iso, string $languageCode = ''): array
    {
        $alias = [
            'si' => 'sl', 'cz' => 'cs', 'slo' => 'sl', 'svk' => 'sk', 'hrv' => 'hr',
            'hun' => 'hu', 'deu' => 'de', 'ger' => 'de', 'ita' => 'it', 'eng' => 'en', 'gb' => 'en',
        ];
        $cat = [
            'sl' => 'Povezane kategorije', 'en' => 'Related categories', 'hr' => 'Povezane kategorije',
            'it' => 'Categorie correlate', 'de' => 'Verwandte Kategorien', 'cs' => 'Související kategorie',
            'hu' => 'Kapcsolódó kategóriák', 'sk' => 'Súvisiace kategórie',
            'es' => 'Categorías relacionadas', 'fr' => 'Catégories associées',
            'pl' => 'Powiązane kategorie', 'nl' => 'Gerelateerde categorieën',
        ];
        $prod = [
            'sl' => 'Kategorije', 'en' => 'Categories', 'hr' => 'Kategorije', 'it' => 'Categorie',
            'de' => 'Kategorien', 'cs' => 'Kategorie', 'hu' => 'Kategóriák', 'sk' => 'Kategórie',
            'es' => 'Categorías', 'fr' => 'Catégories', 'pl' => 'Kategorie', 'nl' => 'Categorieën',
        ];
        $key = strtolower(trim($iso));
        if ($key === '' && $languageCode !== '') {
            $key = strtolower(substr($languageCode, 0, 2)); // e.g. "de-DE" -> "de"
        }
        $key = $alias[$key] ?? $key;

        return [
            'cat' => $cat[$key] ?? $cat['en'],
            'prod' => $prod[$key] ?? $prod['en'],
        ];
    }

    private function enabled(): bool
    {
        return (string) Configuration::get('AKVARC_ENABLED') === '1';
    }

    // ------------------------------------------------------------------
    // Front-office hooks
    // ------------------------------------------------------------------

    /**
     * Load the FO stylesheet as a raw, versioned <link> (CCC-safe).
     */
    public function hookDisplayHeader($params): string
    {
        if (!$this->enabled()) {
            return '';
        }

        // SPEED (2026-06-26): the related-categories block only renders on category +
        // product pages (displayFooterCategory / displayFooterProduct). Loading its CSS on
        // every page (home, CMS, ...) made it a needless render-blocking request. Gate it.
        $self = isset($this->context->controller->php_self) ? (string) $this->context->controller->php_self : '';
        if ($self !== 'category' && $self !== 'product') {
            return '';
        }

        return '<link rel="stylesheet" type="text/css" href="'
            . htmlspecialchars($this->_path . 'views/css/front.css', ENT_QUOTES, 'UTF-8')
            . '?v=' . rawurlencode($this->version) . '" media="all">';
    }

    /**
     * Category archive footer: related categories (parent + siblings + children).
     */
    public function hookDisplayFooterCategory($params): string
    {
        try {
            if (!$this->enabled()) {
                return '';
            }
            $idLang = (int) $this->context->language->id;
            $idShop = (int) $this->context->shop->id;
            $idCategory = $this->currentCategoryId();
            if ($idCategory <= 0) {
                return '';
            }
            $max = max(1, (int) Configuration::get('AKVARC_CAT_MAX'));
            $items = $this->categoryRelated($idCategory, $idLang, $idShop, $max);
            if ($items === []) {
                return '';
            }
            $title = (string) Configuration::get('AKVARC_CAT_TITLE', $idLang);

            return $this->renderBlock($title, $items, 'category');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Product page footer: the categories the product belongs to.
     */
    public function hookDisplayFooterProduct($params): string
    {
        try {
            if (!$this->enabled()) {
                return '';
            }
            $idLang = (int) $this->context->language->id;
            $idShop = (int) $this->context->shop->id;
            $idProduct = $this->extractProductId($params);
            if ($idProduct <= 0) {
                return '';
            }
            $max = max(1, (int) Configuration::get('AKVARC_PROD_MAX'));
            $items = $this->productCategories($idProduct, $idLang, $max);
            if ($items === []) {
                return '';
            }
            $title = (string) Configuration::get('AKVARC_PROD_TITLE', $idLang);

            return $this->renderBlock($title, $items, 'product');
        } catch (Throwable $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // Data resolution
    // ------------------------------------------------------------------

    /**
     * Current category id on a category archive page.
     */
    private function currentCategoryId(): int
    {
        $controller = $this->context->controller;
        if (isset($controller->category)
            && Validate::isLoadedObject($controller->category)) {
            return (int) $controller->category->id;
        }

        return (int) Tools::getValue('id_category');
    }

    /**
     * @param array<string,mixed> $params
     */
    private function extractProductId(array $params): int
    {
        $product = $params['product'] ?? null;
        if (is_array($product)) {
            return (int) ($product['id_product'] ?? $product['id'] ?? 0);
        }
        if (is_object($product)) {
            return (int) ($product->id ?? 0);
        }

        return (int) Tools::getValue('id_product');
    }

    /**
     * Related categories for a category page: parent (nadkategorija), siblings (sokategorije)
     * and children (podkategorije), de-duplicated, root/home excluded, capped at $max.
     *
     * @return list<array{id:int,name:string,url:string}>
     */
    private function categoryRelated(int $idCategory, int $idLang, int $idShop, int $max): array
    {
        $exclude = $this->excludedCategoryIds();
        $exclude[$idCategory] = true;

        $cat = new Category($idCategory, $idLang, $idShop);
        if (!Validate::isLoadedObject($cat)) {
            return [];
        }
        $idParent = (int) $cat->id_parent;

        $items = [];
        $seen = [];

        $add = function (int $id, ?string $name, ?string $rewrite) use (&$items, &$seen, $exclude, $idLang): void {
            if ($id <= 0 || isset($exclude[$id]) || isset($seen[$id]) || $name === null || $name === '') {
                return;
            }
            $seen[$id] = true;
            $items[] = [
                'id' => $id,
                'name' => (string) $name,
                'url' => (string) $this->context->link->getCategoryLink($id, $rewrite, $idLang),
            ];
        };

        // Parent first (most relevant), then siblings (same level), then children.
        if ((string) Configuration::get('AKVARC_INC_PARENT') === '1' && $idParent > 0 && !isset($exclude[$idParent])) {
            $parent = new Category($idParent, $idLang, $idShop);
            if (Validate::isLoadedObject($parent)) {
                $add($idParent, $parent->name, $parent->link_rewrite);
            }
        }
        if ((string) Configuration::get('AKVARC_INC_SIBLINGS') === '1' && $idParent > 0) {
            foreach ($this->childrenOf($idParent, $idLang, $idShop) as $row) {
                $add((int) $row['id_category'], $row['name'] ?? null, $row['link_rewrite'] ?? null);
            }
        }
        if ((string) Configuration::get('AKVARC_INC_CHILDREN') === '1') {
            foreach ($this->childrenOf($idCategory, $idLang, $idShop) as $row) {
                $add((int) $row['id_category'], $row['name'] ?? null, $row['link_rewrite'] ?? null);
            }
        }

        return array_slice($items, 0, $max);
    }

    /**
     * Categories a product belongs to (excluding root/home), capped at $max.
     *
     * @return list<array{id:int,name:string,url:string}>
     */
    private function productCategories(int $idProduct, int $idLang, int $max): array
    {
        $exclude = $this->excludedCategoryIds();
        $rows = Product::getProductCategoriesFull($idProduct, $idLang);
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id_category'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            if ($id <= 0 || isset($exclude[$id]) || $name === '') {
                continue;
            }
            $items[] = [
                'id' => $id,
                'name' => $name,
                'url' => (string) $this->context->link->getCategoryLink($id, $row['link_rewrite'] ?? null, $idLang),
            ];
            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    /**
     * Active children of a category in the current shop. Tolerant of PS signature variance.
     *
     * @return array<int,array<string,mixed>>
     */
    private function childrenOf(int $idParent, int $idLang, int $idShop): array
    {
        try {
            $rows = Category::getChildren($idParent, $idLang, true, $idShop);

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Root + home category ids to never link (they are not useful "related" targets).
     *
     * @return array<int,bool> id => true
     */
    private function excludedCategoryIds(): array
    {
        $out = [];
        foreach (['PS_ROOT_CATEGORY', 'PS_HOME_CATEGORY'] as $k) {
            $id = (int) Configuration::get($k);
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        // Defensive: the conventional root/home ids.
        $out[1] = true;
        $out[2] = true;

        return $out;
    }

    // ------------------------------------------------------------------
    // Rendering (Hummingbird-native, SEO-correct)
    // ------------------------------------------------------------------

    /**
     * @param list<array{id:int,name:string,url:string}> $items
     */
    private function renderBlock(string $title, array $items, string $variant): string
    {
        if ($items === []) {
            return '';
        }
        $label = $title !== '' ? $title : ($variant === 'product' ? 'Kategorije' : 'Povezane kategorije');

        $h = '<nav class="akva-rc akva-rc--' . self::esc($variant) . '" aria-label="' . self::esc($label) . '">';
        // De-emphasised supplementary nav: a styled <p>, NOT an <h2>. A small muted
        // related-links footer is not a top-level page section, so keeping it OUT of the
        // heading outline avoids over-stating it and adding heading-keyword noise on every
        // category/product page. The nav's aria-label still names the region for assistive
        // tech, so no accessible name is lost.
        $h .= '<p class="akva-rc__title">' . self::esc($label) . '</p>';
        $h .= '<ul class="akva-rc__list">';
        foreach ($items as $it) {
            $h .= '<li class="akva-rc__item"><a class="akva-rc__link" href="'
                . self::esc($it['url']) . '">' . self::esc($it['name']) . '</a></li>';
        }
        $h .= '</ul></nav>';

        return $h;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // Back office (PS9-native config)
    // ------------------------------------------------------------------

    public function getContent(): string
    {
        $flash = '';
        if (Tools::isSubmit('submitAkvarc')) {
            $flash = $this->processSave();
        }

        return $flash . $this->renderConfig();
    }

    private function processSave(): string
    {
        Configuration::updateGlobalValue('AKVARC_ENABLED', Tools::getValue('AKVARC_ENABLED') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_INC_PARENT', Tools::getValue('AKVARC_INC_PARENT') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_INC_SIBLINGS', Tools::getValue('AKVARC_INC_SIBLINGS') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_INC_CHILDREN', Tools::getValue('AKVARC_INC_CHILDREN') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_CAT_MAX', (string) max(1, min(50, (int) Tools::getValue('AKVARC_CAT_MAX'))));
        Configuration::updateGlobalValue('AKVARC_PROD_MAX', (string) max(1, min(50, (int) Tools::getValue('AKVARC_PROD_MAX'))));

        $catTitle = [];
        $prodTitle = [];
        foreach (Language::getLanguages(false) as $lang) {
            $id = (int) $lang['id_lang'];
            $catTitle[$id] = trim((string) Tools::getValue('AKVARC_CAT_TITLE_' . $id));
            $prodTitle[$id] = trim((string) Tools::getValue('AKVARC_PROD_TITLE_' . $id));
        }
        Configuration::updateGlobalValue('AKVARC_CAT_TITLE', $catTitle);
        Configuration::updateGlobalValue('AKVARC_PROD_TITLE', $prodTitle);

        return '<div class="alert alert-success">' . self::esc($this->l('Nastavitve shranjene.')) . '</div>';
    }

    private function renderConfig(): string
    {
        $languages = Language::getLanguages(false);
        $catMax = (int) Configuration::get('AKVARC_CAT_MAX');
        $prodMax = (int) Configuration::get('AKVARC_PROD_MAX');

        $sw = function (string $name, string $onLabel, string $offLabel): string {
            $on = (string) Configuration::get($name) === '1';

            return '<span class="switch prestashop-switch fixed-width-lg">'
                . '<input type="radio" name="' . self::esc($name) . '" id="' . self::esc($name) . '_on" value="1"' . ($on ? ' checked="checked"' : '') . '>'
                . '<label for="' . self::esc($name) . '_on">' . self::esc($onLabel) . '</label>'
                . '<input type="radio" name="' . self::esc($name) . '" id="' . self::esc($name) . '_off" value="0"' . ($on ? '' : ' checked="checked"') . '>'
                . '<label for="' . self::esc($name) . '_off">' . self::esc($offLabel) . '</label>'
                . '<a class="slide-button btn"></a></span>';
        };

        $langInputs = function (string $prefix, string $cfgKey) use ($languages): string {
            $out = '';
            foreach ($languages as $lang) {
                $id = (int) $lang['id_lang'];
                $val = (string) Configuration::get($cfgKey, $id);
                $out .= '<div class="input-group" style="margin-bottom:6px;max-width:520px;">'
                    . '<span class="input-group-addon">' . self::esc((string) $lang['iso_code']) . '</span>'
                    . '<input type="text" class="form-control" name="' . self::esc($prefix . $id) . '" value="' . self::esc($val) . '" maxlength="120">'
                    . '</div>';
            }

            return $out;
        };

        $row = function (string $label, string $hint, string $control): string {
            return '<div class="form-group row">'
                . '<label class="col-lg-3 control-label">' . self::esc($label) . '</label>'
                . '<div class="col-lg-9">' . $control
                . ($hint !== '' ? '<p class="help-block">' . self::esc($hint) . '</p>' : '')
                . '</div></div>';
        };

        $body = $row($this->l('Vključi modul'), $this->l('Glavno stikalo. Izklop skrije oba bloka povezav.'), $sw('AKVARC_ENABLED', $this->l('Da'), $this->l('Ne')))
            . '<hr><h4>' . self::esc($this->l('Kategorija (pod izdelki v arhivu)')) . '</h4>'
            . $row($this->l('Število povezav'), $this->l('Največje skupno število povezanih kategorij (privzeto 10).'),
                '<input type="number" min="1" max="50" class="form-control fixed-width-sm" name="AKVARC_CAT_MAX" value="' . $catMax . '">')
            . $row($this->l('Vključi nadkategorijo'), '', $sw('AKVARC_INC_PARENT', $this->l('Da'), $this->l('Ne')))
            . $row($this->l('Vključi sokategorije'), $this->l('Kategorije z isto nadkategorijo.'), $sw('AKVARC_INC_SIBLINGS', $this->l('Da'), $this->l('Ne')))
            . $row($this->l('Vključi podkategorije'), '', $sw('AKVARC_INC_CHILDREN', $this->l('Da'), $this->l('Ne')))
            . $row($this->l('Naslov bloka'), $this->l('Za vsak jezik. Pojavi se nad povezavami.'), $langInputs('AKVARC_CAT_TITLE_', 'AKVARC_CAT_TITLE'))
            . '<hr><h4>' . self::esc($this->l('Izdelek (na dnu strani izdelka)')) . '</h4>'
            . $row($this->l('Število kategorij'), $this->l('Največje število kategorij, v katere izdelek spada (privzeto 5).'),
                '<input type="number" min="1" max="50" class="form-control fixed-width-sm" name="AKVARC_PROD_MAX" value="' . $prodMax . '">')
            . $row($this->l('Naslov bloka'), $this->l('Za vsak jezik.'), $langInputs('AKVARC_PROD_TITLE_', 'AKVARC_PROD_TITLE'));

        return '<form method="post" class="form-horizontal">'
            . '<div class="panel">'
            . '<div class="panel-heading"><i class="icon-link"></i> ' . self::esc($this->l('Povezane kategorije -- SEO notranje povezave')) . '</div>'
            . '<div class="alert alert-info">' . self::esc($this->l('Bloki so diskretni (drobni, sivi, na dnu) a polno indeksabilni -- prave povezave z imeni kategorij kot sidrnim besedilom. Namerno NISO skriti: skrivanje notranjih povezav je prikrivanje (cloaking) in tvega kazen.')) . '</div>'
            . $body
            . '<div class="panel-footer"><button type="submit" name="submitAkvarc" class="btn btn-primary pull-right"><i class="process-icon-save"></i> ' . self::esc($this->l('Shrani')) . '</button></div>'
            . '</div></form>';
    }
}
