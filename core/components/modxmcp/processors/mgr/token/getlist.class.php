<?php

use MODX\Revolution\Processors\Model\GetListProcessor;
use MODX\Revolution\modUser;
use MODXMCP\Model\ModxmcpToken;

/**
 * Token grid.
 *
 * token_hash is never selected. There is no legitimate reason to move a hash
 * into the Manager UI, and not selecting it means it cannot leak through a
 * grid export or a browser cache.
 */
class ModxmcpTokenGetListProcessor extends GetListProcessor
{
    public $classKey        = ModxmcpToken::class;
    public $languageTopics  = ['modxmcp:default'];
    public $defaultSortField = 'createdon';
    public $defaultSortDirection = 'DESC';
    public $objectType      = 'modxmcp.token';
    public $permission      = 'settings';

    public function prepareQueryBeforeCount(xPDO\Om\xPDOQuery $c)
    {
        $query = $this->getProperty('query');
        if (!empty($query)) {
            $c->where([
                'name:LIKE'          => '%' . $query . '%',
                'OR:token_prefix:LIKE' => '%' . $query . '%',
            ]);
        }
        return $c;
    }

    public function prepareRow(xPDO\Om\xPDOObject $object)
    {
        $row = $object->toArray();
        unset($row['token_hash']);

        $user = $this->modx->getObject(modUser::class, (int) $object->get('user_id'));
        $row['username'] = $user ? $user->get('username') : '(missing user)';

        $scopes = $object->get('scopes');
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true);
        }
        $row['scopes_display'] = is_array($scopes) ? implode(', ', $scopes) : '';

        if (empty($row['last_used_at'])) {
            $row['last_used_at'] = $this->modx->lexicon('modxmcp.token.never');
        }

        return $row;
    }
}

return 'ModxmcpTokenGetListProcessor';
