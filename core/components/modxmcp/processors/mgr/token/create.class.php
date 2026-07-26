<?php

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modUser;
use MODXMCP\Auth\TokenService;
use MODXMCP\Package;

/**
 * Issue a token.
 *
 * Not a GetListProcessor/CreateProcessor pair, because the plaintext exists for
 * exactly one response and must never be reconstructable from stored state.
 * TokenService owns generation and hashing; this only validates input and hands
 * the plaintext back once.
 */
class ModxmcpTokenCreateProcessor extends Processor
{
    public $languageTopics = ['modxmcp:default'];
    public $permission     = 'settings';

    public function process()
    {
        Package::load($this->modx);

        $name   = trim((string) $this->getProperty('name', ''));
        $userId = (int) $this->getProperty('user_id', 0);

        if ($name === '') {
            $this->addFieldError('name', $this->modx->lexicon('modxmcp.err.name_ns'));
        }
        if ($userId <= 0) {
            $this->addFieldError('user_id', $this->modx->lexicon('modxmcp.err.user_ns'));
        } elseif (!$this->modx->getObject(modUser::class, $userId)) {
            $this->addFieldError('user_id', $this->modx->lexicon('modxmcp.err.user_nf'));
        }
        if ($this->hasErrors()) {
            return $this->failure();
        }

        $scopes = $this->getProperty('scopes', '');
        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $scopes = array_values(array_unique(array_map('strval', (array) $scopes)));

        $expires = trim((string) $this->getProperty('expires_at', ''));
        $ipList  = trim((string) $this->getProperty('ip_allowlist', ''));

        try {
            $issued = (new TokenService())->issue(
                $this->modx,
                $name,
                $userId,
                $scopes,
                $expires !== '' ? $expires : null,
                $ipList !== '' ? $ipList : null
            );
        } catch (\Throwable $e) {
            $this->modx->log(MODX\Revolution\modX::LOG_LEVEL_ERROR, 'modxmcp: token issue failed: ' . $e->getMessage());
            return $this->failure($e->getMessage());
        }

        // The only time the plaintext is ever returned.
        return $this->success('', [
            'id'      => $issued['id'],
            'token'   => $issued['token'],
            'message' => $this->modx->lexicon('modxmcp.token.shown_once'),
        ]);
    }
}

return 'ModxmcpTokenCreateProcessor';
