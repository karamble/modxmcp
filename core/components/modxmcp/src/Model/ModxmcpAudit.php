<?php
namespace MODXMCP\Model;

use xPDO\xPDO;

/**
 * Class ModxmcpAudit
 *
 * @property integer $token_id
 * @property integer $user_id
 * @property string $ip
 * @property string $rpc_method
 * @property string $tool
 * @property string $arguments
 * @property boolean $success
 * @property integer $error_code
 * @property string $error_message
 * @property integer $duration_ms
 * @property string $createdon
 *
 * @package MODXMCP\Model
 */
class ModxmcpAudit extends \xPDO\Om\xPDOSimpleObject
{
}
