<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modChunk;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modTemplateVar;
use MODXMCP\Protocol\McpException;

/**
 * The five MODX element types, behind one set of tools.
 *
 * They are near-identical in behaviour and wildly inconsistent in naming: the
 * body lives in "snippet" for chunks and snippets, "content" for templates,
 * "plugincode" for plugins and "default_text" for template variables, and a
 * template's name is "templatename" while everything else uses "name". Five
 * separate tool families would multiply that inconsistency across the tool
 * surface; this table absorbs it once.
 */
trait ElementSupport
{
    /**
     * @return array<string,array{class:string,processor:string,name:string,content:string,label:string}>
     */
    private function elementTypes(): array
    {
        return [
            'chunk' => [
                'class'     => modChunk::class,
                'processor' => 'Element/Chunk',
                'name'      => 'name',
                'content'   => 'snippet',
                'label'     => 'chunk (a reusable HTML fragment)',
            ],
            'snippet' => [
                'class'     => modSnippet::class,
                'processor' => 'Element/Snippet',
                'name'      => 'name',
                'content'   => 'snippet',
                'label'     => 'snippet (PHP executed on render)',
            ],
            'template' => [
                'class'     => modTemplate::class,
                'processor' => 'Element/Template',
                'name'      => 'templatename',
                'content'   => 'content',
                'label'     => 'template (the page shell)',
            ],
            'tv' => [
                'class'     => modTemplateVar::class,
                'processor' => 'Element/TemplateVar',
                'name'      => 'name',
                'content'   => 'default_text',
                'label'     => 'template variable (a custom field on resources)',
            ],
            'plugin' => [
                'class'     => modPlugin::class,
                'processor' => 'Element/Plugin',
                'name'      => 'name',
                'content'   => 'plugincode',
                'label'     => 'plugin (PHP bound to system events)',
            ],
        ];
    }

    /**
     * @return array{class:string,processor:string,name:string,content:string,label:string}
     * @throws McpException
     */
    protected function elementType(string $type): array
    {
        $types = $this->elementTypes();
        $key   = strtolower(trim($type));
        if (!isset($types[$key])) {
            throw McpException::invalidParams(
                "Unknown element type '{$type}'. Expected one of: " . implode(', ', array_keys($types)));
        }
        return $types[$key];
    }

    /** @return string[] */
    protected function elementTypeKeys(): array
    {
        return array_keys($this->elementTypes());
    }

    /**
     * Normalise an element row so callers see the same shape whatever the type.
     *
     * @param array<string,mixed> $row
     * @param array{name:string,content:string} $type
     * @return array<string,mixed>
     */
    protected function normaliseElement(array $row, array $type, bool $includeContent): array
    {
        $out = [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => $row[$type['name']] ?? '',
            'description' => $row['description'] ?? '',
            'category'    => (int) ($row['category'] ?? 0),
            'locked'      => !empty($row['locked']),
        ];

        if (array_key_exists('static', $row)) {
            $out['static']      = !empty($row['static']);
            $out['static_file'] = $row['static_file'] ?? '';
        }
        if (array_key_exists('disabled', $row)) {
            $out['disabled'] = !empty($row['disabled']);
        }
        if (isset($row['type'])) {
            $out['input_type'] = $row['type'];
        }

        if ($includeContent) {
            $out['content'] = $row[$type['content']] ?? '';
        }

        return $out;
    }
}
