<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Describe the structure of a MIGX template variable.
 *
 * A MIGX TV stores a JSON array of objects, and the database says only "text".
 * The actual shape lives in the TV's input properties, either inline as
 * "formtabs" or by reference to a named MIGX config. Without reading that, a
 * caller writing a MIGX value is guessing at key names, and a wrong guess
 * produces a TV that saves cleanly and renders as nothing.
 */
final class MigxDescribeTool extends AbstractTool
{
    public function name(): string
    {
        return 'modxmcp_migx_describe';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Describe a MIGX template variable',
            'description' => 'Return the item structure of a MIGX template variable: the field '
                . 'names, captions and input types each row may contain. MIGX TVs hold a JSON '
                . 'array whose shape exists only in the TV configuration, so read this before '
                . 'writing one with modxmcp_resource_create or modxmcp_resource_update. Omit the '
                . 'tv argument to list every MIGX TV on the site.',
            'inputSchema' => Schema::object([
                'tv' => Schema::string('MIGX template variable name. Omit to list all of them.'),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $name = $this->arg($arguments, 'tv');

        if ($name === null) {
            $all = [];
            foreach ($modx->getCollection(modTemplateVar::class, ['type' => 'migx']) as $tv) {
                $all[] = [
                    'tv'      => $tv->get('name'),
                    'id'      => (int) $tv->get('id'),
                    'caption' => $tv->get('caption'),
                ];
            }
            return ['migx_tvs' => $all, 'count' => count($all)];
        }

        /** @var modTemplateVar|null $tv */
        $tv = $modx->getObject(modTemplateVar::class, ['name' => (string) $name]);
        if (!$tv) {
            throw McpException::invalidParams("No template variable named '{$name}'");
        }
        if ($tv->get('type') !== 'migx') {
            throw McpException::invalidParams(
                "Template variable '{$name}' is of type '{$tv->get('type')}', not migx.");
        }

        $properties = (array) $tv->get('input_properties');
        $tabs       = $this->resolveTabs($modx, $properties);

        $fields = [];
        foreach ($tabs as $tab) {
            foreach ((array) ($tab['fields'] ?? []) as $field) {
                if (empty($field['field'])) {
                    continue;
                }
                $fields[] = array_filter([
                    'field'   => $field['field'],
                    'caption' => $field['caption'] ?? null,
                    'type'    => $field['inputTVtype'] ?? null,
                ], static fn($v) => $v !== null && $v !== '');
            }
        }

        $configName = trim((string) ($properties['migx_configs'] ?? ''));
        if ($tabs === []) {
            $source = 'unknown';
        } elseif ($configName !== '') {
            $source = 'named config: ' . $configName;
        } else {
            $source = 'inline formtabs';
        }

        return [
            'tv'          => $tv->get('name'),
            'id'          => (int) $tv->get('id'),
            'source'      => $source,
            'item_fields' => $fields,
            'write_as'    => 'A JSON array of objects using these field names, e.g. '
                . '[{"' . ($fields[0]['field'] ?? 'field') . '": "value"}]. Pass it to the tvs '
                . 'argument of modxmcp_resource_create or modxmcp_resource_update, which encodes '
                . 'arrays to JSON for you.',
        ];
    }

    /**
     * Structure comes either inline or from a named MIGX config; both are used
     * in practice and a site can mix them.
     *
     * @param array<string,mixed> $properties
     * @return array<int,array<string,mixed>>
     */
    private function resolveTabs(modX $modx, array $properties): array
    {
        $inline = $properties['formtabs'] ?? '';
        if (is_string($inline) && trim($inline) !== '') {
            $decoded = json_decode($inline, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($inline) && $inline !== []) {
            return $inline;
        }

        $configName = trim((string) ($properties['migx_configs'] ?? ''));
        if ($configName === '') {
            return [];
        }

        // Named configs live in MIGX's own tables, discovered like any other
        // extra model rather than hardcoded.
        $config = $modx->getObject('migxConfig', ['name' => $configName]);
        if (!$config) {
            return [];
        }

        $tabs = [];
        foreach ($modx->getCollection('migxFormtab', ['configs' => $config->get('id')]) as $tab) {
            $fields = [];
            foreach ($modx->getCollection('migxFormtabField', ['formtabs' => $tab->get('id')]) as $field) {
                $fields[] = [
                    'field'       => $field->get('field'),
                    'caption'     => $field->get('caption'),
                    'inputTVtype' => $field->get('inputTVtype'),
                ];
            }
            $tabs[] = ['caption' => $tab->get('caption'), 'fields' => $fields];
        }

        return $tabs;
    }
}
