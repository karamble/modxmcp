<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modCategory;
use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * List element categories.
 *
 * Categories were the one part of the element surface with no way in at all:
 * modxmcp_element_save took a numeric category id, modxmcp_element_list returned
 * a numeric category id, and nothing anywhere mapped an id to a name. A caller
 * told "put this chunk in the Blog category" had no way to comply, and no way to
 * discover that it had put it somewhere else.
 *
 * Element counts are included because the useful question is usually "which
 * category do things like this already live in", and a name on its own does not
 * answer it.
 */
final class CategoryListTool extends AbstractTool
{
    use ElementSupport;

    public function name(): string
    {
        return 'modxmcp_category_list';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List element categories',
            'description' => 'List the categories elements can be filed under, with their parent '
                . 'and how many elements of each type they hold. Use this to turn a category name '
                . 'into the id that modxmcp_element_save expects, or to see where similar '
                . 'elements already live.',
            'inputSchema' => Schema::object([
                'search' => Schema::string('Match against the category name.'),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $criteria = [];
        if (($search = $this->arg($arguments, 'search')) !== null) {
            $criteria['category:LIKE'] = '%' . $search . '%';
        }

        $categories = [];
        foreach ($modx->getIterator(modCategory::class, $criteria) as $category) {
            /** @var modCategory $category */
            $id = (int) $category->get('id');
            $categories[] = [
                'id'       => $id,
                'name'     => (string) $category->get('category'),
                'parent'   => (int) $category->get('parent'),
                'elements' => $this->elementCounts($modx, $id),
            ];
        }

        usort($categories, fn($a, $b) => [$a['parent'], $a['name']] <=> [$b['parent'], $b['name']]);

        return [
            'total'      => count($categories),
            'categories' => $categories,
        ];
    }

    /**
     * How many elements of each type sit in a category.
     *
     * @return array<string,int>
     */
    private function elementCounts(modX $modx, int $categoryId): array
    {
        $counts = [];
        foreach ($this->elementTypeKeys() as $typeKey) {
            $type  = $this->elementType($typeKey);
            $count = $modx->getCount($type['class'], ['category' => $categoryId]);
            if ($count > 0) {
                $counts[$typeKey] = (int) $count;
            }
        }
        return $counts;
    }
}
