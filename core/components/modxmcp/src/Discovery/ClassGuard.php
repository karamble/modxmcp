<?php

namespace MODXMCP\Discovery;

use MODX\Revolution\modX;

/**
 * Decides which xPDO classes generic object access may touch.
 *
 * This is the only thing standing between a token and every table on the site,
 * and that is worth being explicit about. The curated resource and element tools
 * write through MODX processors, which run their own checkPermissions() against
 * the bound user, so MODX's ACL applies. Generic access has no such backstop:
 * xPDO has no per-class permission model, so getCollection() on an arbitrary
 * class is simply a SELECT. Nothing below is defence in depth; it is the defence.
 *
 * Hence: both reads and writes are opt-in per class, and a hard block list that
 * no setting can override covers the classes where a mistake is unrecoverable.
 */
final class ClassGuard
{
    /**
     * Never reachable, whatever the settings say.
     *
     * Credentials and session identifiers mean account takeover; the access
     * classes mean privilege escalation; modxmcp's own tables would let a token
     * widen its own scope or erase the record of having done so.
     */
    // Case-insensitive by design. PHP class names are case-insensitive, so
    // "modx\revolution\moduser" resolves to the same class as
    // "MODX\Revolution\modUser"; case-sensitive patterns here would block one
    // spelling and wave the other through.
    private const HARD_BLOCKED = [
        '/^MODX\\\\Revolution\\\\modUser/i',      // modUser, modUserProfile, modUserGroup*, modUserSetting
        '/^MODX\\\\Revolution\\\\modAccess/i',    // every ACL class
        '/^MODX\\\\Revolution\\\\modSession$/i',
        '/^MODX\\\\Revolution\\\\modActiveUser$/i',
        '/^MODXMCP\\\\Model\\\\/i',               // our own tokens and audit trail

        // Settings, both directions. Writing them lets a token rewrite
        // modxmcp.read_class_allowlist / write_class_allowlist and grant itself
        // everything this guard is here to withhold, which is the same
        // self-widening the modxmcp tables are blocked to prevent. Reading them
        // is no safer: the secret lives in the generic `value` column while the
        // secret-ness lives in `key`, so field-name redaction cannot see it and
        // SMTP passwords and API keys would come back in plain text.
        '/^MODX\\\\Revolution\\\\modSystemSetting$/i',
        '/^MODX\\\\Revolution\\\\modContextSetting$/i',
        '/^MODX\\\\Revolution\\\\modDashboardWidget$/i',
    ];

    /**
     * Readable generically, but never writable generically.
     *
     * These all have dedicated processor-backed tools. Reaching them through
     * xPDO::save() would bypass the processor's own checkPermissions() and every
     * lifecycle event, which is the exact failure this extra exists to prevent.
     * For snippets and plugins it is worse than inconsistent: their body is
     * executable PHP, so a generic write is remote code execution that never
     * passes a permission check.
     *
     * The tool descriptions already say "never use this for resources or
     * elements". Prose is not an access control.
     */
    private const WRITE_BLOCKED = [
        '/^MODX\\\\Revolution\\\\modResource$/i',
        '/^MODX\\\\Revolution\\\\modSnippet$/i',
        '/^MODX\\\\Revolution\\\\modPlugin$/i',
        '/^MODX\\\\Revolution\\\\modChunk$/i',
        '/^MODX\\\\Revolution\\\\modTemplate$/i',
        '/^MODX\\\\Revolution\\\\modTemplateVar$/i',
    ];

    /**
     * Base types whose descendants are equally write-blocked.
     *
     * The regex list above matches names, and names only describe the classes
     * somebody thought to write down. Every list here was anchored with $, so
     * modResource was blocked and modDocument was not, and modDocument is what
     * essentially every real MODX page is: the guard blocked the one type
     * content almost never uses and waved through the one it always uses. The
     * same held for modWebLink, modSymLink, modStaticResource and every
     * container class an extra defines, e.g. Collections.
     *
     * Ancestry is the property that actually matters, because it is what decides
     * whether a dedicated processor-backed tool exists for the class. Testing it
     * cannot be outrun by naming a subclass.
     */
    private const WRITE_BLOCKED_DESCENDANTS_OF = [
        \MODX\Revolution\modResource::class,
        \MODX\Revolution\modElement::class,
    ];

    /**
     * Field names masked in read output even for permitted classes.
     *
     * An allowlisted class can still carry a secret in one column, and the
     * admin who allowed the class was thinking about the other nineteen.
     */
    private const SENSITIVE_FIELDS = [
        '/pass(word|wd)?$/i',
        '/secret/i',
        '/token/i',
        '/hash/i',
        '/salt/i',
        '/(api|private|secret|access)_?key/i',
        '/credential/i',
        '/cachepwd/i',
    ];

    public const REDACTED = '[redacted by modxmcp]';

    private modX $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public function isHardBlocked(string $class): bool
    {
        foreach (self::HARD_BLOCKED as $pattern) {
            if (preg_match($pattern, $class)) {
                return true;
            }
        }
        return false;
    }

    public function canRead(string $class): bool
    {
        return !$this->isHardBlocked($class)
            && $this->inList($class, 'modxmcp.read_class_allowlist');
    }

    public function canWrite(string $class): bool
    {
        return !$this->isHardBlocked($class)
            && !$this->isWriteBlocked($class)
            && $this->inList($class, 'modxmcp.write_class_allowlist');
    }

    public function isWriteBlocked(string $class): bool
    {
        foreach (self::WRITE_BLOCKED as $pattern) {
            if (preg_match($pattern, $class)) {
                return true;
            }
        }

        return $this->descendsFromWriteBlocked($class);
    }

    /**
     * Does this class inherit from a type that has a dedicated tool?
     *
     * Two lookups because neither alone is sufficient. is_a() with the
     * string form covers anything the autoloader can reach, which is every core
     * class. xPDO::getAncestry() goes through loadClass(), which resolves the
     * classes an extra registers with addPackage and a plain autoloader may not
     * see. A class this guard cannot resolve at all falls through to the
     * allowlist, which is opt-in and therefore closed by default.
     */
    private function descendsFromWriteBlocked(string $class): bool
    {
        foreach (self::WRITE_BLOCKED_DESCENDANTS_OF as $base) {
            if (is_a($class, $base, true)) {
                return true;
            }
        }

        $ancestry = $this->modx->getAncestry($class);
        if (!is_array($ancestry)) {
            return false;
        }

        foreach ($ancestry as $ancestor) {
            foreach (self::WRITE_BLOCKED_DESCENDANTS_OF as $base) {
                if (strcasecmp((string) $ancestor, $base) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Why a class is unavailable, and what would change that.
     *
     * Returned to the caller so a refusal is actionable rather than a dead end:
     * the model can tell the user exactly which setting to edit.
     */
    public function explainDenial(string $class, string $operation): string
    {
        if ($this->isHardBlocked($class)) {
            return "Access to {$class} is permanently blocked by modxmcp. It holds credentials, "
                . 'session data, access-control rules, system settings, or modxmcp\'s own tokens '
                . 'and audit trail. No setting can enable it.';
        }

        if ($operation === 'write' && $this->isWriteBlocked($class)) {
            return "Generic writes to {$class} are permanently blocked. Writing it directly would "
                . 'bypass the MODX processor that enforces permissions and fires the events extras '
                . 'depend on. Use the dedicated tools instead: modxmcp_resource_create / '
                . 'modxmcp_resource_update for resources, modxmcp_element_save for elements. '
                . 'Reading this class generically is still possible if it is allowlisted.';
        }

        $setting = $operation === 'write'
            ? 'modxmcp.write_class_allowlist'
            : 'modxmcp.read_class_allowlist';

        return "{$class} is not in the {$operation} allowlist. Generic object access is opt-in "
            . "per class because xPDO has no permission model of its own. An administrator can "
            . "add it to the {$setting} system setting. Schema inspection "
            . '(modxmcp_schema_list, modxmcp_schema_describe) works without it.';
    }

    /**
     * Mask sensitive values in a row.
     *
     * @param array<string,mixed> $row
     * @return array{row:array<string,mixed>,redacted:string[]}
     */
    public function redact(array $row): array
    {
        $redacted = [];
        foreach ($row as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            foreach (self::SENSITIVE_FIELDS as $pattern) {
                if (preg_match($pattern, (string) $field)) {
                    $row[$field] = self::REDACTED;
                    $redacted[]  = (string) $field;
                    break;
                }
            }
        }
        return ['row' => $row, 'redacted' => $redacted];
    }

    /**
     * Match a class against a comma or whitespace separated setting.
     *
     * Entries may be exact class names or end in * as a namespace prefix, so a
     * site can allow one extra's model without listing each class.
     */
    private function inList(string $class, string $settingKey): bool
    {
        $raw = trim((string) $this->modx->getOption($settingKey, null, ''));
        if ($raw === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if ($entry === '*') {
                // Still subject to both the hard block list and, for writes, the
                // write block list and its descendants: this returns "allowlisted",
                // not "permitted". canRead()/canWrite() apply the blocks after it.
                //
                // Spelled out because the earlier wording mentioned only the hard
                // blocks, which read as though '*' opened up resources and
                // elements to generic xPDO writes. It never should, and since the
                // descendant check landed it demonstrably does not.
                return true;
            }
            // Matching is case-insensitive throughout, for the same reason the
            // hard block list is: an allowlist that misses a spelling the class
            // loader accepts is not an allowlist.
            if (substr($entry, -1) === '*') {
                $prefix = substr($entry, 0, -1);
                if ($prefix !== '' && strncasecmp($class, $prefix, strlen($prefix)) === 0) {
                    return true;
                }
                continue;
            }
            if (strcasecmp($entry, $class) === 0) {
                return true;
            }
        }

        return false;
    }
}
