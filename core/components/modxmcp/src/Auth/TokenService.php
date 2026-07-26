<?php

namespace MODXMCP\Auth;

use MODX\Revolution\modX;
use MODXMCP\Model\ModxmcpToken;
use MODXMCP\Package;
use MODXMCP\Protocol\McpException;

/**
 * Issues and verifies API tokens.
 *
 * Token format:  mcp_<prefix>_<secret>
 *
 * The prefix is a non-secret, uniquely indexed lookup key; the whole token is
 * hashed. Password hashes are salted and therefore cannot be queried, so
 * without a prefix every verification would mean loading every token and
 * running a hash comparison against each one.
 */
final class TokenService
{
    private const SCHEME       = 'mcp_';
    private const PREFIX_BYTES = 4;   // 8 hex chars
    private const SECRET_BYTES = 24;  // 48 hex chars

    /**
     * A valid bcrypt hash of a value no caller can supply, used to spend the
     * same time on a miss as on a hit. Without it, response timing reveals
     * whether a given token prefix exists.
     */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ass6a';

    /**
     * Create a token. The plaintext is returned once and is not recoverable.
     *
     * @param string[] $scopes
     * @return array{token:string,id:int}
     */
    public function issue(
        modX $modx,
        string $name,
        int $userId,
        array $scopes,
        ?string $expiresAt = null,
        ?string $ipAllowlist = null
    ): array {
        Package::load($modx);

        $prefix    = bin2hex(random_bytes(self::PREFIX_BYTES));
        $secret    = bin2hex(random_bytes(self::SECRET_BYTES));
        $plaintext = self::SCHEME . $prefix . '_' . $secret;

        /** @var ModxmcpToken $token */
        $token = $modx->newObject(ModxmcpToken::class);
        $token->fromArray([
            'name'         => $name,
            'token_prefix' => $prefix,
            'token_hash'   => password_hash($plaintext, PASSWORD_DEFAULT),
            'user_id'      => $userId,
            'scopes'       => array_values($scopes),
            'active'       => 1,
            'expires_at'   => $expiresAt,
            'ip_allowlist' => $ipAllowlist,
            'createdon'    => date('Y-m-d H:i:s'),
            'createdby'    => $modx->user ? (int) $modx->user->get('id') : 0,
        ]);

        if (!$token->save()) {
            throw McpException::internal('Could not save token');
        }

        return ['token' => $plaintext, 'id' => (int) $token->get('id')];
    }

    /**
     * Verify an Authorization header and return the matching token.
     *
     * Every rejection path is reported as a bare 401. Distinguishing "unknown
     * token" from "expired" or "IP not allowed" would tell an attacker which of
     * their guesses was structurally correct.
     *
     * @throws McpException
     */
    public function verify(modX $modx, ?string $authorizationHeader, string $clientIp): ModxmcpToken
    {
        Package::load($modx);

        $plaintext = $this->extractBearer($authorizationHeader);
        $prefix    = $this->extractPrefix($plaintext);

        /** @var ModxmcpToken|null $token */
        $token = $prefix === null
            ? null
            : $modx->getObject(ModxmcpToken::class, ['token_prefix' => $prefix]);

        // Always run one verification, so a miss costs the same as a hit.
        $hash    = $token ? (string) $token->get('token_hash') : self::DUMMY_HASH;
        $matches = password_verify($plaintext, $hash);

        if (!$token || !$matches) {
            throw McpException::unauthorized();
        }
        if (!$token->get('active')) {
            throw McpException::unauthorized();
        }

        $expires = $token->get('expires_at');
        if (!empty($expires) && strtotime((string) $expires) < time()) {
            throw McpException::unauthorized();
        }

        if (!$this->ipAllowed($token, $clientIp)) {
            throw McpException::unauthorized();
        }

        $this->touch($token, $clientIp);

        return $token;
    }

    /** @return string[] */
    public function scopesOf(ModxmcpToken $token): array
    {
        $scopes = $token->get('scopes');
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true);
        }
        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
    }

    private function extractBearer(?string $header): string
    {
        $header = $header ?? '';
        return (stripos($header, 'Bearer ') === 0) ? trim(substr($header, 7)) : '';
    }

    /** Pull the indexed lookup key out of mcp_<prefix>_<secret>. */
    private function extractPrefix(string $plaintext): ?string
    {
        if (strncmp($plaintext, self::SCHEME, strlen(self::SCHEME)) !== 0) {
            return null;
        }
        $rest  = substr($plaintext, strlen(self::SCHEME));
        $parts = explode('_', $rest, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        // Anything not plain hex cannot be one of ours, and keeping this strict
        // means the value going into the query is always safe.
        return ctype_xdigit($parts[0]) ? $parts[0] : null;
    }

    /**
     * An empty allowlist means "any address". Entries may be plain addresses or
     * CIDR ranges.
     */
    private function ipAllowed(ModxmcpToken $token, string $clientIp): bool
    {
        $raw = trim((string) $token->get('ip_allowlist'));
        if ($raw === '') {
            return true;
        }
        if ($clientIp === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (strpos($entry, '/') === false) {
                if (hash_equals($entry, $clientIp)) {
                    return true;
                }
                continue;
            }
            if ($this->inCidr($clientIp, $entry)) {
                return true;
            }
        }
        return false;
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        if (!isset($ipBin[$bytes], $subnetBin[$bytes])) {
            return false;
        }

        $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * Record use. Best effort: a failed write here must never deny an otherwise
     * valid request.
     */
    private function touch(ModxmcpToken $token, string $clientIp): void
    {
        try {
            $token->set('last_used_at', date('Y-m-d H:i:s'));
            $token->set('last_used_ip', $clientIp !== '' ? $clientIp : null);
            $token->save();
        } catch (\Throwable $e) {
            // deliberately ignored
        }
    }
}
