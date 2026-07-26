<?php

namespace MODXMCP\Discovery;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modX;

/**
 * Describes a discovered class: fields, types, relations, and human labels.
 *
 * Labels come from the extra's own lexicon files. Field names alone are a poor
 * guide for a caller deciding what to put in them, and the extra's authors
 * already wrote human descriptions for their Manager UI. Reusing those is free
 * and better than anything that could be inferred from a column type.
 */
final class SchemaMapper
{
    private modX $modx;

    /** @var array<string,array<string,string>> */
    private array $lexiconCache = [];

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * @param array{class:string,extra:string,layout:string} $entry
     * @return array<string,mixed>
     */
    public function describe(string $class, array $entry): array
    {
        $fieldMeta = $this->modx->getFieldMeta($class) ?: [];
        $fields    = $this->modx->getFields($class) ?: [];
        $lexicon   = $this->lexicon($entry['extra']);

        $described = [];
        foreach ($fieldMeta as $name => $meta) {
            $described[$name] = array_filter([
                'type'        => $this->jsonType((string) ($meta['phptype'] ?? 'string')),
                'db_type'     => $meta['dbtype'] ?? null,
                'nullable'    => !empty($meta['null']),
                'default'     => $fields[$name] ?? ($meta['default'] ?? null),
                'indexed'     => !empty($meta['index']),
                'description' => $this->label($name, $entry['extra'], $lexicon),
            ], static fn($v) => $v !== null && $v !== '');
        }

        $relations = [];
        $related = [
            'aggregate' => (array) ($this->modx->getAggregates($class) ?: []),
            'composite' => (array) ($this->modx->getComposites($class) ?: []),
        ];
        foreach ($related as $kind => $definitions) {
            foreach ($definitions as $alias => $definition) {
                if (!is_array($definition)) {
                    continue;
                }
                $relations[(string) $alias] = array_filter([
                    'kind'        => $kind,
                    'class'       => $definition['class'] ?? '',
                    'local'       => $definition['local'] ?? '',
                    'foreign'     => $definition['foreign'] ?? '',
                    'cardinality' => $definition['cardinality'] ?? '',
                ], static fn($v) => $v !== '' && $v !== null);
            }
        }

        return [
            'class'       => $class,
            'extra'       => $entry['extra'],
            'layout'      => $entry['layout'],
            'table'       => $this->modx->getTableName($class),
            'primary_key' => $this->modx->getPK($class),
            'fields'      => $described,
            'relations'   => $relations,
        ];
    }

    /**
     * Map an xPDO phptype onto a JSON Schema type.
     *
     * Dates stay strings: they cross the wire as strings and inventing a richer
     * type here would only mislead a caller about what to send back.
     */
    private function jsonType(string $phptype): string
    {
        switch (strtolower($phptype)) {
            case 'integer':
            case 'int':
                return 'integer';
            case 'float':
            case 'double':
            case 'decimal':
                return 'number';
            case 'boolean':
            case 'bool':
                return 'boolean';
            case 'array':
            case 'json':
                return 'object';
            default:
                return 'string';
        }
    }

    /**
     * Best available human label for a field.
     *
     * Extras key their lexicons inconsistently, so several shapes are tried
     * before giving up: "<extra>.<field>", "<field>", "<extra>_<field>".
     *
     * @param array<string,string> $lexicon
     */
    private function label(string $field, string $extra, array $lexicon): ?string
    {
        foreach (["{$extra}.{$field}", $field, "{$extra}_{$field}", "{$field}_desc"] as $key) {
            if (isset($lexicon[$key]) && trim($lexicon[$key]) !== '') {
                return $lexicon[$key];
            }
        }
        return null;
    }

    /**
     * Load an extra's English lexicon entries.
     *
     * These files assign into a local $_lang array and nothing else, so
     * including them in a scoped function is safe and is how MODX itself reads
     * them.
     *
     * @return array<string,string>
     */
    private function lexicon(string $extra): array
    {
        if (isset($this->lexiconCache[$extra])) {
            return $this->lexiconCache[$extra];
        }

        $namespace = $this->modx->getObject(modNamespace::class, ['name' => $extra]);
        if (!$namespace) {
            return $this->lexiconCache[$extra] = [];
        }

        $entries = [];
        foreach (glob(rtrim($namespace->getCorePath(), '/') . '/lexicon/en/*.inc.php') ?: [] as $file) {
            $loaded = (static function (string $path): array {
                $_lang = [];
                include $path;
                return is_array($_lang) ? $_lang : [];
            })($file);
            $entries += $loaded;
        }

        return $this->lexiconCache[$extra] = $entries;
    }
}
