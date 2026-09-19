<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Copy a resource, then make the copy visible to everything that cares.
 *
 * Resource/Duplicate is not a create. It extends the plain Processor rather than
 * CreateProcessor and fires only OnResourceDuplicate, so none of the Manager
 * save path runs for the copy: SeoSuite never registers it and it is silently
 * absent from sitemap.xml, Collections never applies its rules, and the cache is
 * never cleared. That is the precise failure this extra exists to prevent, so a
 * thin wrapper around the processor would have shipped the bug it was written to
 * avoid.
 *
 * The copy is therefore re-saved through Resource/Update immediately afterwards,
 * which is a real Manager save and fires OnBeforeDocFormSave and OnDocFormSave.
 *
 * Two other things the processor does that a caller will not expect:
 *
 *  - `parent` is not accepted. modResource::duplicate() supports it, the
 *    processor never passes it, and the copy always lands beside the original.
 *  - On an alias collision MODX does not de-duplicate, it empties the alias
 *    (modResource.php:1159), leaving MODX to derive a URI from the id. With
 *    friendly URLs off it does not check at all and copies the alias verbatim,
 *    producing two resources with the same one.
 */
final class ResourceDuplicateTool extends AbstractTool
{
    use ResourceSupport;

    public function requiredScope(): string
    {
        return 'write:content';
    }

    public function name(): string
    {
        return 'modxmcp_resource_duplicate';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Duplicate a resource',
            'description' => 'Copy a resource, including its template variable values. The copy '
                . 'is created beside the original: MODX does not accept a different parent when '
                . 'duplicating, so move it afterwards with modxmcp_resource_update if you need '
                . 'it elsewhere. The copy is re-saved through the normal update path so extras '
                . 'such as SeoSuite register it; without that it would exist but be missing from '
                . 'sitemap.xml.',
            'inputSchema' => Schema::object([
                'id'   => Schema::integer('Resource to copy. Required.'),
                'name' => Schema::string('Page title for the copy. Defaults to the original\'s, '
                    . 'which MODX allows.'),
                'alias' => Schema::string('Alias for the copy. Strongly recommended: on a '
                    . 'collision MODX empties the alias rather than making it unique, and the '
                    . 'copy then has a URL derived from its id.'),
                'duplicate_children' => Schema::boolean(
                    'Also copy the resource\'s children, recursively.', false),
                'published_mode' => Schema::enum(
                    'Published state of the copy. "preserve" keeps the original\'s.',
                    ['preserve', 'publish', 'unpublish'],
                    'preserve'),
            ], ['id']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $id = (int) $this->requireArg($arguments, 'id');

        /** @var modResource|null $source */
        $source = $modx->getObject(modResource::class, $id);
        if (!$source) {
            throw McpException::invalidParams("No resource with id {$id}");
        }

        $sourceAlias = (string) $source->get('alias');

        // Property names are inconsistent in core: `name` not `newName`,
        // `duplicate_children` not `duplicateChildren`, and `prefixDuplicate` in
        // camelCase beside them. Translated here so callers see one convention.
        $properties = ['id' => $id];
        if (($name = $this->arg($arguments, 'name')) !== null) {
            $properties['name'] = (string) $name;
        }
        if (!empty($arguments['duplicate_children'])) {
            $properties['duplicate_children'] = true;
        }
        if (($mode = $this->arg($arguments, 'published_mode')) !== null) {
            $properties['published_mode'] = (string) $mode;
        }

        $object = $this->runProcessor($modx, 'Resource/Duplicate', $properties);

        $newId = (int) ($object['id'] ?? 0);
        if ($newId <= 0) {
            throw McpException::internal(
                'The duplicate processor reported success without returning an id, so the copy '
                . 'cannot be confirmed.');
        }

        $warnings = $this->republish($modx, $newId, $this->arg($arguments, 'alias'));

        $result = $this->summarise($modx, $newId);
        $result['duplicated_from'] = $id;

        // The description promises the copy carries the original's template
        // variable values, and resource_create echoes what it stored for the
        // same reason: without this the only way to confirm the promise held is
        // to re-read the copy. Read after the republish rather than reusing
        // what it submitted, on the principle summarise() already applies to
        // the resource's own fields.
        //
        // Stored values, not effective ones. The first cut of this used
        // readTvsForTemplate() and, on a template with twenty-seven TVs,
        // answered a two-value copy with twenty-five nulls -- describing the
        // template rather than the copy.
        $tvs = $this->storedTvs($modx, $newId, (int) ($result['template'] ?? 0));
        if ($tvs !== []) {
            $result['tvs'] = $tvs;
        }

        $newAlias = (string) ($result['alias'] ?? '');
        if ($newAlias === '') {
            $warnings[] = sprintf(
                'The copy has no alias. MODX empties it rather than making it unique when the '
                . 'original\'s alias ("%s") is already taken, so the copy is reachable only by '
                . 'a URL derived from its id. Set one with modxmcp_resource_update.',
                $sourceAlias
            );
        } elseif ($newAlias === $sourceAlias) {
            $warnings[] = sprintf(
                'The copy has the same alias as the original ("%s"). MODX only checks for '
                . 'collisions when friendly URLs are enabled, so two resources now share it.',
                $sourceAlias
            );
        }

        $warnings = array_merge($warnings, $this->parentWarnings(
            $modx,
            (int) ($result['parent'] ?? 0),
            null
        ));
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Re-save the copy through the Manager save path.
     *
     * Resource/Duplicate fires no form event, so this is what makes SeoSuite and
     * Collections aware of the copy. It is a full resubmission of the stored row
     * for the same reason ResourceUpdateTool is: Resource/Update mirrors a
     * Manager form and blanks what it is not sent, and checkFriendlyAlias() will
     * set the alias to null outright if it is absent.
     *
     * @return string[]
     */
    private function republish(modX $modx, int $newId, ?string $alias): array
    {
        /** @var modResource|null $copy */
        $copy = $modx->getObject(modResource::class, $newId);
        if (!$copy) {
            throw McpException::internal("Duplicate {$newId} could not be read back");
        }

        $properties = $copy->toArray();
        unset($properties['uri']);

        if ($alias !== null && $alias !== '') {
            $properties['alias'] = $alias;
        }

        // Resubmit the copy's own TV values, and set the flag without which the
        // processor ignores every tv{id} property it is given.
        $tvs = $this->readTvsForTemplate($modx, $newId, (int) $copy->get('template'));
        if ($tvs !== []) {
            $properties = array_replace(
                $properties,
                $this->resolveTvs($modx, $tvs, (int) $copy->get('template'))
            );
            $properties['tvs'] = 1;
        }

        $this->runProcessor($modx, 'Resource/Update', $properties);

        return [];
    }
}
