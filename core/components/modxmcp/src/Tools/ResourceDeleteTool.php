<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Delete a resource through the MODX Resource/Delete processor.
 *
 * Deletion in MODX is a soft delete: the resource moves to the recycle bin and
 * can be restored until the bin is emptied. That is the only behaviour offered
 * here. Permanent removal is a separate, irreversible operation and does not
 * belong behind the same tool name as a reversible one.
 */
final class ResourceDeleteTool extends AbstractTool
{
    use ResourceSupport;

    public function requiredScope(): string
    {
        return 'write:content';
    }

    public function name(): string
    {
        return 'modxmcp_resource_delete';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Delete a resource',
            'description' => 'Move a resource to the recycle bin. This is a soft delete and can '
                . 'be undone from the Manager until the bin is emptied. Children are affected '
                . 'too, so check for them first with modxmcp_resource_list.',
            'inputSchema' => Schema::object([
                'id' => Schema::integer('Resource id. Required.'),
            ], ['id']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $id = (int) $this->requireArg($arguments, 'id');

        /** @var modResource|null $resource */
        $resource = $modx->getObject(modResource::class, $id);
        if (!$resource) {
            throw McpException::invalidParams("No resource with id {$id}");
        }

        // Reported back so the caller can see what it actually removed, which
        // matters when the id came from a search rather than from a human.
        $summary  = $this->pick($resource->toArray(), ['id', 'pagetitle', 'alias', 'uri', 'parent']);
        $children = $modx->getCount(modResource::class, ['parent' => $id, 'deleted' => 0]);

        $this->runProcessor($modx, 'Resource/Delete', ['id' => $id]);

        $summary['deleted']       = true;
        $summary['recoverable']   = true;
        $summary['child_count']   = $children;
        if ($children > 0) {
            $summary['warnings'] = [
                "This resource had {$children} child resource(s), which are removed with it.",
            ];
        }

        return $summary;
    }
}
