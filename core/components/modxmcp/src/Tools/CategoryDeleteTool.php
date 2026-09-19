<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modCategory;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Remove an empty element category.
 *
 * The gap this closes is small and annoying: category_list and category_save
 * existed, element_save refuses an unknown category name and so pushes a caller
 * into creating one, and a category created by a typo could then only be removed
 * through the Manager or in SQL.
 *
 * Emptiness is required, and that is this extra's rule rather than MODX's. MODX
 * deletes a category whatever is in it, and the consequences are not obvious
 * from the call:
 *
 *   modCategory::remove() resets every element in the category to category=0
 *   across all five element types, so the elements survive but silently leave
 *   the tree the caller was organising.
 *
 *   `Children` is a composite relation on modCategory, so xPDO cascades the
 *   delete to every descendant category first, and each of those uncategorises
 *   its own elements on the way out. One call on a parent can therefore
 *   dismantle a whole branch.
 *
 * Neither is reported. A model that created the wrong category and reaches for
 * the tool to undo it would, on a name collision, take a category tree with it
 * and be told only "deleted": true. So the count is taken first and a populated
 * category is refused, naming what is in it, which is recoverable by editing
 * one argument. Emptying it deliberately is still possible -- reassign the
 * elements with element_save, delete the children first -- it just has to be
 * meant.
 */
final class CategoryDeleteTool extends AbstractTool
{
    use ElementSupport;

    /** Element types whose rows carry a category, in tree order. */
    private const CATEGORISED = ['chunk', 'snippet', 'template', 'tv', 'plugin'];

    public function requiredScope(): string
    {
        // Same scope as category_save: categories organise elements rather than
        // being content, so this needs no scope of its own.
        return 'write:elements';
    }

    public function name(): string
    {
        return 'modxmcp_category_delete';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Delete an empty category',
            'description' => 'Permanently remove an element category. The category must be '
                . 'empty: one still holding chunks, snippets, templates, template variables or '
                . 'plugins is refused, and so is one with sub-categories, with the contents '
                . 'named so you can see what to move first. This is stricter than MODX, which '
                . 'deletes the category regardless and quietly resets everything inside it to '
                . 'uncategorised, cascading through sub-categories as it goes. Reassign elements '
                . 'with modxmcp_element_save before deleting. The elements themselves are never '
                . 'touched by this tool.',
            'inputSchema' => Schema::object([
                'id'   => Schema::integer('Category id. Either this or name is required.'),
                'name' => Schema::string('Category name, if you do not have the id.'),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $id   = $this->arg($arguments, 'id');
        $name = $this->arg($arguments, 'name');

        // Before the lookup, not after. getObject(modCategory::class, 0)
        // returns null, so a check further down would sit behind the
        // not-found refusal and never run -- which is how the dead warning
        // this tool's sibling used to carry got there.
        if ($id !== null && (int) $id === 0) {
            throw McpException::invalidParams(
                'Category 0 is not a category, it is how an element records having none. There '
                . 'is nothing to delete. Nothing was deleted.');
        }

        if ($id !== null) {
            $category = $modx->getObject(modCategory::class, (int) $id);
        } elseif ($name !== null) {
            $category = $modx->getObject(modCategory::class, ['category' => (string) $name]);
        } else {
            throw McpException::invalidParams('Provide either id or name');
        }

        if (!$category) {
            $which = $id !== null ? "id {$id}" : "name '{$name}'";
            throw McpException::invalidParams(sprintf(
                'No category with %s. Use modxmcp_category_list to see what this site has. '
                . 'Nothing was deleted.',
                $which
            ));
        }

        $categoryId = (int) $category->get('id');

        $this->assertEmpty($modx, $categoryId, (string) $category->get('category'));

        $removed = [
            'id'     => $categoryId,
            'name'   => (string) $category->get('category'),
            'parent' => (int) $category->get('parent'),
        ];

        $this->runProcessor($modx, 'Element/Category/Remove', ['id' => $categoryId]);

        return [
            'deleted'     => true,
            'recoverable' => false,
            'removed'     => $removed,
        ];
    }

    /**
     * Refuse a category that still holds anything.
     *
     * Counted before the processor runs, so a refusal leaves the site exactly
     * as it was. Contents are named rather than totalled for the reason the
     * element reference check names callers: a count tells the caller it cannot
     * proceed without telling it what to do next.
     *
     * @throws McpException
     */
    private function assertEmpty(modX $modx, int $categoryId, string $categoryName): void
    {
        $holdings = [];

        foreach (self::CATEGORISED as $typeKey) {
            $type  = $this->elementType($typeKey);
            $names = [];
            foreach ($modx->getIterator($type['class'], ['category' => $categoryId]) as $element) {
                $names[] = (string) $element->get($type['name']);
            }
            if ($names !== []) {
                sort($names);
                $holdings[] = sprintf(
                    '%d %s(s): %s',
                    count($names),
                    $typeKey,
                    implode(', ', array_slice($names, 0, 20))
                        . (count($names) > 20 ? ', and more' : '')
                );
            }
        }

        $children = [];
        foreach ($modx->getIterator(modCategory::class, ['parent' => $categoryId]) as $child) {
            $children[] = sprintf('%s (%d)', (string) $child->get('category'), (int) $child->get('id'));
        }
        if ($children !== []) {
            sort($children);
            $holdings[] = sprintf(
                '%d sub-categor%s: %s',
                count($children),
                count($children) === 1 ? 'y' : 'ies',
                implode(', ', $children)
            );
        }

        if ($holdings === []) {
            return;
        }

        throw McpException::invalidParams(sprintf(
            "Category '%s' (%d) is not empty: it holds %s. MODX would delete it anyway and reset "
            . 'everything inside to uncategorised, cascading through sub-categories, without '
            . 'saying so, which is why this refuses instead. Move the elements with '
            . 'modxmcp_element_save by passing a different category, delete the sub-categories '
            . 'first, then retry. Nothing was deleted.',
            $categoryName,
            $categoryId,
            implode('; ', $holdings)
        ));
    }
}
