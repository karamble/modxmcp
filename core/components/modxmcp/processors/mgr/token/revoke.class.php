<?php

use MODX\Revolution\Processors\Processor;
use MODXMCP\Model\ModxmcpToken;
use MODXMCP\Package;

/**
 * Revoke a token.
 *
 * Deactivates rather than deletes: audit rows reference token_id, and deleting
 * the row would orphan the history of everything that token did. Revocation is
 * immediate either way, since verification requires active = 1.
 */
class ModxmcpTokenRevokeProcessor extends Processor
{
    public $languageTopics = ['modxmcp:default'];
    public $permission     = 'settings';

    public function process()
    {
        Package::load($this->modx);

        $id = (int) $this->getProperty('id', 0);
        /** @var ModxmcpToken|null $token */
        $token = $id > 0 ? $this->modx->getObject(ModxmcpToken::class, $id) : null;
        if (!$token) {
            return $this->failure($this->modx->lexicon('modxmcp.err.token_nf'));
        }

        $token->set('active', 0);
        if (!$token->save()) {
            return $this->failure();
        }

        return $this->success('', ['id' => $id]);
    }
}

return 'ModxmcpTokenRevokeProcessor';
