<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * Shared resource handling.
 *
 * Template variables are the subtle part, and they have their own trait:
 * TemplateVarSupport, composed in below. The Manager submits them as tv{id}
 * form properties, and the Resource processors expect exactly that, so a caller
 * writing directly to the value table would bypass every input transformation
 * and every extra hooked into the save. Callers here name TVs, and that trait
 * maps names to the properties the processor wants, against the template that
 * will actually render them.
 */
trait ResourceSupport
{
    use ResourceClassSupport;
    use TemplateVarSupport;

    /**
     * Fields returned for a resource. The full row is around forty columns,
     * most of them irrelevant to a caller and expensive to repeat in a list.
     *
     * @var string[]
     */
    private static array $resourceFields = [
        'id', 'pagetitle', 'longtitle', 'description', 'alias', 'uri', 'parent',
        'template', 'published', 'deleted', 'hidemenu', 'isfolder', 'menuindex',
        'context_key', 'class_key', 'content_type', 'publishedon', 'createdon', 'editedon',
    ];

    /**
     * Read the TVs attached to a resource's template, keyed by name.
     *
     * Resolution lives in TemplateVarSupport now. tvProperties() used to sit
     * here and mapped names to tv{id} without checking template attachment,
     * which is what let unattached TVs be accepted and then silently discarded
     * by the processor. It was removed rather than deprecated: a wrapper that
     * skipped the check would keep that path alive.
     *
     * @return array<string,mixed>
     */
    protected function readTvs(modX $modx, modResource $resource): array
    {
        return $this->readTvsForTemplate(
            $modx,
            (int) $resource->get('id'),
            (int) $resource->get('template')
        );
    }

    /**
     * Warnings a caller cannot derive from any schema.
     *
     * Collections stores its rule in a plugin, not in the resource table: a
     * child of a Collections container that keeps show_in_tree=1 disappears
     * from listings while remaining published and reachable. Nothing about the
     * failure points at the cause, so it is worth saying out loud on every
     * write that could trip it.
     *
     * @return string[]
     */
    protected function parentWarnings(modX $modx, int $parentId, ?int $showInTree): array
    {
        if ($parentId <= 0) {
            return [];
        }
        if (!$modx->getObject(\MODX\Revolution\modNamespace::class, ['name' => 'collections'])) {
            return [];
        }

        /** @var modResource|null $parent */
        $parent = $modx->getObject(modResource::class, $parentId);
        if (!$parent) {
            return [];
        }

        // A Collections container advertises itself through its class_key.
        if (stripos((string) $parent->get('class_key'), 'Collection') === false) {
            return [];
        }

        if ($showInTree === 0) {
            return [];
        }

        return [
            "Parent {$parentId} is a Collections container. Children normally need "
            . 'show_in_tree=0 and an explicit menuindex, or they will not appear in the '
            . 'Collections grid. Pass show_in_tree=0 unless you specifically want this '
            . 'resource in the tree.',
        ];
    }

    /** @return string[] */
    protected function resourceFields(): array
    {
        return self::$resourceFields;
    }

    /**
     * Describe a resource by re-reading it after a write.
     *
     * The processors cannot be trusted to echo what they saved.
     * Resource/Create::cleanup() returns nothing but the id, so a caller could
     * not see the alias MODX derived, the resulting URI, or whether the
     * resource actually published. Resource/Update returns more but explicitly
     * strips pagetitle, longtitle, content, introtext, description and
     * menutitle, so "what changed" is exactly what it omits.
     *
     * Reading the row back is authoritative and gives both tools the same
     * shape, which matters more than saving a query.
     *
     * @return array<string,mixed>
     * @throws McpException
     */
    protected function summarise(modX $modx, int $id): array
    {
        /** @var modResource|null $resource */
        $resource = $modx->getObject(modResource::class, $id);
        if (!$resource) {
            throw McpException::internal("Resource {$id} could not be read back after saving");
        }

        return $this->pick($resource->toArray(), $this->resourceFields());
    }
}
