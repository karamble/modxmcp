<?php

use MODX\Revolution\modX;
use MODX\Revolution\Processors\Processor;
use MODXMCP\Model\ModxmcpAudit;
use MODXMCP\Package;

/**
 * Empty the audit log.
 *
 * Deletes every row, not just what the grid currently shows: a filtered clear
 * would leave an administrator believing the log is empty when it is not. The
 * clear itself is written to the MODX system log, because an audit log that can
 * vanish without leaving any trace of who emptied it is not much of an audit
 * log.
 */
class ModxmcpAuditClearProcessor extends Processor
{
    public $languageTopics = ['modxmcp:default'];
    public $permission     = 'settings';

    public function process()
    {
        Package::load($this->modx);

        $table = $this->modx->getTableName(ModxmcpAudit::class);
        $stmt  = $this->modx->prepare("DELETE FROM {$table}");
        if (!$stmt->execute()) {
            return $this->failure();
        }
        $cleared = $stmt->rowCount();

        $this->modx->log(modX::LOG_LEVEL_INFO, sprintf(
            'modxmcp: audit log cleared (%d rows) by manager user "%s"',
            $cleared,
            $this->modx->user ? $this->modx->user->get('username') : ''
        ));

        return $this->success('', ['cleared' => $cleared]);
    }
}

return 'ModxmcpAuditClearProcessor';
