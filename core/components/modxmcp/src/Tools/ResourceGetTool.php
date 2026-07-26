<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Full detail for one resource, including its template variables.
 */
final class ResourceGetTool extends AbstractTool
{
    use ResourceSupport;

    public function name(): string
    {
        return 'modxmcp_resource_get';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Get a resource',
            'description' => 'Fetch one resource by id or by alias, including its content and '
                . 'template variable values. Read this before updating a resource: '
                . 'modxmcp_resource_update only changes the fields you pass, so you need to '
                . 'know the current state to avoid surprises.',
            'inputSchema' => Schema::object([
                'id'          => Schema::integer('Resource id. Either this or alias is required.'),
                'alias'       => Schema::string('Resource alias, if you do not have the id.'),
                'context'     => Schema::string('Context to look in when using alias.', 'web'),
                'include_tvs' => Schema::boolean('Include template variable values.', true),
                'include_content' => Schema::boolean('Include the content field, which can be large.', true),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $resource = $this->locate($modx, $arguments);

        $row = $this->pick($resource->toArray(), array_merge($this->resourceFields(), [
            'introtext', 'menutitle', 'searchable', 'cacheable', 'richtext', 'template',
        ]));

        if ($this->arg($arguments, 'include_content', true)) {
            $row['content'] = $resource->get('content');
        }

        if ($this->arg($arguments, 'include_tvs', true)) {
            $row['tvs'] = $this->readTvs($modx, $resource);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $arguments
     * @throws McpException
     */
    private function locate(modX $modx, array $arguments): modResource
    {
        $id = $this->arg($arguments, 'id');
        if ($id !== null) {
            /** @var modResource|null $resource */
            $resource = $modx->getObject(modResource::class, (int) $id);
            if (!$resource) {
                throw McpException::invalidParams("No resource with id {$id}");
            }
            return $resource;
        }

        $alias = $this->arg($arguments, 'alias');
        if ($alias === null) {
            throw McpException::invalidParams('Provide either id or alias');
        }

        /** @var modResource|null $resource */
        $resource = $modx->getObject(modResource::class, [
            'alias'       => (string) $alias,
            'context_key' => (string) $this->arg($arguments, 'context', 'web'),
        ]);
        if (!$resource) {
            throw McpException::invalidParams("No resource with alias '{$alias}'");
        }

        return $resource;
    }
}
