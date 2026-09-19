<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;

/**
 * Who calls this element.
 *
 * modxmcp_search has answered this since it shipped, in tag_reference mode, and
 * its own description calls it "what you want before renaming or deleting one".
 * The capability existed and the delete path did not use it, so a chunk called
 * by two published pages was removed without a word and those pages rendered
 * without it, the tag vanishing with no trace in the HTML.
 *
 * Extracted here rather than left in SearchTool because that class is final and
 * kept these private, and because the two callers want different answers from
 * the same knowledge: search wants excerpts and offsets, a delete wants a list
 * of names it can put in a warning.
 */
trait ReferenceSupport
{
    // escapeLike(). ExcerptSupport is also used directly by SearchTool; PHP
    // flattens the same trait reached by two paths without complaint.
    use ExcerptSupport;

    /** Resource columns a tag can realistically be pasted into. */
    private const REFERENCE_FIELDS = ['content', 'introtext', 'description'];

    /** How many callers of each kind to name before summarising the rest. */
    private const REFERENCE_LIMIT = 20;

    /**
     * Every MODX tag form that might call an element of this name.
     *
     * Which sigil an element uses depends on its type, and a caller asking "who
     * calls productCard" rarely knows or cares. Searching all of them costs one
     * OR per form and removes the guesswork.
     *
     * @return string[]
     */
    protected function tagPatterns(string $name): array
    {
        return [
            '[[$' . $name,   // chunk
            '[[' . $name,    // snippet, cached
            '[[!' . $name,   // snippet, uncached
            '[[*' . $name,   // resource field or TV
            '[[+' . $name,   // placeholder
        ];
    }

    /**
     * The forms that can call an element of this specific type.
     *
     * Narrower than tagPatterns() on purpose, because a delete warning has to
     * be right to be worth printing. A chunk named "content" checked against
     * every form matches [[*content]] in any template on the site — that is the
     * resource content field and has nothing to do with the chunk. Crying wolf
     * on a destructive operation teaches the caller to ignore the warning.
     *
     * Two types deliberately return nothing:
     *
     *   plugin    is never called by a tag. It runs on system events, and those
     *             are already echoed by the delete itself.
     *   template  is protected by MODX, which refuses to remove one any
     *             resource still uses.
     *
     * @return string[]
     */
    protected function tagPatternsFor(string $typeKey, string $name): array
    {
        switch ($typeKey) {
            case 'chunk':
                return ['[[$' . $name];
            case 'snippet':
                return ['[[' . $name, '[[!' . $name];
            case 'tv':
                return ['[[*' . $name];
            default:
                return [];
        }
    }

    /**
     * Elements and resources whose body contains one of these tag forms.
     *
     * Two stages, the same way searchResources() does it: narrow in SQL on the
     * bare name, then confirm the exact form in PHP. LIKE cannot be trusted to
     * decide — it is case-insensitive under the usual collations and cannot
     * match a literal bracket reliably — so it filters and PHP rules.
     *
     * Matching is case-insensitive, which is not sloppiness: MODX resolves a
     * tag by selecting the element by name, and under MySQL's default collation
     * [[$Footer]] finds a chunk called "footer". A case-sensitive check here
     * would miss callers that really do break.
     *
     * @param string[] $needles from tagPatternsFor()
     * @return array{elements:array<int,array<string,mixed>>,resources:array<int,array<string,mixed>>,total:int,truncated:bool}
     */
    protected function referencesTo(
        modX $modx,
        string $bareName,
        array $needles,
        string $excludeClass = '',
        int $excludeId = 0
    ): array {
        $found = ['elements' => [], 'resources' => [], 'total' => 0, 'truncated' => false];
        if ($needles === [] || trim($bareName) === '') {
            return $found;
        }

        $like    = '%' . $this->escapeLike($bareName) . '%';
        $overrun = false;

        foreach ($this->elementTypes() as $typeKey => $type) {
            $query = $modx->newQuery($type['class']);
            $query->where([$type['content'] . ':LIKE' => $like]);
            $query->limit(self::REFERENCE_LIMIT * 4);

            foreach ($modx->getIterator($type['class'], $query) as $element) {
                $id = (int) $element->get('id');

                // The element being deleted is not a caller of itself worth
                // reporting: the interesting question is what else breaks.
                if ($type['class'] === $excludeClass && $id === $excludeId) {
                    continue;
                }
                if (!$this->containsAnyTag((string) $element->get($type['content']), $needles)) {
                    continue;
                }
                if (count($found['elements']) >= self::REFERENCE_LIMIT) {
                    $overrun = true;
                    break;
                }

                $found['elements'][] = [
                    'type' => $typeKey,
                    'id'   => $id,
                    'name' => (string) $element->get($type['name']),
                ];
            }
        }

        $query = $modx->newQuery(modResource::class);
        $query->where(['deleted' => 0]);

        // One condition per field, OR'd. xPDO keys criteria by "OR:field:op",
        // which cannot express the same key twice, so the fields must differ.
        $any   = [];
        $first = true;
        foreach (self::REFERENCE_FIELDS as $field) {
            $any[($first ? '' : 'OR:') . $field . ':LIKE'] = $like;
            $first = false;
        }
        $query->where([$any]);
        $query->limit(self::REFERENCE_LIMIT * 4);

        foreach ($modx->getIterator(modResource::class, $query) as $resource) {
            foreach (self::REFERENCE_FIELDS as $field) {
                if (!$this->containsAnyTag((string) $resource->get($field), $needles)) {
                    continue;
                }
                if (count($found['resources']) >= self::REFERENCE_LIMIT) {
                    $overrun = true;
                    break 2;
                }

                $found['resources'][] = [
                    'id'        => (int) $resource->get('id'),
                    'pagetitle' => (string) $resource->get('pagetitle'),
                    'uri'       => (string) $resource->get('uri'),
                    'published' => (bool) $resource->get('published'),
                ];
                break;
            }
        }

        $found['total']     = count($found['elements']) + count($found['resources']);
        $found['truncated'] = $overrun;

        return $found;
    }

    /** @param string[] $needles */
    private function containsAnyTag(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * One sentence naming the callers, for a warning.
     *
     * Named rather than counted, because "3 resources still call this" leaves
     * the caller no better off than silence did: it cannot act without knowing
     * which. Published state is included for resources, since a caller broken
     * on a live page matters more than one on a draft.
     *
     * @param array{elements:array<int,array<string,mixed>>,resources:array<int,array<string,mixed>>,total:int,truncated:bool} $found
     */
    protected function describeReferences(array $found, string $typeKey, string $name): string
    {
        $parts = [];

        if ($found['resources'] !== []) {
            $listed = array_map(
                fn(array $r) => sprintf(
                    '%d (%s%s)',
                    $r['id'],
                    $r['uri'] !== '' ? '/' . $r['uri'] : $r['pagetitle'],
                    $r['published'] ? '' : ', unpublished'
                ),
                $found['resources']
            );
            $parts[] = sprintf(
                '%d resource(s): %s',
                count($found['resources']),
                implode(', ', $listed)
            );
        }

        if ($found['elements'] !== []) {
            $listed = array_map(
                fn(array $e) => sprintf('%s "%s" (%d)', $e['type'], $e['name'], $e['id']),
                $found['elements']
            );
            $parts[] = sprintf(
                '%d element(s): %s',
                count($found['elements']),
                implode(', ', $listed)
            );
        }

        return sprintf(
            'Still referenced after removal by %s.%s The tag will now render as nothing, leaving '
            . 'no trace in the output. The body is in removed.content above, so recreating this '
            . '%s with modxmcp_element_save restores them; otherwise edit the callers. '
            . 'modxmcp_search with mode=tag_reference and q="%s" shows the exact lines.',
            implode('; ', $parts),
            $found['truncated'] ? ' There are more than these; the list is capped.' : '',
            $typeKey,
            $name
        );
    }
}
