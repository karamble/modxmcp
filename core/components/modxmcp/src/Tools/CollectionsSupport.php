<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modResource;
use MODX\Revolution\modX;

/**
 * One definition of "is this a Collections container".
 *
 * The same predicate had grown in four places, in three different shapes: as a
 * stripos() on a parent's class_key in ResourceSupport::parentWarnings(), as a
 * class_key:LIKE fragment in CollectionsContainersTool, as a stripos() on the
 * resource's own class_key in ResourceClassSupport, and as prose keyed on
 * namespace presence in SiteInfoTool. parentWarnings() also carried its own
 * inline copy of the namespace probe.
 *
 * Consolidating them needs all three shapes, because the callers genuinely ask
 * different questions: one asks about a parent, one about the resource itself,
 * and one needs a query fragment rather than a PHP test.
 *
 * Collections advertises a container through its class_key and keeps everything
 * else about it in its own tables, which is why matching the name is the only
 * available test.
 */
trait CollectionsSupport
{
    /** The class_key marker Collections uses for its container types. */
    private static string $collectionsMarker = 'Collection';

    protected function collectionsInstalled(modX $modx): bool
    {
        return (bool) $modx->getObject(modNamespace::class, ['name' => 'collections']);
    }

    /** Does this class_key belong to a Collections container type? */
    protected function isCollectionsClassKey(?string $classKey): bool
    {
        return $classKey !== null
            && $classKey !== ''
            && stripos($classKey, self::$collectionsMarker) !== false;
    }

    protected function isCollectionsContainer(modX $modx, int $resourceId): bool
    {
        if ($resourceId <= 0) {
            return false;
        }

        /** @var modResource|null $resource */
        $resource = $modx->getObject(modResource::class, $resourceId);

        return $resource !== null
            && $this->isCollectionsClassKey((string) $resource->get('class_key'));
    }

    /** The query fragment form, for callers listing containers. */
    protected function collectionsCriteria(): array
    {
        return ['class_key:LIKE' => '%' . self::$collectionsMarker . '%'];
    }

    /**
     * Containers on this site, with how many children each holds.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function collectionsContainers(modX $modx, ?string $context = null): array
    {
        $query = $modx->newQuery(modResource::class);
        $query->where(array_merge(['deleted' => 0], $this->collectionsCriteria()));
        if ($context !== null && $context !== '') {
            $query->where(['context_key' => $context]);
        }
        $query->sortby('id', 'ASC');

        $containers = [];
        foreach ($modx->getIterator(modResource::class, $query) as $resource) {
            $id = (int) $resource->get('id');
            $containers[] = [
                'id'          => $id,
                'pagetitle'   => (string) $resource->get('pagetitle'),
                'uri'         => (string) $resource->get('uri'),
                'class_key'   => (string) $resource->get('class_key'),
                'context_key' => (string) $resource->get('context_key'),
                'child_count' => (int) $modx->getCount(modResource::class, [
                    'parent'  => $id,
                    'deleted' => 0,
                ]),
            ];
        }

        return $containers;
    }

    /**
     * The canonical statement of the rule, so it is worded once.
     */
    protected function collectionsChildRule(): string
    {
        return 'Resources created under a Collections container need show_in_tree=0 and a real '
            . 'menuindex. Without them the resource exists, is published and is reachable by '
            . 'URL, but never appears in the Collections grid, and nothing about the symptom '
            . 'points at the cause.';
    }
}
