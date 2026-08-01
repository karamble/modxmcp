<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarResource;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * Resolving template variables against the template that will actually render.
 *
 * This is the subtle part of writing a resource, and it used to fail silently.
 *
 * The Manager submits TV values as tv{id} form properties and the Resource
 * processors consume exactly that, so mapping caller-supplied names to ids is
 * unavoidable. What the old mapping missed is which TVs the processor will even
 * look at. modResource::getTemplateVarCollection() inner joins
 * modTemplateVarTemplate on templateid = resource.template, and both Resource
 * processors iterate that collection and nothing else. A tv{id} property for a
 * TV outside it is not rejected, not logged and not saved: the loop simply never
 * reaches it, the processor returns success, and the caller is told the value
 * was written.
 *
 * So resolution here is against the attached set, not against every TV on the
 * site, and a name outside it is an error rather than a warning. The check runs
 * before the processor, which means a rejection leaves nothing half-written.
 */
trait TemplateVarSupport
{
    /**
     * The TVs a template declares, keyed by name.
     *
     * Two queries rather than an inner join, because the join would depend on a
     * relation alias spelling and this does not. It also replaces the old
     * one-getObject-per-name pattern with a bounded pair.
     *
     * @return array<string,modTemplateVar>
     */
    protected function attachedTvs(modX $modx, int $templateId): array
    {
        if ($templateId <= 0) {
            return [];
        }

        $ids = [];
        foreach ($modx->getIterator(modTemplateVarTemplate::class, ['templateid' => $templateId]) as $link) {
            $ids[] = (int) $link->get('tmplvarid');
        }
        if ($ids === []) {
            return [];
        }

        $attached = [];
        foreach ($modx->getIterator(modTemplateVar::class, ['id:IN' => $ids]) as $tv) {
            /** @var modTemplateVar $tv */
            $attached[(string) $tv->get('name')] = $tv;
        }

        return $attached;
    }

    /**
     * Map a name-keyed value map to the tv{id} properties the processors want.
     *
     * Every bad name is collected before anything is thrown. A model handed
     * three problems at once fixes the call once; handed them one at a time it
     * burns three round trips.
     *
     * @param array<string,mixed> $tvs
     * @return array<string,mixed>
     * @throws McpException before any write has happened
     */
    protected function resolveTvs(modX $modx, array $tvs, int $templateId): array
    {
        if ($tvs === []) {
            return [];
        }

        $attached = $this->attachedTvs($modx, $templateId);
        $problems = [];
        $properties = [];

        foreach ($tvs as $name => $value) {
            $name = (string) $name;

            if (isset($attached[$name])) {
                $tv = $attached[$name];
                $properties['tv' . (int) $tv->get('id')] = $this->encodeTvValue($tv, $value);
                continue;
            }

            // Distinguish the two failures, because the fixes are different:
            // one is a typo, the other is a template-configuration problem.
            if ($modx->getObject(modTemplateVar::class, ['name' => $name])) {
                $problems[] = $templateId > 0
                    ? sprintf(
                        "Template variable '%s' exists but is not attached to template %d, so "
                        . 'MODX would discard the value: the Resource processors only write TVs '
                        . "that the resource's template declares. Attach it to template %d in "
                        . 'the Manager, or use a template that already has it.',
                        $name,
                        $templateId,
                        $templateId
                    )
                    : sprintf(
                        "Template variable '%s' cannot be written because this resource has no "
                        . 'template. Set a template first, in this call or a previous one.',
                        $name
                    );
                continue;
            }

            $problems[] = sprintf(
                "Unknown template variable '%s'. Use modxmcp_element_list with type=tv to see "
                . 'what exists on this site.',
                $name
            );
        }

        if ($problems !== []) {
            throw McpException::invalidParams(
                implode(' ', $problems) . ' ' . $this->describeAttached($attached, $templateId)
                . ' Nothing was written.'
            );
        }

        return $properties;
    }

    /**
     * Tell the caller what it could have used.
     *
     * An error that only says what is wrong gets retried verbatim; one that also
     * says what is available gets corrected.
     *
     * @param array<string,modTemplateVar> $attached
     */
    private function describeAttached(array $attached, int $templateId): string
    {
        if ($templateId <= 0) {
            return '';
        }
        if ($attached === []) {
            return sprintf('Template %d declares no template variables at all.', $templateId);
        }

        $names = array_keys($attached);
        sort($names);
        $shown = array_slice($names, 0, 40);
        $more  = count($names) - count($shown);

        return sprintf(
            'Template %d declares: %s%s.',
            $templateId,
            implode(', ', $shown),
            $more > 0 ? sprintf(' and %d more', $more) : ''
        );
    }

    /**
     * Encode a value the way the processor expects for this TV's type.
     *
     * The processor's own switch is the specification here. It comma-splits tag
     * and autotag values, and its default branch implodes arrays with '||'.
     * Which means:
     *
     *  - MIGX has no case, so it lands in the default branch, and an item array
     *    handed over raw would be '||'-joined into garbage. It must arrive as
     *    the JSON it stores.
     *  - tag and autotag reach explode(',', $value) unconditionally, so an array
     *    is a TypeError under PHP 8. Join it here.
     *  - anything else can stay an array and let the processor do the '||' join,
     *    which is the behaviour its own multi-select inputs expect.
     *
     * @param mixed $value
     * @return mixed
     */
    private function encodeTvValue(modTemplateVar $tv, $value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = strtolower((string) $tv->get('type'));

        if ($type === 'tag' || $type === 'autotag') {
            return implode(',', array_map('strval', $value));
        }

        if ($type === 'migx' || $this->isAssociative($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        foreach ($value as $item) {
            // A list of scalars is a multi-select; a list of rows is MIGX under
            // a TV type this build does not recognise by name.
            if (is_array($item)) {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $value;
    }

    /** @param array<mixed> $value */
    private function isAssociative(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Effective values for the TVs a given template declares, keyed by name.
     *
     * @return array<string,mixed>
     */
    protected function readTvsForTemplate(modX $modx, int $resourceId, int $templateId): array
    {
        $values = [];
        foreach ($this->attachedTvs($modx, $templateId) as $name => $tv) {
            $values[$name] = $tv->getValue($resourceId);
        }
        return $values;
    }

    /**
     * Effective values for named TVs, for echoing a write back to the caller.
     *
     * Reports the effective value rather than the presence of a stored row, and
     * the difference is not academic: getValue() falls back to default_text, and
     * the Update processor deliberately deletes a modTemplateVarResource row
     * whose value equals the computed default. Writing a value that happens to
     * equal the default therefore stores nothing and reads back as what was
     * sent, which is correct. The resource renders what was asked for.
     *
     * @param string[] $names
     * @return array<string,mixed>
     */
    protected function readTvValues(modX $modx, int $resourceId, array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            /** @var modTemplateVar|null $tv */
            $tv = $modx->getObject(modTemplateVar::class, ['name' => (string) $name]);
            if ($tv) {
                $values[(string) $name] = $tv->getValue($resourceId);
            }
        }
        return $values;
    }

    /**
     * Which TVs a template change takes out of, and brings into, effect.
     *
     * @return string[]
     */
    protected function templateChangeWarnings(modX $modx, int $fromTemplate, int $toTemplate): array
    {
        if ($fromTemplate === $toTemplate) {
            return [];
        }

        $before = array_keys($this->attachedTvs($modx, $fromTemplate));
        $after  = array_keys($this->attachedTvs($modx, $toTemplate));

        $lost   = array_diff($before, $after);
        $gained = array_diff($after, $before);

        if ($lost === [] && $gained === []) {
            return [];
        }

        $message = sprintf('Template changed from %d to %d.', $fromTemplate, $toTemplate);
        if ($lost !== []) {
            sort($lost);
            $message .= sprintf(
                ' Template variables no longer in effect: %s. Their stored values remain in the '
                . 'database but are not rendered and no longer appear in modxmcp_resource_get.',
                implode(', ', $lost)
            );
        }
        if ($gained !== []) {
            sort($gained);
            $message .= sprintf(
                ' Now in effect, currently at their defaults: %s. Set them with a follow-up '
                . 'modxmcp_resource_update if the defaults are not what you want.',
                implode(', ', $gained)
            );
        }

        return [$message];
    }

    /**
     * Unused rows are not an error worth reporting, so this stays quiet.
     *
     * Kept so callers that only need to know whether a value is stored at all
     * do not have to reach for modTemplateVarResource themselves.
     */
    protected function tvRowExists(modX $modx, int $resourceId, int $tvId): bool
    {
        return $modx->getCount(modTemplateVarResource::class, [
            'contentid'  => $resourceId,
            'tmplvarid'  => $tvId,
        ]) > 0;
    }
}
