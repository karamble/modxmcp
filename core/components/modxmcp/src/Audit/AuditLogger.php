<?php

namespace MODXMCP\Audit;

use MODX\Revolution\modX;
use MODXMCP\Model\ModxmcpAudit;
use MODXMCP\Package;

/**
 * Writes one row per MCP call.
 *
 * Records rejected calls as well as successful ones: a run of failures is the
 * signal that matters most, and an audit log that only contains successes
 * cannot answer the question it exists for.
 *
 * Writes are best effort. Losing an audit row must never turn a working request
 * into a failed one, so every path here swallows its own errors and falls back
 * to the MODX error log.
 */
final class AuditLogger
{
    private bool $logArguments;

    /**
     * @param bool $logArguments Tool arguments can contain page content and
     *        other caller-supplied data, so this is off unless enabled.
     */
    public function __construct(bool $logArguments = false)
    {
        $this->logArguments = $logArguments;
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function record(modX $modx, array $entry): void
    {
        try {
            Package::load($modx);

            /** @var ModxmcpAudit $row */
            $row = $modx->newObject(ModxmcpAudit::class);
            $row->fromArray([
                'token_id'      => (int) ($entry['token_id'] ?? 0),
                'user_id'       => (int) ($entry['user_id'] ?? 0),
                'ip'            => $entry['ip'] ?? null,
                'rpc_method'    => (string) ($entry['rpc_method'] ?? ''),
                'tool'          => $entry['tool'] ?? null,
                'arguments'     => $this->encodeArguments($entry['arguments'] ?? null),
                'success'       => !empty($entry['success']) ? 1 : 0,
                'error_code'    => isset($entry['error_code']) ? (int) $entry['error_code'] : null,
                'error_message' => $entry['error_message'] ?? null,
                'duration_ms'   => (int) ($entry['duration_ms'] ?? 0),
                'createdon'     => date('Y-m-d H:i:s'),
            ]);
            $row->save();

            $this->maybePrune($modx);
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR, 'modxmcp: could not write audit row: ' . $e->getMessage());
        }
    }

    /**
     * Enforce the retention setting occasionally, rather than never.
     *
     * modxmcp.audit_retention_days previously configured a policy nothing acted
     * on, so an administrator setting 30 days believed old rows were being
     * removed while the table grew forever. That matters more than it sounds:
     * a row is written before authentication succeeds, so an unauthenticated
     * caller can grow this table on purpose.
     *
     * Sampled rather than run every request because a DELETE across a large
     * table on every call would be a self-inflicted denial of service, and
     * pruning is not urgent enough to be worth it.
     */
    private function maybePrune(modX $modx): void
    {
        $days = (int) $modx->getOption('modxmcp.audit_retention_days', null, 90);
        if ($days <= 0) {
            return;
        }

        try {
            if (random_int(1, 200) !== 1) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $this->prune($modx, $days);
    }

    /**
     * Argument keys whose values are replaced before logging. File content is
     * the reason this exists: with log_arguments on, a single upload would
     * otherwise write up to 16 KB of base64 into every audit row it touches,
     * and the audit log is for "who did what", never for payload storage.
     */
    private const REDACTED_KEYS = ['content_base64'];

    /**
     * @param mixed $arguments
     */
    private function encodeArguments($arguments): ?string
    {
        if (!$this->logArguments || $arguments === null || $arguments === []) {
            return null;
        }
        if (is_array($arguments)) {
            $arguments = $this->redact($arguments);
        }
        $json = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }
        // Bounded so one oversized call cannot bloat the table.
        return strlen($json) > 16000 ? substr($json, 0, 16000) . '...[truncated]' : $json;
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    private function redact(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_string($key) && in_array($key, self::REDACTED_KEYS, true)) {
                $arguments[$key] = is_string($value)
                    ? '[redacted ' . strlen($value) . ' chars]'
                    : '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $arguments[$key] = $this->redact($value);
            }
        }
        return $arguments;
    }

    /**
     * Delete rows older than $days. Zero disables retention.
     */
    public function prune(modX $modx, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        try {
            Package::load($modx);
            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            $table  = $modx->getTableName(ModxmcpAudit::class);
            $stmt   = $modx->prepare("DELETE FROM {$table} WHERE createdon < ?");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR, 'modxmcp: audit prune failed: ' . $e->getMessage());
            return 0;
        }
    }
}
