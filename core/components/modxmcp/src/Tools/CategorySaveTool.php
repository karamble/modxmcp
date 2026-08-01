<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modCategory;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Create or rename an element category.
 *
 * Processor-backed like everything else that writes, so the save_category
 * permission is enforced and the Manager's own lifecycle applies. Categories are
 * element organisation rather than content, which is why this sits under
 * write:elements and needs no new scope.
 */
final class CategorySaveTool extends AbstractTool
{
    public function requiredScope(): string
    {
        return 'write:elements';
    }

    public function name(): string
    {
        return 'modxmcp_category_save';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Create or rename a category',
            'description' => 'Create an element category, or rename an existing one by id. '
                . 'Categories group chunks, snippets, templates, template variables and plugins '
                . 'in the Manager tree. Use modxmcp_category_list first to check whether the '
                . 'category you want already exists under a slightly different name.',
            'inputSchema' => Schema::object([
                'name'   => Schema::string('Category name. Required when creating.'),
                'id'     => Schema::integer('Category id, to rename an existing category.'),
                'parent' => Schema::integer('Parent category id. 0 for top level.', 0),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $id   = $this->arg($arguments, 'id');
        $name = $this->arg($arguments, 'name');

        $existing = $id !== null ? $modx->getObject(modCategory::class, (int) $id) : null;
        if ($id !== null && !$existing) {
            throw McpException::invalidParams("No category with id {$id}");
        }

        $properties = $existing ? $existing->toArray() : [];

        if ($name !== null) {
            $properties['category'] = (string) $name;
        }
        if (array_key_exists('parent', $arguments) && $arguments['parent'] !== null) {
            $properties['parent'] = (int) $arguments['parent'];
        }

        if ($existing) {
            $properties['id'] = (int) $existing->get('id');
            $action = 'Element/Category/Update';
        } else {
            $this->requireArg($arguments, 'name');
            $action = 'Element/Category/Create';
        }

        $object = $this->runProcessor($modx, $action, $properties);

        // Re-read rather than trust the echo. The '?? $properties' fallbacks this
        // replaces were the tell: they existed because Element/Category/Create
        // does not reliably return these fields, which is the same doubt
        // ResourceSupport::summarise() acts on for resources.
        $savedId = (int) ($object['id'] ?? 0);
        if ($savedId <= 0) {
            throw McpException::internal(
                'The category processor reported success without returning an id, so the save '
                . 'cannot be confirmed. Check modxmcp_category_list.');
        }

        /** @var modCategory|null $saved */
        $saved = $modx->getObject(modCategory::class, $savedId);
        if (!$saved) {
            throw McpException::internal("Category {$savedId} could not be read back after saving");
        }

        return [
            'id'      => $savedId,
            'name'    => (string) $saved->get('category'),
            'parent'  => (int) $saved->get('parent'),
            'created' => $existing === null,
        ];
    }
}
