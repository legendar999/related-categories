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

require_once __DIR__ . '/classes/AkvarcDescriptionLinker.php';

class Akvarelatedcategories extends Module
{
    public function __construct()
    {
        $this->name = 'akvarelatedcategories';
        $this->tab = 'seo';
        $this->version = '1.2.1';
        $this->author = 'Akva Modules';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Related categories (SEO internal links)');
        $this->description = $this->l('SEO internal links: shows related categories under each category page (parent, siblings, children), and the categories a product belongs to under each product page. Discreetly placed at the bottom, fully indexable.');
        $this->confirmUninstall = $this->l('Uninstall the module? Only its settings are removed; categories and products remain untouched.');
    }

    // ------------------------------------------------------------------
    // Install / uninstall
    // ------------------------------------------------------------------

    /** Hooks this module renders on every page type. */
    private const HOOKS = [
        'displayHeader', 'displayFooterCategory', 'displayFooterProduct',
        // v1.1.0 -- description inner-linking (see AkvarcDescriptionLinker).
        'filterCategoryContent', 'filterProductContent', 'filterCmsContent',
    ];

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
            'AKVARC_IL_ENABLED', 'AKVARC_IL_CAT', 'AKVARC_IL_PROD', 'AKVARC_IL_CMS',
            'AKVARC_IL_MAX', 'AKVARC_IL_RANDOM', 'AKVARC_IL_MINLEN', 'AKVARC_IL_ONCE',
            'AKVARC_IL_SELF', 'AKVARC_IL_DESC_SHORT', 'AKVARC_IL_EXCLUDE_IDS',
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

        // v1.1.0 -- description inner-linking, opt-in (rewriting body HTML is more aggressive
        // than the v1 footer block, so new installs get sane defaults but the feature itself
        // stays off until the merchant explicitly enables it).
        Configuration::updateGlobalValue('AKVARC_IL_ENABLED', '0');
        Configuration::updateGlobalValue('AKVARC_IL_CAT', '1');
        Configuration::updateGlobalValue('AKVARC_IL_PROD', '1');
        Configuration::updateGlobalValue('AKVARC_IL_CMS', '1');
        Configuration::updateGlobalValue('AKVARC_IL_MAX', '3');
        Configuration::updateGlobalValue('AKVARC_IL_RANDOM', '1');
        Configuration::updateGlobalValue('AKVARC_IL_MINLEN', '3');
        Configuration::updateGlobalValue('AKVARC_IL_ONCE', '1');
        Configuration::updateGlobalValue('AKVARC_IL_SELF', '0');
        Configuration::updateGlobalValue('AKVARC_IL_DESC_SHORT', '0');
        Configuration::updateGlobalValue('AKVARC_IL_EXCLUDE_IDS', '');

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
     * Load the FO stylesheet + click-tracking script as raw, versioned assets (CCC-safe).
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
        $blockPage = ($self === 'category' || $self === 'product');

        // Click tracking (v1.2.0) also needs to run on CMS pages, but ONLY when the inline
        // description-linking feature could have emitted trackable anchors there. The CSS gate
        // stays narrower (it only styles the block, which never renders on CMS).
        $cmsInline = $self === 'cms'
            && (string) Configuration::get('AKVARC_IL_ENABLED') === '1'
            && (string) Configuration::get('AKVARC_IL_CMS') === '1';

        if (!$blockPage && !$cmsInline) {
            return '';
        }

        $out = '';
        if ($blockPage) {
            $out .= '<link rel="stylesheet" type="text/css" href="'
                . htmlspecialchars($this->_path . 'views/css/front.css', ENT_QUOTES, 'UTF-8')
                . '?v=' . rawurlencode($this->version) . '" media="all">';
        }
        // Raw, versioned <script> (CCC-safe, same rationale as the CSS above), deferred so it
        // never blocks render. Delegated click listener -> window.dataLayer.
        $out .= '<script src="'
            . htmlspecialchars($this->_path . 'views/js/front.js', ENT_QUOTES, 'UTF-8')
            . '?v=' . rawurlencode($this->version) . '" defer></script>';

        return $out;
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

            return $this->renderBlock($title, $items, 'category', $idLang);
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

            return $this->renderBlock($title, $items, 'product', $idLang);
        } catch (Throwable $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // Description inner-linking (v1.1.0) -- see AkvarcDescriptionLinker.
    // ------------------------------------------------------------------

    /**
     * Category description: link mentions of other active category names.
     *
     * @param array{object:mixed} $params
     * @return array{object:mixed}
     */
    public function hookFilterCategoryContent($params)
    {
        try {
            if (!$this->descriptionLinkingActive('AKVARC_IL_CAT')) {
                return $params;
            }
            $obj = $params['object'] ?? null;
            if ($obj === null) {
                return $params;
            }
            $html = (string) ($obj['description'] ?? '');
            if ($html === '') {
                return $params;
            }
            $idLang = (int) $this->context->language->id;
            $idShop = (int) $this->context->shop->id;
            $idCategory = $this->currentCategoryId();
            $exclude = [];
            if ($idCategory > 0 && (string) Configuration::get('AKVARC_IL_SELF') !== '1') {
                $exclude[$idCategory] = true;
            }
            $glossary = $this->descriptionLinkGlossary($idLang, $idShop, $exclude);
            if ($glossary === []) {
                return $params;
            }
            $new = AkvarcDescriptionLinker::process($html, $glossary, $this->descriptionLinkOptions('cat', $idCategory, $idLang));
            if ($new !== $html) {
                $obj['description'] = $new;
            }

            return ['object' => $obj];
        } catch (Throwable $e) {
            return $params;
        }
    }

    /**
     * Product description (+ optionally description_short): link mentions of active categories.
     *
     * @param array{object:mixed} $params
     * @return array{object:mixed}
     */
    public function hookFilterProductContent($params)
    {
        try {
            if (!$this->descriptionLinkingActive('AKVARC_IL_PROD')) {
                return $params;
            }
            $obj = $params['object'] ?? null;
            if ($obj === null) {
                return $params;
            }
            $idLang = (int) $this->context->language->id;
            $idShop = (int) $this->context->shop->id;
            $glossary = $this->descriptionLinkGlossary($idLang, $idShop);
            if ($glossary === []) {
                return $params;
            }
            $idProduct = (int) ($obj['id_product'] ?? $obj['id'] ?? 0);
            $opts = $this->descriptionLinkOptions('prod', $idProduct, $idLang);
            $changed = false;

            $html = (string) ($obj['description'] ?? '');
            if ($html !== '') {
                $new = AkvarcDescriptionLinker::process($html, $glossary, $opts);
                if ($new !== $html) {
                    $obj['description'] = $new;
                    $changed = true;
                }
            }

            if ((string) Configuration::get('AKVARC_IL_DESC_SHORT') === '1') {
                $short = (string) ($obj['description_short'] ?? '');
                if ($short !== '') {
                    $newShort = AkvarcDescriptionLinker::process($short, $glossary, $opts);
                    if ($newShort !== $short) {
                        $obj['description_short'] = $newShort;
                        $changed = true;
                    }
                }
            }

            return $changed ? ['object' => $obj] : $params;
        } catch (Throwable $e) {
            return $params;
        }
    }

    /**
     * CMS page content: link mentions of active categories.
     *
     * @param array{object:mixed} $params
     * @return array{object:mixed}
     */
    public function hookFilterCmsContent($params)
    {
        try {
            if (!$this->descriptionLinkingActive('AKVARC_IL_CMS')) {
                return $params;
            }
            $obj = $params['object'] ?? null;
            if (!is_array($obj)) {
                return $params;
            }
            $html = (string) ($obj['content'] ?? '');
            if ($html === '') {
                return $params;
            }
            $idLang = (int) $this->context->language->id;
            $idShop = (int) $this->context->shop->id;
            $glossary = $this->descriptionLinkGlossary($idLang, $idShop);
            if ($glossary === []) {
                return $params;
            }
            $idCms = (int) ($obj['id_cms'] ?? $obj['id'] ?? 0);
            $new = AkvarcDescriptionLinker::process($html, $glossary, $this->descriptionLinkOptions('cms', $idCms, $idLang));
            if ($new === $html) {
                return $params;
            }
            $obj['content'] = $new;

            return ['object' => $obj];
        } catch (Throwable $e) {
            return $params;
        }
    }

    /** Master switch AND feature switch AND the given per-content-type switch. */
    private function descriptionLinkingActive(string $scopeKey): bool
    {
        return $this->enabled()
            && (string) Configuration::get('AKVARC_IL_ENABLED') === '1'
            && (string) Configuration::get($scopeKey) === '1';
    }

    /** @return array{max:int,random:bool,minLen:int,once:bool,entitySeed:string,variant:string,linkBuilder:callable} */
    private function descriptionLinkOptions(string $entityType, int $entityId, int $idLang): array
    {
        // The generated inline anchors carry a data-akvarc-variant reflecting the page type
        // they render on, so click tracking (views/js/front.js) can distinguish them.
        $variantMap = ['cat' => 'category', 'prod' => 'product', 'cms' => 'cms'];

        return [
            'max' => max(0, (int) Configuration::get('AKVARC_IL_MAX')),
            'random' => (string) Configuration::get('AKVARC_IL_RANDOM') === '1',
            'minLen' => max(1, (int) Configuration::get('AKVARC_IL_MINLEN')),
            'once' => (string) Configuration::get('AKVARC_IL_ONCE') === '1',
            'entitySeed' => $entityType . ':' . $entityId,
            'variant' => $variantMap[$entityType] ?? $entityType,
            'linkBuilder' => function (array $cat) use ($idLang): string {
                $rewrite = $cat['rewrite'] !== '' ? $cat['rewrite'] : null;

                return (string) $this->context->link->getCategoryLink($cat['id'], $rewrite, $idLang);
            },
        ];
    }

    /** @var array<string,list<array{id:int,name:string,rewrite:string}>> */
    private static $glossaryCache = [];

    /**
     * All active category names for a shop+lang -- the v2 "auto glossary" for inline description
     * linking. Root/home + any ids in $excludeIds are dropped. Memoised per request
     * ("$idShop:$idLang") only: ets_superspeed full-page cache means this query only runs on a
     * cache MISS, and the catalogue is small enough (dozens of categories) that a persistent
     * cross-request cache would add invalidation complexity for no measurable gain.
     *
     * @param array<int,bool> $excludeIds
     * @return list<array{id:int,name:string,rewrite:string}>
     */
    private function descriptionLinkGlossary(int $idLang, int $idShop, array $excludeIds = []): array
    {
        $cacheKey = $idShop . ':' . $idLang;
        if (!isset(self::$glossaryCache[$cacheKey])) {
            $exclude = $this->excludedCategoryIds() + $this->descriptionLinkExcludedTargetIds();
            $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT c.id_category, cl.name, cl.link_rewrite
                 FROM `' . _DB_PREFIX_ . 'category` c
                 INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                     ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $idLang . ' AND cl.id_shop = ' . (int) $idShop . '
                 INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                     ON cs.id_category = c.id_category AND cs.id_shop = ' . (int) $idShop . '
                 WHERE c.active = 1'
            );
            $items = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $id = (int) ($row['id_category'] ?? 0);
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($id <= 0 || $name === '' || isset($exclude[$id])) {
                        continue;
                    }
                    $items[] = ['id' => $id, 'name' => $name, 'rewrite' => (string) ($row['link_rewrite'] ?? '')];
                }
            }
            self::$glossaryCache[$cacheKey] = $items;
        }

        $items = self::$glossaryCache[$cacheKey];
        if ($excludeIds !== []) {
            $items = array_values(array_filter($items, static function (array $it) use ($excludeIds): bool {
                return !isset($excludeIds[$it['id']]);
            }));
        }

        return $items;
    }

    /**
     * Merchant-configured category ids that must never become auto-link TARGETS (their own
     * pages are unaffected -- this only removes them from the glossary of link-worthy names).
     * Typical use: short/generic spec-style category names (e.g. "FI 160" pipe-diameter
     * categories) that would otherwise over-match unrelated text even past AKVARC_IL_MINLEN,
     * because they're long enough in characters but not distinctive enough as a phrase.
     *
     * @return array<int,bool>
     */
    private function descriptionLinkExcludedTargetIds(): array
    {
        $raw = (string) Configuration::get('AKVARC_IL_EXCLUDE_IDS');
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        return $out;
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
    private function renderBlock(string $title, array $items, string $variant, int $idLang): string
    {
        if ($items === []) {
            return '';
        }
        if ($title !== '') {
            $label = $title;
        } else {
            // Only reached if a language was added after install/upgrade and never
            // backfilled -- fall back to a title in the SHOP's language, not a
            // fixed one, so a newly-added language never silently shows Slovenian.
            $iso = (string) Language::getIsoById($idLang);
            $t = self::titlesForIso($iso);
            $label = $variant === 'product' ? $t['prod'] : $t['cat'];
        }

        $h = '<nav class="akva-rc akva-rc--' . self::esc($variant) . '" aria-label="' . self::esc($label) . '">';
        // De-emphasised supplementary nav: a styled <p>, NOT an <h2>. A small muted
        // related-links footer is not a top-level page section, so keeping it OUT of the
        // heading outline avoids over-stating it and adding heading-keyword noise on every
        // category/product page. The nav's aria-label still names the region for assistive
        // tech, so no accessible name is lost.
        $h .= '<p class="akva-rc__title">' . self::esc($label) . '</p>';
        $h .= '<ul class="akva-rc__list">';
        // Stable tracking hooks (v1.2.0): a delegated front-end listener (views/js/front.js)
        // reads these to push a `related_category_click` dataLayer event. $variant is already
        // 'category' or 'product' here; the block links are always source="related_block".
        foreach ($items as $it) {
            $h .= '<li class="akva-rc__item"><a class="akva-rc__link" href="'
                . self::esc($it['url'])
                . '" data-akvarc-category-id="' . (int) $it['id'] . '"'
                . ' data-akvarc-source="related_block"'
                . ' data-akvarc-variant="' . self::esc($variant) . '">'
                . self::esc($it['name']) . '</a></li>';
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

        Configuration::updateGlobalValue('AKVARC_IL_ENABLED', Tools::getValue('AKVARC_IL_ENABLED') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_CAT', Tools::getValue('AKVARC_IL_CAT') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_PROD', Tools::getValue('AKVARC_IL_PROD') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_CMS', Tools::getValue('AKVARC_IL_CMS') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_MAX', (string) max(0, min(20, (int) Tools::getValue('AKVARC_IL_MAX'))));
        Configuration::updateGlobalValue('AKVARC_IL_RANDOM', Tools::getValue('AKVARC_IL_RANDOM') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_MINLEN', (string) max(1, min(40, (int) Tools::getValue('AKVARC_IL_MINLEN'))));
        Configuration::updateGlobalValue('AKVARC_IL_ONCE', Tools::getValue('AKVARC_IL_ONCE') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_SELF', Tools::getValue('AKVARC_IL_SELF') ? '1' : '0');
        Configuration::updateGlobalValue('AKVARC_IL_DESC_SHORT', Tools::getValue('AKVARC_IL_DESC_SHORT') ? '1' : '0');

        $excludeIds = [];
        foreach ((array) Tools::getValue('AKVARC_IL_EXCLUDE_IDS', []) as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $excludeIds[$id] = true;
            }
        }
        Configuration::updateGlobalValue('AKVARC_IL_EXCLUDE_IDS', implode(',', array_keys($excludeIds)));

        $catTitle = [];
        $prodTitle = [];
        foreach (Language::getLanguages(false) as $lang) {
            $id = (int) $lang['id_lang'];
            $catTitle[$id] = trim((string) Tools::getValue('AKVARC_CAT_TITLE_' . $id));
            $prodTitle[$id] = trim((string) Tools::getValue('AKVARC_PROD_TITLE_' . $id));
        }
        Configuration::updateGlobalValue('AKVARC_CAT_TITLE', $catTitle);
        Configuration::updateGlobalValue('AKVARC_PROD_TITLE', $prodTitle);

        return '<div class="alert alert-success">' . self::esc($this->l('Settings saved.')) . '</div>';
    }

    /**
     * Search-and-add / remove editor for which categories are excluded as inline-link targets.
     * A native `<select multiple>` (the v1.1.1 first cut) turned out unusable once the catalogue
     * had 100+ categories -- finding and Ctrl/Cmd-clicking ~20 items in a scrolling list is not a
     * realistic BO workflow. This replaces it with: a text search (native `<datalist>`
     * autocomplete, no AJAX -- the whole category list is small enough to embed once) + an "Add"
     * button, and each already-excluded category rendered as a row with a "-" remove button.
     * Removed/added categories are plain hidden `AKVARC_IL_EXCLUDE_IDS[]` inputs manipulated by
     * inline vanilla JS -- the POSTed field shape is unchanged, so processSave() needed no edit.
     */
    private function descriptionLinkExcludePicker(): string
    {
        $idLang = (int) $this->context->language->id;
        $selected = $this->descriptionLinkExcludedTargetIds();
        // `category_lang` is keyed (id_category, id_lang, id_shop) -- one row PER SHOP sharing the
        // same language, so a plain join without shop-scoping returns the same category name once
        // per active shop (4x on this install). GROUP BY + MIN() dedupes to one row per category
        // (names are shop-invariant in practice) and stays ONLY_FULL_GROUP_BY-safe.
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, MIN(cl.name) AS name
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                 ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $idLang . '
             WHERE c.active = 1
             GROUP BY c.id_category
             ORDER BY name ASC'
        );

        $names = [];
        $datalist = '';
        $chips = '';
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $id = (int) ($row['id_category'] ?? 0);
                $name = (string) ($row['name'] ?? '');
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $names[$id] = $name;
                $label = $name . ' (#' . $id . ')';
                $datalist .= '<option data-id="' . $id . '" value="' . self::esc($label) . '"></option>';
            }
        }
        foreach ($selected as $id => $_true) {
            $chips .= $this->descriptionLinkExcludeChip((int) $id, $names[$id] ?? ('#' . $id));
        }

        return '<div class="akvarc-il-exclude-editor">'
            . '<div class="input-group" style="max-width:520px;">'
            . '<input type="text" id="akvarcIlExcludeSearch" list="akvarcIlExcludeOptions" class="form-control" '
            . 'placeholder="' . self::esc($this->l('Type a category name...')) . '">'
            . '<span class="input-group-btn"><button type="button" id="akvarcIlExcludeAdd" class="btn btn-default">+</button></span>'
            . '</div>'
            . '<datalist id="akvarcIlExcludeOptions">' . $datalist . '</datalist>'
            . '<ul id="akvarcIlExcludeList" style="list-style:none;margin:8px 0 0;padding:0;max-width:520px;">' . $chips . '</ul>'
            . '</div>'
            . '<script>(function(){
    var list = document.getElementById("akvarcIlExcludeList");
    var search = document.getElementById("akvarcIlExcludeSearch");
    var addBtn = document.getElementById("akvarcIlExcludeAdd");
    var options = document.getElementById("akvarcIlExcludeOptions");

    function alreadyAdded(id) {
        return list.querySelector(\'li[data-id="\' + id + \'"]\') !== null;
    }

    function addRow(id, name) {
        id = String(id);
        if (alreadyAdded(id)) { return; }
        var li = document.createElement("li");
        li.setAttribute("data-id", id);
        li.style.cssText = "display:flex;align-items:center;justify-content:space-between;padding:5px 10px;border:1px solid #ddd;border-top:none;background:#fff;";
        var label = document.createElement("span");
        label.textContent = name;
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "btn btn-default btn-xs";
        btn.textContent = "−";
        btn.title = "' . self::esc($this->l('Remove')) . '";
        btn.addEventListener("click", function () { li.parentNode.removeChild(li); });
        var hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = "AKVARC_IL_EXCLUDE_IDS[]";
        hidden.value = id;
        li.appendChild(label);
        li.appendChild(btn);
        li.appendChild(hidden);
        list.appendChild(li);
    }

    function tryAdd() {
        var val = search.value.trim();
        if (!val) { return; }
        var match = null;
        var opts = options.querySelectorAll("option");
        for (var i = 0; i < opts.length; i++) {
            if (opts[i].value === val) { match = opts[i]; break; }
        }
        if (!match) {
            var m = val.match(/#(\\d+)\\)?\\s*$/) || val.match(/^(\\d+)$/);
            if (m) {
                for (var j = 0; j < opts.length; j++) {
                    if (opts[j].getAttribute("data-id") === m[1]) { match = opts[j]; break; }
                }
            }
        }
        if (match) {
            addRow(match.getAttribute("data-id"), match.value.replace(/\\s*\\(#\\d+\\)\\s*$/, ""));
            search.value = "";
            search.focus();
        }
    }

    addBtn.addEventListener("click", tryAdd);
    search.addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); tryAdd(); }
    });
})();</script>';
    }

    private function descriptionLinkExcludeChip(int $id, string $name): string
    {
        return '<li data-id="' . $id . '" style="display:flex;align-items:center;justify-content:space-between;'
            . 'padding:5px 10px;border:1px solid #ddd;border-top:none;background:#fff;">'
            . '<span>' . self::esc($name) . '</span>'
            . '<button type="button" class="btn btn-default btn-xs" onclick="this.parentNode.parentNode.removeChild(this.parentNode)" title="'
            . self::esc($this->l('Remove')) . '">&minus;</button>'
            . '<input type="hidden" name="AKVARC_IL_EXCLUDE_IDS[]" value="' . $id . '"></li>';
    }

    private function renderConfig(): string
    {
        $languages = Language::getLanguages(false);
        $catMax = (int) Configuration::get('AKVARC_CAT_MAX');
        $prodMax = (int) Configuration::get('AKVARC_PROD_MAX');
        $ilMax = (int) Configuration::get('AKVARC_IL_MAX');
        $ilMinLen = (int) Configuration::get('AKVARC_IL_MINLEN');

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

        $body = $row($this->l('Enable module'), $this->l('Master switch. Turning it off hides both link blocks.'), $sw('AKVARC_ENABLED', $this->l('Yes'), $this->l('No')))
            . '<hr><h4>' . self::esc($this->l('Category (shown below the product listing in the archive)')) . '</h4>'
            . $row($this->l('Number of links'), $this->l('Maximum total number of related categories (default 10).'),
                '<input type="number" min="1" max="50" class="form-control fixed-width-sm" name="AKVARC_CAT_MAX" value="' . $catMax . '">')
            . $row($this->l('Include parent category'), '', $sw('AKVARC_INC_PARENT', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Include sibling categories'), $this->l('Categories that share the same parent.'), $sw('AKVARC_INC_SIBLINGS', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Include child categories'), '', $sw('AKVARC_INC_CHILDREN', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Block title'), $this->l('Per language. Appears above the links.'), $langInputs('AKVARC_CAT_TITLE_', 'AKVARC_CAT_TITLE'))
            . '<hr><h4>' . self::esc($this->l('Product (at the bottom of the product page)')) . '</h4>'
            . $row($this->l('Number of categories'), $this->l('Maximum number of categories the product belongs to (default 5).'),
                '<input type="number" min="1" max="50" class="form-control fixed-width-sm" name="AKVARC_PROD_MAX" value="' . $prodMax . '">')
            . $row($this->l('Block title'), $this->l('Per language.'), $langInputs('AKVARC_PROD_TITLE_', 'AKVARC_PROD_TITLE'))
            . '<hr><h4>' . self::esc($this->l('Description internal links (SEO)')) . '</h4>'
            . $row($this->l('Enable inline linking'), $this->l('Automatically turns mentions of active category names inside category/product/CMS body text into internal links.'), $sw('AKVARC_IL_ENABLED', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Apply to category descriptions'), '', $sw('AKVARC_IL_CAT', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Apply to product descriptions'), '', $sw('AKVARC_IL_PROD', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Apply to CMS pages'), '', $sw('AKVARC_IL_CMS', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Max links per page'), $this->l('Upper bound on how many category mentions get turned into links in one description (default 3).'),
                '<input type="number" min="0" max="20" class="form-control fixed-width-sm" name="AKVARC_IL_MAX" value="' . $ilMax . '">')
            . $row($this->l('Choose randomly'), $this->l('When more mentions are found than the max, pick which ones become links at random instead of always the first ones.'), $sw('AKVARC_IL_RANDOM', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Minimum name length'), $this->l('Category names shorter than this (in characters) are never auto-linked, to avoid over-matching short or common words (default 3).'),
                '<input type="number" min="1" max="40" class="form-control fixed-width-sm" name="AKVARC_IL_MINLEN" value="' . $ilMinLen . '">')
            . $row($this->l('One link per category'), $this->l('Never link the same category more than once within a single description.'), $sw('AKVARC_IL_ONCE', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Allow self-link'), $this->l('On a category page, also allow linking mentions of that same category\'s own name.'), $sw('AKVARC_IL_SELF', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Include short description'), $this->l('Also apply inline linking to the product\'s short description (off by default -- links in a short teaser tend to look spammy).'), $sw('AKVARC_IL_DESC_SHORT', $this->l('Yes'), $this->l('No')))
            . $row($this->l('Never link to these categories'), $this->l('Categories excluded here are never used as auto-link targets (their own pages are unaffected). Use this for generic or spec-like category names (e.g. size/diameter categories) that would otherwise get linked from unrelated text. Type a name to search, then Add; click the minus to remove.'),
                $this->descriptionLinkExcludePicker());

        return '<form method="post" class="form-horizontal">'
            . '<div class="panel">'
            . '<div class="panel-heading"><i class="icon-link"></i> ' . self::esc($this->l('Related categories -- SEO internal links')) . '</div>'
            . '<div class="alert alert-info">' . self::esc($this->l('The blocks are discreet (small, grey, at the bottom) but fully indexable -- real links using category names as anchor text. They are intentionally NOT hidden: hiding internal links is cloaking and risks a penalty.')) . '</div>'
            . $body
            . '<div class="panel-footer"><button type="submit" name="submitAkvarc" class="btn btn-primary pull-right"><i class="process-icon-save"></i> ' . self::esc($this->l('Save')) . '</button></div>'
            . '</div></form>';
    }
}
