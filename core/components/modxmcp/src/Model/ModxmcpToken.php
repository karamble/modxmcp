<?php
namespace MODXMCP\Model;

use xPDO\xPDO;

/**
 * Class ModxmcpToken
 *
 * @property string $name
 * @property string $token_prefix
 * @property string $token_hash
 * @property integer $user_id
 * @property array $scopes
 * @property boolean $active
 * @property string $expires_at
 * @property string $ip_allowlist
 * @property string $last_used_at
 * @property string $last_used_ip
 * @property string $createdon
 * @property integer $createdby
 *
 * @package MODXMCP\Model
 */
class ModxmcpToken extends \xPDO\Om\xPDOSimpleObject
{
}
