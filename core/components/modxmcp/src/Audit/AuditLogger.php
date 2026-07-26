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
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR, 'modxmcp: could not write audit row: ' . $e->getMessage());
        }
    }

    /**
     * @param mixed $arguments
     */
    private function encodeArguments($arguments): ?string
    {
        if (!$this->logArguments || $arguments === null || $arguments === []) {
            return null;
        }
        $json = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }
        // Bounded so one oversized call cannot bloat the table.
        return strlen($json) > 16000 ? substr($json, 0, 16000) . '...[truncated]' : $json;
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
