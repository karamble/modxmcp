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
    private const HARD_BLOCKED = [
        '/^MODX\\\\Revolution\\\\modUser/',      // modUser, modUserProfile, modUserGroup*, modUserSetting
        '/^MODX\\\\Revolution\\\\modAccess/',    // every ACL class
        '/^MODX\\\\Revolution\\\\modSession$/',
        '/^MODX\\\\Revolution\\\\modActiveUser$/',
        '/^MODXMCP\\\\Model\\\\/',               // our own tokens and audit trail
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
            && $this->inList($class, 'modxmcp.write_class_allowlist');
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
                . 'session data, access-control rules, or modxmcp\'s own tokens and audit trail. '
                . 'No setting can enable it.';
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
                // Deliberately still subject to the hard block list above.
                return true;
            }
            if (substr($entry, -1) === '*') {
                $prefix = substr($entry, 0, -1);
                if ($prefix !== '' && strncmp($class, $prefix, strlen($prefix)) === 0) {
                    return true;
                }
                continue;
            }
            if ($entry === $class) {
                return true;
            }
        }

        return false;
    }
}
