<?php

namespace MODXMCP\Knowledge\Advisories;

use MODX\Revolution\modResource;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modX;
use MODXMCP\Knowledge\Advisory;
use MODXMCP\Knowledge\AdvisoryInterface;

/**
 * A template variable named after a resource field is shadowed by it.
 *
 * The reserved set is derived from the resource's own column list rather than
 * hardcoded, because the reserved set IS the column list by definition. A fixed
 * list would drift with every MODX release and would have to be maintained for
 * no reason.
 *
 * The not_affected block is load-bearing. This is a rendering collision only:
 * modxmcp resolves TVs by name to tv{id} properties, so its own reads and writes
 * are unaffected. Without saying so, a model that sees this advisory will start
 * working around a problem it does not have.
 */
final class ReservedTvNameAdvisory implements AdvisoryInterface
{
    public function id(): string
    {
        return 'tv.reserved_names';
    }

    public function detect(modX $modx): ?Advisory
    {
        $reserved = array_map('strtolower', array_keys((array) $modx->getFieldMeta(modResource::class)));
        if ($reserved === []) {
            return null;
        }

        $collisions = [];
        foreach ($modx->getIterator(modTemplateVar::class) as $tv) {
            $name = (string) $tv->get('name');
            if (in_array(strtolower($name), $reserved, true)) {
                $collisions[] = ['tv_id' => (int) $tv->get('id'), 'name' => $name];
            }
        }

        if ($collisions === []) {
            return null;
        }

        $names = array_column($collisions, 'name');

        return Advisory::gotcha(
            $this->id(),
            sprintf(
                '%d template variable%s shadowed by a resource field of the same name and '
                . 'cannot be read with [[*name]]: %s.',
                count($collisions),
                count($collisions) === 1 ? ' is' : 's are',
                implode(', ', $names)
            ),
            [
                'collisions' => $collisions,
                'source'     => 'column set of ' . modResource::class,
            ],
            [
                'symptom' => '[[*name]] renders the resource field, not the template variable',
                'fix'     => [
                    'rename the TV with modxmcp_element_save (type=tv)',
                    'or read it through a snippet such as pdoField',
                ],
                'not_affected' => [
                    'the tvs map returned by modxmcp_resource_get',
                    'the tvs argument of modxmcp_resource_create and modxmcp_resource_update',
                ],
            ]
        );
    }
}
