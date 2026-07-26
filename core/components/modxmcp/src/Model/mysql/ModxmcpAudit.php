<?php
namespace MODXMCP\Model\mysql;

use xPDO\xPDO;

class ModxmcpAudit extends \MODXMCP\Model\ModxmcpAudit
{

    public static $metaMap = array (
        'package' => 'MODXMCP\\Model',
        'version' => '3.0',
        'table' => 'modxmcp_audit',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'token_id' => 0,
            'user_id' => 0,
            'ip' => NULL,
            'rpc_method' => '',
            'tool' => NULL,
            'arguments' => NULL,
            'success' => 0,
            'error_code' => NULL,
            'error_message' => NULL,
            'duration_ms' => 0,
            'createdon' => NULL,
        ),
        'fieldMeta' => 
        array (
            'token_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'user_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'ip' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '45',
                'phptype' => 'string',
                'null' => true,
            ),
            'rpc_method' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '64',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'index' => 'index',
            ),
            'tool' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '128',
                'phptype' => 'string',
                'null' => true,
                'index' => 'index',
            ),
            'arguments' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'string',
                'null' => true,
            ),
            'success' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'error_code' => 
            array (
                'dbtype' => 'int',
                'precision' => '11',
                'phptype' => 'integer',
                'null' => true,
            ),
            'error_message' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'duration_ms' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'createdon' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
                'index' => 'index',
            ),
        ),
        'indexes' => 
        array (
            'createdon' => 
            array (
                'alias' => 'createdon',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'createdon' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'token_id' => 
            array (
                'alias' => 'token_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'token_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
        'aggregates' => 
        array (
            'Token' => 
            array (
                'class' => 'MODXMCP\\Model\\ModxmcpToken',
                'local' => 'token_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
