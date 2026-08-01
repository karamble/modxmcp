<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Find text anywhere in the site's elements and content.
 *
 * MODX has no processor for this because the Manager has no screen for it, so
 * this is one of the few reads built directly on xPDO. That is not a departure
 * from the processor-first rule: that rule is about writes, and every read in
 * this extra already goes straight to xPDO.
 *
 * Elements and resources are searched together on purpose. "Who calls
 * [[$productCard]]?" is one question, and its answer is spread across template
 * bodies, other chunks, snippets that build markup, and resource content where
 * an editor pasted the tag by hand. Splitting it into two tools would mean a
 * caller that asks half the question and believes it has the answer.
 *
 * This exposes nothing new: modxmcp_element_get already returns snippet and
 * plugin PHP under the read scope, and modxmcp_resource_get already returns
 * content. Every query below is code-owned with bound values, so nothing the
 * caller sends reaches an operator or a column name.
 */
final class SearchTool extends AbstractTool
{
    use ElementSupport;
    use ExcerptSupport;

    /**
     * Resource columns worth searching, and safe to search.
     *
     * Fixed rather than caller-supplied: a column name cannot be bound as a
     * parameter, so accepting one would put caller input into the query
     * structure. These are the fields that hold prose.
     */
    private const RESOURCE_FIELDS = [
        'pagetitle', 'longtitle', 'alias', 'description', 'introtext', 'content',
    ];

    public function name(): string
    {
        return 'modxmcp_search';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Search elements and content',
            'description' => 'Find literal text across element bodies (chunks, snippets, '
                . 'templates, template variables, plugins) and resource content, with the '
                . 'surrounding line quoted back. Use mode=tag_reference to find every place a '
                . 'named element is CALLED, which is what you want before renaming or deleting '
                . 'one: it expands the name to the MODX tag forms ([[$name]], [[name]], '
                . '[[!name]]) so you do not have to guess which is in use.',
            'inputSchema' => Schema::object([
                'q'    => Schema::string('Text to find. Matched literally, not as a regular '
                    . 'expression or a wildcard pattern.'),
                'in'   => Schema::enum('Where to search.', ['both', 'elements', 'resources'], 'both'),
                'mode' => Schema::enum(
                    'literal searches for the text as given. tag_reference treats q as an '
                    . 'element name and searches for the MODX tag forms that would call it.',
                    ['literal', 'tag_reference'],
                    'literal'),
                'types' => Schema::arrayOf(
                    'Element types to search. Omit for all of them.',
                    Schema::enum('Element type', $this->elementTypeKeys())),
                'case_sensitive' => Schema::boolean('Match case exactly.', false),
                'context' => Schema::string('Restrict resources to one context key.'),
                'limit'   => Schema::integer('Maximum matching items per kind.', 25),
            ], ['q']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $q = trim((string) $this->requireArg($arguments, 'q'));
        if ($q === '') {
            throw McpException::invalidParams('q cannot be empty.');
        }

        $mode          = (string) $this->arg($arguments, 'mode', 'literal');
        $where         = (string) $this->arg($arguments, 'in', 'both');
        $caseSensitive = !empty($arguments['case_sensitive']);
        $limit         = max(1, min(100, (int) $this->arg($arguments, 'limit', 25)));

        $needles = $mode === 'tag_reference' ? $this->tagPatterns($q) : [$q];

        $result = [
            'query'    => $q,
            'mode'     => $mode,
            'patterns' => $needles,
        ];

        $elements  = [];
        $resources = [];

        if ($where === 'both' || $where === 'elements') {
            $types    = $this->arg($arguments, 'types');
            $types    = is_array($types) && $types !== [] ? $types : $this->elementTypeKeys();
            $elements = $this->searchElements($modx, $needles, $types, $limit, $caseSensitive);
        }

        if ($where === 'both' || $where === 'resources') {
            $resources = $this->searchResources(
                $modx,
                $needles,
                $q,
                $limit,
                $caseSensitive,
                $this->arg($arguments, 'context')
            );
        }

        $result['total_matches'] = array_sum(array_column($elements, 'match_count'))
            + array_sum(array_column($resources, 'match_count'));
        $result['elements']  = $elements;
        $result['resources'] = $resources;

        return $result;
    }

    /**
     * The MODX tag forms that would call an element of this name.
     *
     * Which sigil an element uses depends on its type, and a caller asking "who
     * calls productCard" rarely knows or cares. Searching all of them costs one
     * OR per form and removes the guesswork.
     *
     * @return string[]
     */
    private function tagPatterns(string $name): array
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
     * @param string[] $needles
     * @param string[] $types
     * @return array<int,array<string,mixed>>
     */
    private function searchElements(
        modX $modx,
        array $needles,
        array $types,
        int $limit,
        bool $caseSensitive
    ): array {
        $hits = [];

        foreach ($types as $typeKey) {
            $type = $this->elementType((string) $typeKey);

            foreach ($modx->getIterator($type['class']) as $element) {
                $name = (string) $element->get($type['name']);
                $body = (string) $element->get($type['content']);

                $inName = $this->excerpts($name, $needles, $caseSensitive);
                $inBody = $this->excerpts($body, $needles, $caseSensitive);

                if ($inName['count'] === 0 && $inBody['count'] === 0) {
                    continue;
                }

                $hits[] = [
                    'type'        => $typeKey,
                    'id'          => (int) $element->get('id'),
                    'name'        => $name,
                    'matched_in'  => $inBody['count'] > 0
                        ? ($inName['count'] > 0 ? 'name and content' : 'content')
                        : 'name',
                    'match_count' => $inName['count'] + $inBody['count'],
                    'excerpts'    => $inBody['excerpts'],
                    'truncated'   => $inBody['truncated'],
                ];

                if (count($hits) >= $limit) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /**
     * @param string[] $needles expanded forms to locate exactly
     * @param string    $term    bare term, used to narrow in SQL
     * @return array<int,array<string,mixed>>
     */
    private function searchResources(
        modX $modx,
        array $needles,
        string $term,
        int $limit,
        bool $caseSensitive,
        ?string $context
    ): array {
        // Narrow in SQL first so the whole content table does not come back,
        // then find exact offsets in PHP. LIKE cannot give a position and is
        // case-insensitive under the usual collations, so it filters rather
        // than decides.
        $query = $modx->newQuery(modResource::class);
        $query->where(['deleted' => 0]);
        if ($context !== null) {
            $query->where(['context_key' => (string) $context]);
        }

        // Narrow on the bare term, not on each expanded pattern. Every
        // tag_reference form contains the element name as a substring, so one
        // condition per field covers all of them, and xPDO's criteria are an
        // array keyed by "OR:field:op" which cannot express the same key twice
        // anyway. The exact pattern matching happens in PHP below.
        $like  = '%' . $this->escapeLike($term) . '%';
        $any   = [];
        $first = true;
        foreach (self::RESOURCE_FIELDS as $field) {
            $any[($first ? '' : 'OR:') . $field . ':LIKE'] = $like;
            $first = false;
        }
        $query->where([$any]);

        // Over-fetch, because LIKE filters rather than decides: it is
        // case-insensitive under the usual collations, so a case-sensitive
        // search discards some of what comes back.
        $query->limit($limit * 4);

        $hits = [];
        foreach ($modx->getIterator(modResource::class, $query) as $resource) {
            $matches = 0;
            $best    = ['excerpts' => [], 'truncated' => false];
            $matchedIn = [];

            foreach (self::RESOURCE_FIELDS as $field) {
                $found = $this->excerpts((string) $resource->get($field), $needles, $caseSensitive);
                if ($found['count'] === 0) {
                    continue;
                }
                $matches += $found['count'];
                $matchedIn[] = $field;
                if ($best['excerpts'] === []) {
                    $best = $found;
                }
            }

            if ($matches === 0) {
                continue;
            }

            $hits[] = [
                'id'          => (int) $resource->get('id'),
                'pagetitle'   => (string) $resource->get('pagetitle'),
                'uri'         => (string) $resource->get('uri'),
                'published'   => (bool) $resource->get('published'),
                'matched_in'  => implode(', ', $matchedIn),
                'match_count' => $matches,
                'excerpts'    => $best['excerpts'],
                'truncated'   => $best['truncated'],
            ];

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }
}
