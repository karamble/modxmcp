<?php

use MODX\Revolution\Processors\Model\GetListProcessor;
use MODX\Revolution\modUser;
use MODXMCP\Model\ModxmcpAudit;

/**
 * Audit grid.
 *
 * Defaults to newest first and supports filtering to failures only, because the
 * question this log usually has to answer is "what is being rejected, and from
 * where".
 */
class ModxmcpAuditGetListProcessor extends GetListProcessor
{
    public $classKey             = ModxmcpAudit::class;
    public $languageTopics       = ['modxmcp:default'];
    public $defaultSortField     = 'createdon';
    public $defaultSortDirection = 'DESC';
    public $objectType           = 'modxmcp.audit';
    public $permission           = 'settings';

    public function prepareQueryBeforeCount(xPDO\Om\xPDOQuery $c)
    {
        $query = trim((string) $this->getProperty('query', ''));
        if ($query !== '') {
            $c->where([
                'rpc_method:LIKE'    => '%' . $query . '%',
                'OR:tool:LIKE'       => '%' . $query . '%',
                'OR:ip:LIKE'         => '%' . $query . '%',
                'OR:error_message:LIKE' => '%' . $query . '%',
            ]);
        }

        if ($this->getProperty('failures_only')) {
            $c->where(['success' => 0]);
        }

        $tokenId = (int) $this->getProperty('token_id', 0);
        if ($tokenId > 0) {
            $c->where(['token_id' => $tokenId]);
        }

        return $c;
    }

    public function prepareRow(xPDO\Om\xPDOObject $object)
    {
        $row = $object->toArray();

        $user = $this->modx->getObject(modUser::class, (int) $object->get('user_id'));
        $row['username'] = $user ? $user->get('username') : '';

        $row['result'] = $this->modx->lexicon(
            $object->get('success') ? 'modxmcp.audit.ok' : 'modxmcp.audit.failed'
        );

        // Arguments are only stored when modxmcp.log_arguments is on, and can be
        // large; the grid shows presence, the detail view shows content.
        $row['has_arguments'] = !empty($row['arguments']);
        unset($row['arguments']);

        return $row;
    }
}

return 'ModxmcpAuditGetListProcessor';
