<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modAccessibleObject;
use MODX\Revolution\modElement;
use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODX\Revolution\Processors\Processor;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\ToolInterface;

/**
 * Base for modxmcp's tools.
 *
 * Two things here carry the extra's premise.
 *
 * runProcessor() is how every write that has a MODX processor goes through it.
 * Writing via a processor rather than saving an xPDO object is what fires the
 * lifecycle events extras depend on; bypassing it is what makes resources vanish
 * from sitemaps and Collections listings. Two paths write without one, because
 * MODX ships none for them: generic object access and SeoSuite redirects.
 *
 * requirePermission() and its siblings are how every tool asks MODX what the
 * Manager would ask before the same action. The token's scope decides which
 * tools a caller may reach at all; these decide what the MODX user it acts as
 * may do with them, so a token can never read or do more than that user could
 * in the Manager.
 */
abstract class AbstractTool implements ToolInterface
{
    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * Run a MODX processor and return its object payload.
     *
     * Processor failures are surfaced with their field errors intact. A model
     * that is told "alias: must be unique" can fix its own call; one told
     * "operation failed" cannot.
     *
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     * @throws McpException
     */
    protected function runProcessor(modX $modx, string $action, array $properties = []): array
    {
        $response = $modx->runProcessor($action, $properties);
        if (!$response) {
            throw McpException::internal("Processor '{$action}' could not be loaded");
        }

        $result = $response->getResponse();
        if (!is_array($result)) {
            $decoded = json_decode((string) $result, true);
            $result  = is_array($decoded) ? $decoded : [];
        }

        if (empty($result['success'])) {
            throw McpException::invalidParams($this->describeFailure($action, $result));
        }

        return is_array($result['object'] ?? null) ? $result['object'] : $result;
    }

    /**
     * Turn a processor's error structure into one actionable sentence.
     *
     * @param array<string,mixed> $result
     */
    private function describeFailure(string $action, array $result): string
    {
        $parts = [];

        foreach ((array) ($result['errors'] ?? []) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $field   = (string) ($error['id'] ?? $error['field'] ?? '');
            $message = (string) ($error['msg'] ?? $error['message'] ?? '');
            if ($message === '') {
                continue;
            }
            $parts[] = $field !== '' ? "{$field}: {$message}" : $message;
        }

        $top = trim((string) ($result['message'] ?? ''));
        if ($top !== '' && !in_array($top, $parts, true)) {
            array_unshift($parts, $top);
        }

        if ($parts === []) {
            $parts[] = 'the processor reported no detail';
        }

        return "MODX processor '{$action}' rejected the request (" . implode('; ', $parts) . ')';
    }

    /**
     * Trim an object down to the fields worth returning.
     *
     * Resources carry ~40 columns, most of which are noise to a caller and
     * collectively burn a lot of context when several are listed at once.
     *
     * @param array<string,mixed> $row
     * @param string[]            $fields
     * @return array<string,mixed>
     */
    protected function pick(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }
        return $out;
    }

    /**
     * Read an argument, treating '' and null as absent.
     *
     * @param array<string,mixed> $arguments
     * @return mixed
     */
    protected function arg(array $arguments, string $key, $default = null)
    {
        $value = $arguments[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Read an argument that has been renamed, accepting the older name too.
     *
     * The read half of this surface returns MODX's own column names, and a
     * caller reading a resource and reusing its fields to make another sends
     * back what it was given. Where an argument was once spelled differently
     * from the field -- `context` against the `context_key` every read
     * returns -- that round trip silently dropped the value and the write
     * landed on the default.
     *
     * Both names are declared in the schema, so neither is an unknown key, and
     * the current one wins. Sending both is refused rather than resolved: they
     * mean the same thing, so a caller that sent two has one of them wrong and
     * would rather be told than have a coin tossed.
     *
     * @param array<string,mixed> $arguments
     * @return mixed
     * @throws McpException
     */
    protected function renamedArg(array $arguments, string $current, string $older, $default = null)
    {
        $new = $this->arg($arguments, $current);
        $old = $this->arg($arguments, $older);

        if ($new !== null && $old !== null && (string) $new !== (string) $old) {
            throw McpException::invalidParams(sprintf(
                "Both %s and %s were given, with different values ('%s' and '%s'). They are two "
                . 'names for the same thing; %s is the current one. Nothing was written.',
                $current,
                $older,
                (string) $new,
                (string) $old,
                $current
            ));
        }

        return $new ?? $old ?? $default;
    }

    /**
     * @param array<string,mixed> $arguments
     * @throws McpException
     */
    protected function requireArg(array $arguments, string $key)
    {
        $value = $this->arg($arguments, $key);
        if ($value === null) {
            throw McpException::invalidParams("Missing required argument: {$key}");
        }
        return $value;
    }

    /**
     * Refuse unless the MODX user this token acts as holds every named
     * permission.
     *
     * Call it before anything is read, so a refusal can never be mistaken for
     * an empty site. $action is what the Manager needs the permission for, in
     * the infinitive ("view a plugin"); it is what the refusal names.
     *
     * Meaningful only while MODX evaluates policies, which Server establishes
     * before any tool runs (Authenticator::assertPermissionsEvaluable()).
     *
     * @param string[] $permissions
     * @throws McpException
     */
    protected function requirePermission(modX $modx, string $action, array $permissions, ?string $outcome = null): void
    {
        $lacking = [];
        foreach ($permissions as $permission) {
            if (!$modx->hasPermission($permission)) {
                $lacking[] = $permission;
            }
        }
        if ($lacking === []) {
            return;
        }

        $one = count($lacking) === 1;
        throw McpException::forbidden(sprintf(
            'The MODX user this token acts as (%s) lacks the %s permission%s, which %s what the '
            . 'Manager requires to %s. Grant %s through the user group\'s access policy, or use a '
            . 'token bound to a user that has %s. %s',
            $this->boundUsername($modx),
            $this->listing($lacking),
            $one ? '' : 's',
            $one ? 'is' : 'are',
            $action,
            $one ? 'it' : 'them',
            $one ? 'it' : 'them',
            $outcome ?? $this->outcome()
        ));
    }

    /**
     * Refuse unless the bound user passes $policy on one loaded object.
     *
     * The check MODX's GetProcessor::initialize() makes with 'view' before it
     * returns a row. A resource in a resource group, or an element in a
     * category, can carry an access policy of its own, which the user's
     * Manager permissions say nothing about. $what names the object in the
     * refusal ("resource 12", "chunk 'header'").
     *
     * @throws McpException
     */
    protected function requirePolicy(
        modX $modx,
        modAccessibleObject $object,
        string $policy,
        string $what,
        ?string $outcome = null
    ): void {
        if ($object->checkPolicy($policy)) {
            return;
        }

        if ($object instanceof modResource) {
            $owner = 'an access policy on a resource group it belongs to';
        } elseif ($object instanceof modElement) {
            $owner = 'an access policy on its category';
        } else {
            $owner = 'an access policy on it';
        }

        throw McpException::forbidden(sprintf(
            'The MODX user this token acts as (%s) may not %s %s: %s grants that user no "%s" '
            . 'access, which is the refusal the Manager gives. Grant it through that policy, or use '
            . 'a token bound to a user that has it. %s',
            $this->boundUsername($modx),
            $policy,
            $what,
            $owner,
            $policy,
            $outcome ?? $this->outcome()
        ));
    }

    /**
     * Ask a MODX processor's own permission check before this tool reads
     * anything on its way to running that processor.
     *
     * Processor::run() calls checkPermissions() first and refuses on false.
     * Asking the same question earlier means the reads a tool makes to prepare
     * a processor call -- resolving a category, counting where a template is
     * used, checking whether a file exists -- cannot answer a caller the
     * processor would turn away. The permission name comes from the processor,
     * never from this package, so it cannot drift from what MODX enforces.
     *
     * Only as good as the processor's checkPermissions(). A processor that
     * extends MODX's base Processor without overriding it is not gated by its
     * $permission at all -- Processor::checkPermissions() returns true -- and
     * needs an explicit requirePermission() instead. Resource/Delete is one.
     *
     * Building the processor to ask it has no side effect: Processor's
     * constructor only stores the properties, and getInstance() only calls it.
     *
     * @param array<string,mixed> $properties what the real call will send, for
     *        checks that read them (Resource/Create reads class_key)
     * @param string $hint permission named in the refusal when the processor
     *        decides in an override and declares none
     * @throws McpException
     */
    protected function requireProcessorPermission(
        modX $modx,
        string $action,
        array $properties = [],
        string $hint = ''
    ): void {
        $class = $this->processorClass($action);
        if ($class === null) {
            throw McpException::internal("Processor '{$action}' could not be loaded");
        }

        /** @var Processor $probe */
        $probe = $class::getInstance($modx, $class, $properties);
        if ($probe->checkPermissions()) {
            return;
        }

        $declared = (string) $probe->permission;
        $name     = $declared !== '' ? $declared : $hint;
        if ($name !== '') {
            $this->requirePermission($modx, "run its {$action} processor", [$name]);
        }

        // Refused without a name to give: the processor decides in an override
        // that this package cannot see into.
        throw McpException::forbidden(sprintf(
            'The MODX user this token acts as (%s) does not pass the permission check of the '
            . "Manager's %s processor. Grant the permission that processor requires through the "
            . "user group's access policy, or use a token bound to a user that has it. %s",
            $this->boundUsername($modx),
            $action,
            $this->outcome()
        ));
    }

    /**
     * Report rows a per-row policy kept off a page, or nothing.
     *
     * xPDO drops a row the user may not load before a tool ever sees it, and a
     * list then skips the rows the user may not list. $total stays the
     * unfiltered count, as MODX's own GetListProcessor reports it, so a short
     * page says why instead of leaving the caller to wonder whether it reached
     * the end.
     *
     * @return array{hidden?:int,note?:string}
     */
    protected function hiddenRows(int $limit, int $offset, int $total, int $returned, string $noun): array
    {
        $expected = max(0, min($limit, $total - $offset));
        $hidden   = max(0, $expected - $returned);
        if ($hidden === 0) {
            return [];
        }

        return [
            'hidden' => $hidden,
            'note'   => sprintf(
                '%d %s on this page %s not shown: an access policy gives the MODX user this token '
                . 'acts as no load or list access to %s. total still counts %s, as the Manager\'s '
                . 'own lists do.',
                $hidden,
                $noun,
                $hidden === 1 ? 'is' : 'are',
                $hidden === 1 ? 'it' : 'them',
                $hidden === 1 ? 'it' : 'them'
            ),
        ];
    }

    /**
     * The class modX::runProcessor() would run for an action, or null.
     *
     * Mirrors it exactly: a Processor subclass named outright is used as is,
     * and "Element/Chunk/Update" style actions map onto the 3.x namespace, with
     * the TV directory's historical name corrected the same way.
     */
    private function processorClass(string $action): ?string
    {
        $candidates = [$action];
        $legacy     = 'MODX\\Revolution\\Processors\\'
            . implode('\\', array_map('ucfirst', explode('/', $action)));
        $candidates[] = str_replace('\\Tv\\', '\\TemplateVar\\', $legacy);

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Processor::class, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function outcome(): string
    {
        return $this->requiredScope() === 'read' ? 'Nothing was read.' : 'Nothing was written.';
    }

    private function boundUsername(modX $modx): string
    {
        return $modx->user ? '"' . (string) $modx->user->get('username') . '"' : 'unknown';
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param string[] $items
     */
    protected function listing(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
