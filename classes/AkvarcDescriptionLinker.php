<?php
/**
 * akvarelatedcategories -- v2 description inner-linking engine.
 *
 * Turns mentions of active category names inside a block of body HTML (category/product
 * description, CMS content) into real internal <a href> links, DOM-safely (never inside an
 * existing <a>, <script>, <style>, <code>, <pre> or <button>). Pure PHP, no PrestaShop
 * dependencies -- unit-testable standalone.
 *
 * UTF-8 handling follows the precedent in
 * advancedpricemanager/src/Service/B2b/HtmlSanitizer.php: wrap the fragment in an
 * "<?xml encoding=\"UTF-8\"?>"-prefixed root <div>, load with libxml error suppression, extract
 * by id, rebuild with saveHTML() per child (never saveHTML() on the whole document, which would
 * re-add <html><body>).
 *
 * Unicode word-boundary matching: PCRE's \b is ASCII-only, so Slovenian/Croatian diacritics
 * (c/s/z with caron) need lookaround boundaries against \p{L}\p{N}\p{M} instead. PREG_OFFSET_CAPTURE
 * always returns BYTE offsets even under the /u modifier -- this class works in bytes throughout
 * (substr/strlen, never mb_substr/mb_strlen) so those offsets stay valid against DOMText::$data.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AkvarcDescriptionLinker
{
    /**
     * @param array<int,array{id:int,name:string,rewrite:string}> $glossary candidate link targets
     * @param array{max:int,random:bool,minLen:int,once:bool,entitySeed:string,variant?:string,linkBuilder:callable} $opts
     */
    public static function process(string $html, array $glossary, array $opts): string
    {
        $html = trim($html);
        if ($html === '' || $glossary === [] || !class_exists('DOMDocument')) {
            return $html;
        }

        $max = max(0, (int) ($opts['max'] ?? 0));
        if ($max === 0) {
            return $html;
        }

        $terms = self::buildTermMap($glossary, max(1, (int) ($opts['minLen'] ?? 1)));
        if ($terms === []) {
            return $html;
        }

        $pattern = self::buildPattern(array_keys($terms));

        // Cheap exit: skip the DOM parse entirely when nothing in the raw string can match.
        if (@preg_match($pattern, $html) !== 1) {
            return $html;
        }

        $parsed = self::parse($html);
        if ($parsed === null) {
            return $html;
        }
        [$dom, $root] = $parsed;

        $candidates = self::collectCandidates($root, $pattern, $terms);
        if ($candidates === []) {
            return $html;
        }

        $selected = self::selectCandidates(
            $candidates,
            $max,
            (bool) ($opts['once'] ?? true),
            (bool) ($opts['random'] ?? true),
            (string) ($opts['entitySeed'] ?? '') . ':' . strlen($html)
        );
        if ($selected === []) {
            return $html;
        }

        $linkBuilder = is_callable($opts['linkBuilder'] ?? null) ? $opts['linkBuilder'] : null;

        return self::applySelection($dom, $root, $selected, $linkBuilder, (string) ($opts['variant'] ?? ''));
    }

    /**
     * @param array<int,array{id:int,name:string,rewrite:string}> $glossary
     * @return array<string,array{id:int,name:string,rewrite:string}> lowercase name => category
     */
    private static function buildTermMap(array $glossary, int $minLen): array
    {
        $terms = [];
        foreach ($glossary as $cat) {
            $name = trim((string) ($cat['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') < $minLen) {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($terms[$key])) {
                continue; // duplicate category name -- first one in the glossary wins
            }
            $terms[$key] = [
                'id' => (int) ($cat['id'] ?? 0),
                'name' => $name,
                'rewrite' => (string) ($cat['rewrite'] ?? ''),
            ];
        }

        return $terms;
    }

    /**
     * Longest-name-first alternation so PCRE's leftmost-first matching prefers a longer phrase
     * over a shorter one that happens to be its substring (e.g. "Mountain bike helmet" over "bike").
     *
     * @param list<string> $lowerKeys
     */
    private static function buildPattern(array $lowerKeys): string
    {
        usort($lowerKeys, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        $escaped = array_map(static function (string $t): string {
            return preg_quote($t, '/');
        }, $lowerKeys);

        return '/(?<![\p{L}\p{N}\p{M}])(?:' . implode('|', $escaped) . ')(?![\p{L}\p{N}\p{M}])/iu';
    }

    /**
     * @return array{0:\DOMDocument,1:\DOMElement}|null
     */
    private static function parse(string $html): ?array
    {
        $prevErr = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="akvarc-desc-root">' . $html . '</div>';
        $ok = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);
        if (!$ok) {
            return null;
        }

        $root = $dom->getElementById('akvarc-desc-root');
        if (!$root instanceof \DOMElement) {
            return null;
        }

        return [$dom, $root];
    }

    /**
     * @param array<string,array{id:int,name:string,rewrite:string}> $terms
     * @return list<array{node:\DOMText,start:int,len:int,text:string,cat:array{id:int,name:string,rewrite:string}}>
     */
    private static function collectCandidates(\DOMElement $root, string $pattern, array $terms): array
    {
        $xpath = new \DOMXPath($root->ownerDocument);
        $textNodes = $xpath->query(
            './/text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)'
            . ' and not(ancestor::code) and not(ancestor::pre) and not(ancestor::button)]',
            $root
        );
        if ($textNodes === false || $textNodes->length === 0) {
            return [];
        }

        $candidates = [];
        foreach (iterator_to_array($textNodes) as $node) {
            /** @var \DOMText $node */
            $data = $node->data;
            if ($data === '' || trim($data) === '') {
                continue;
            }
            $matched = @preg_match_all($pattern, $data, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            if ($matched === false || $matched === 0) {
                continue;
            }
            foreach ($m as $one) {
                $text = (string) $one[0][0];
                $key = mb_strtolower($text, 'UTF-8');
                if (!isset($terms[$key])) {
                    continue;
                }
                $candidates[] = [
                    'node' => $node,
                    'start' => (int) $one[0][1],
                    'len' => strlen($text),
                    'text' => $text,
                    'cat' => $terms[$key],
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param list<array{node:\DOMText,start:int,len:int,text:string,cat:array{id:int,name:string,rewrite:string}}> $candidates
     * @return list<array{node:\DOMText,start:int,len:int,text:string,cat:array{id:int,name:string,rewrite:string}}>
     */
    private static function selectCandidates(array $candidates, int $max, bool $once, bool $random, string $seedSource): array
    {
        if ($random) {
            mt_srand(crc32($seedSource));
            shuffle($candidates);
            mt_srand(); // reseed from entropy so this doesn't bias any unrelated randomness later in the request
        }

        $selected = [];
        $usedCategoryIds = [];
        foreach ($candidates as $c) {
            if (count($selected) >= $max) {
                break;
            }
            $catId = $c['cat']['id'];
            if ($once && isset($usedCategoryIds[$catId])) {
                continue;
            }
            $selected[] = $c;
            $usedCategoryIds[$catId] = true;
        }

        return $selected;
    }

    /**
     * @param list<array{node:\DOMText,start:int,len:int,text:string,cat:array{id:int,name:string,rewrite:string}}> $selected
     * @param string $variant page type the anchors render on ('category'|'product'|'cms'), for click tracking
     */
    private static function applySelection(\DOMDocument $dom, \DOMElement $root, array $selected, ?callable $linkBuilder, string $variant = ''): string
    {
        $byNode = [];
        foreach ($selected as $c) {
            $byNode[spl_object_id($c['node'])][] = $c;
        }

        foreach ($byNode as $items) {
            usort($items, static function (array $a, array $b): int {
                return $a['start'] <=> $b['start'];
            });

            /** @var \DOMText $node */
            $node = $items[0]['node'];
            $parent = $node->parentNode;
            if ($parent === null) {
                continue;
            }
            $data = $node->data;

            $cursor = 0;
            $fragments = [];
            foreach ($items as $it) {
                if ($it['start'] < $cursor) {
                    continue; // defensive: overlapping matches can't occur from one alternation pass, but never regress the cursor
                }
                $before = substr($data, $cursor, $it['start'] - $cursor);
                if ($before !== '') {
                    $fragments[] = $dom->createTextNode($before);
                }

                $href = $linkBuilder !== null ? (string) $linkBuilder($it['cat']) : '';
                if ($href === '') {
                    $fragments[] = $dom->createTextNode($it['text']);
                } else {
                    $a = $dom->createElement('a');
                    $a->setAttribute('href', $href);
                    $a->setAttribute('class', 'akvarc-inlink');
                    // Stable click-tracking hooks (v1.2.0) read by views/js/front.js.
                    $a->setAttribute('data-akvarc-category-id', (string) (int) $it['cat']['id']);
                    $a->setAttribute('data-akvarc-source', 'description_link');
                    if ($variant !== '') {
                        $a->setAttribute('data-akvarc-variant', $variant);
                    }
                    $a->appendChild($dom->createTextNode($it['text']));
                    $fragments[] = $a;
                }
                $cursor = $it['start'] + $it['len'];
            }
            $tail = substr($data, $cursor);
            if ($tail !== '') {
                $fragments[] = $dom->createTextNode($tail);
            }

            foreach ($fragments as $fragment) {
                $parent->insertBefore($fragment, $node);
            }
            $parent->removeChild($node);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= (string) $dom->saveHTML($child);
        }

        return trim($out);
    }
}
