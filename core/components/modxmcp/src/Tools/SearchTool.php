<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Find text anywhere in the site's elements and content.
 *
 * Built directly on xPDO, like every read in this extra: the processor-first
 * rule is about writes. The Manager's own search screen is a different thing,
 * a search over titles gated on the 'search' permission. This one reads bodies,
 * so it is gated the way reading them one by one is gated (see call()).
 *
 * Elements and resources are searched together on purpose. "Who calls
 * [[$productCard]]?" is one question, and its answer is spread across template
 * bodies, other chunks, snippets that build markup, and resource content where
 * an editor pasted the tag by hand. Splitting it into two tools would mean a
 * caller that asks half the question and believes it has the answer.
 *
 * It exposes nothing a caller could not read one element or resource at a
 * time: each kind is gated on the permission its Get processor declares, and
 * each hit on the view policy its GetProcessor applies. Every query below is
 * code-owned with bound values, so nothing the caller sends reaches an
 * operator or a column name.
 */
final class SearchTool extends AbstractTool
{
    use ElementSupport;
    use ExcerptSupport;
    // tagPatterns(), shared with element_delete's reference check so the two
    // cannot drift into disagreeing about what calls an element.
    use ReferenceSupport;

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
                'context_key' => Schema::string('Restrict resources to one context key.'),
                'context' => Schema::string('Older name for context_key, still accepted.'),
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

        $searchResources = $where === 'both' || $where === 'resources';
        $types           = [];
        if ($where === 'both' || $where === 'elements') {
            $types = $this->arg($arguments, 'types');
            $types = is_array($types) && $types !== [] ? $types : $this->elementTypeKeys();
        }

        // Per kind, the permission MODX's own Get processor for it declares. A
        // kind the user may not read is skipped and reported, not refused, so
        // one missing view_plugin does not cost a caller the templates it may
        // read -- but if nothing asked for remains, the whole call is refused.
        // Deliberately not the Manager's 'search' permission: that gates its
        // title search screen, and Content Editor lacks it, while this reads
        // what the user could already open one at a time.
        $skipped  = [];
        $readable = [];
        foreach ($types as $typeKey) {
            $key        = strtolower(trim((string) $typeKey));
            $permission = $this->elementType($key)['permission'];
            if ($modx->hasPermission($permission)) {
                $readable[] = $typeKey;
            } else {
                $skipped[] = ['kind' => $this->elementNoun($key, true), 'permission' => $permission];
            }
        }
        if ($searchResources && !$modx->hasPermission('view')) {
            $searchResources = false;
            $skipped[]       = ['kind' => 'resources', 'permission' => 'view'];
        }
        if ($readable === [] && !$searchResources) {
            $this->requirePermission(
                $modx,
                'read the ' . $this->listing(array_column($skipped, 'kind')) . ' this search covers',
                array_column($skipped, 'permission')
            );
        }

        if ($readable !== []) {
            $elements = $this->searchElements($modx, $needles, $readable, $limit, $caseSensitive);
        }

        if ($searchResources) {
            $resources = $this->searchResources(
                $modx,
                $needles,
                $q,
                $limit,
                $caseSensitive,
                $this->renamedArg($arguments, 'context_key', 'context')
            );
        }

        $result['total_matches'] = array_sum(array_column($elements, 'match_count'))
            + array_sum(array_column($resources, 'match_count'));
        $result['elements']  = $elements;
        $result['resources'] = $resources;

        // Only when something was skipped, so a caller who may read everything
        // gets exactly the response it always did.
        if ($skipped !== []) {
            $result['skipped'] = $skipped;
            $result['note']    = sprintf(
                '%s %s not searched: the MODX user this token acts as lacks %s. An empty result '
                . 'here does not mean there is no match.',
                ucfirst($this->listing(array_column($skipped, 'kind'))),
                count($skipped) === 1 ? 'was' : 'were',
                $this->listing(array_column($skipped, 'permission'))
            );
        }

        return $result;
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
                // The object policy, before the body is even read. Not counted
                // anywhere: a count would tell the caller that something it may
                // not see matches its query.
                if (!$element->checkPolicy('view')) {
                    continue;
                }
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
            // As for elements: an excerpt is a view, and a refused hit is not
            // counted.
            if (!$resource->checkPolicy('view')) {
                continue;
            }
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
