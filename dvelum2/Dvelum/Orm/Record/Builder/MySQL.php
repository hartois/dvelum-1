<?php
/**
 *  DVelum project http://code.google.com/p/dvelum/ , https://github.com/k-samuel/dvelum , http://dvelum.net
 *  Copyright (C) 2011-2016  Kirill A Egorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace Dvelum\Orm\Record\Builder;

use Dvelum\Config as Cfg;
use Dvelum\Orm;
use Dvelum\Orm\Exception;
use Dvelum\Orm\Record\Config;
use Dvelum\Orm\Record\Builder;
use Dvelum\Lang;


/**
 * Advanced MySQL Builder
 * @package Dvelum\Orm\Record\Builder
 */
class MySQL extends AbstractAdapter
{
    /**
     * Check if DB table has correct structure
     * @return bool
     */
    public function validate() : bool
    {
        if(!parent::validate()){
            return false;
        }

        $engineUpdate = $this->prepareEngineUpdate();

        if(!empty($engineUpdate))
            return false;
        else
            return true;
    }

    /**
     * Prepare DB engine update SQL
     * @return string|null
     */
    public function prepareEngineUpdate() : ?string
    {
        $config = $this->objectConfig->__toArray();
        $conf = $this->db->fetchRow('SHOW TABLE STATUS WHERE `name` = "' . $this->model->table() . '"');

        if(!$conf || !isset($conf['Engine']))
            return null;

        if(strtolower($conf['Engine']) === strtolower($this->objectConfig->get('engine')))
            return null;

        return $this->changeTableEngine($this->objectConfig->get('engine') , true);
    }

    /**
     * Tells whether object can be converted to new engine type
     *
     * @param string $newEngineType
     * @throws \Exception
     * @return mixed - true for success or array with restricted indexes and fields
     */
    public function checkEngineCompatibility($newEngineType)
    {
        $restrictedIndexes = array();
        $restrictedFields = array();

        $indexes = $this->objectConfig->getIndexesConfig();
        $fields = $this->objectConfig->getFieldsConfig();

        switch(strtolower($newEngineType))
        {
            case 'myisam' :
                break;
            case 'memory' :

                foreach($fields as $k => $v)
                {
                    $type = $v['db_type'];

                    if(in_array($type , Builder::$textTypes , true) || in_array($type , Builder::$blobTypes , true))
                        $restrictedFields[] = $k;
                }

                foreach($indexes as $k => $v)
                    if(isset($v['fulltext']) && $v['fulltext'])
                        $restrictedIndexes[] = $k;

                break;
            case 'innodb' :

                foreach($indexes as $k => $v)
                    if(isset($v['fulltext']) && $v['fulltext'])
                        $restrictedIndexes[] = $k;

                break;

            default :
                throw new \Exception('Unknown db engine type');
                break;
        }

        if(! empty($restrictedFields) || ! empty($restrictedIndexes))
            return array(
                'indexes' => $restrictedIndexes ,
                'fields' => $restrictedFields
            );
        else
            return true;
    }


    /**
     * Change Db table engine
     * @param string $engine - new engine name
     * @param boolean $returnQuery - optional, return update query
     * @return boolean | string
     * @throws \Exception
     */
    public function changeTableEngine($engine , $returnQuery = false)
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $sql = 'ALTER TABLE `' . $this->model->table() . '` ENGINE = ' . $engine;

        if($returnQuery)
            return $sql;

        try
        {
            $this->db->query($sql);
            $this->logSql($sql);
            return true;
        }
        catch(\Exception $e)
        {
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;
        }
    }

    /**
     * Prepare list of columns to be updated
     * returns [
     *         'name'=>'SomeName',
     *         'action'=>[drop/add/change],
     *         ]
     * @return array
     */
    public function prepareColumnUpdates() : array
    {
        $config = $this->objectConfig->__toArray();
        $updates = [];

        if(! $this->tableExists())
            $fields = [];
        else
            $fields = $this->getExistingColumns();


        /**
         * @var \Zend\Db\Metadata\Object\ColumnObject $column
         */
        $columns = [];
        foreach ($fields as $column){
            $columns[$column->getName()] = $column;
        }

        // except virtual fields
        foreach($config['fields'] as $field=>$cfg){
            if($this->objectConfig->getField($field)->isVirtual()){
                unset($config['fields'][$field]);
            }
        }

        /*
         * Remove deprecated fields
         */
        foreach($columns as $name=>$column)
        {
            if(!isset($config['fields'][$name]))
            {
                $updates[] = array(
                    'name' => $name ,
                    'action' => 'drop' ,
                    'type' => 'field'
                );
            }
        }

        foreach($config['fields'] as $name => $v)
        {
            /*
             * Add new field
             */
            if(!isset($columns[$name]))
            {
                $updates[] = array(
                    'name' => $name ,
                    'action' => 'add'
                );
                continue;
            }

            $column = $columns[$name];

            $dataType = strtolower($column->getDataType());
            /*
             * Field type compare flag
             */
            $typeCmp = false;
            /*
             * Field length compare flag
             */
            $lenCmp = false;
            /*
             * IsNull compare flag
             */
            $nullCmp = false;
            /*
             * Default value compare flag
             */
            $defaultCmp = false;
            /*
             * Unsigned compare flag
             */
            $unsignedCmp = false;
            /**
             * AUTO_INCREMENT compare flag
             *
             * @var bool
             */
            $incrementCmp = false;

            if($v['db_type'] === 'boolean' && $dataType === 'tinyint')
            {
                /*
                 * skip check for booleans
                 */
            }
            else
            {
                if(strtolower($v['db_type']) !== $dataType)
                    $typeCmp = true;

                if(in_array($v['db_type'] , Builder::$floatTypes , true))
                {
                    /*
                     * @note ZF3 has inverted scale and precision values
                     */
                    if((int) $v['db_scale'] != (int) $column->getNumericPrecision() || (int) $v['db_precision'] != (int) $column->getNumericScale())
                        $lenCmp = true;
                }
                elseif(in_array($v['db_type'] , Builder::$numTypes , true) && isset(Orm\Record\Field\Property::$numberLength[$v['db_type']]))
                {
                    $lenCmp = (int) Orm\Record\Field\Property::$numberLength[$v['db_type']] != (int) $column->getNumericPrecision();
                }
                else
                {
                    if(isset($v['db_len']))
                        $lenCmp = (int) $v['db_len'] != (int) $column->getCharacterMaximumLength();
                }

                /*
                  Auto set default '' for NOT NULL string properties
                  if(in_array($v['db_type'] , self::$charTypes , true) && (! isset($v['db_isNull']) || ! $v['db_isNull']) && (! isset($v['db_default']) || $v['db_default'] === false))
                  {
                    $v['db_default'] = '';
                  }
                */

                if(in_array($v['db_type'] , Builder::$textTypes , true))
                {
                    if(isset($v['required']) && $v['required'])
                        $v['db_isNull'] = false;
                    else
                        $v['db_isNull'] = true;
                }

                $nullCmp = (boolean) $v['db_isNull'] !==  $column->isNullable();

                if((!isset($v['db_unsigned']) || !$v['db_unsigned']) && $column->isNumericUnsigned())
                    $unsignedCmp = true;

                if(isset($v['db_unsigned']) && $v['db_unsigned'] && ! $column->isNumericUnsigned())
                    $unsignedCmp = true;
            }

            if(!((boolean) $v['db_isNull']) && ! in_array($v['db_type'] , Builder::$dateTypes , true) && ! in_array($v['db_type'] , Builder::$textTypes , true))
            {
                $columnDefault = $column->getColumnDefault();
                // Zend Send '' as empty default for strings
                if(is_string($columnDefault)){
                    $columnDefault = trim($columnDefault,'\'');
                }
                if((!isset($v['db_default']) || $v['db_default'] === false) && !is_null($columnDefault)){
                    $defaultCmp = true;
                }
                if(isset($v['db_default']))
                {
                    if((is_null($columnDefault) && $v['db_default'] !== false) || (! is_null($columnDefault) && $v['db_default'] === false))
                        $defaultCmp = true;
                    else
                        $defaultCmp = (string) $v['db_default'] != (string) $columnDefault;
                }
            }

            /**
             * @todo migrate identity
             */
            //            if($fields[$name]['IDENTITY'] && $name != $this->objectConfig->getPrimaryKey())
            //                $incrementCmp = true;
            //
            //            if($name == $this->objectConfig->getPrimaryKey() && ! $fields[$name]['IDENTITY'])
            //                $incrementCmp = true;



            /*
             * If not passed at least one comparison then rebuild the the field
             */
            if($typeCmp || $lenCmp || $nullCmp || $defaultCmp || $unsignedCmp || $incrementCmp)
            {
                $updates[] = array(
                    'name' => $name ,
                    'action' => 'change',
                    'info' => [
                        'object' => $this->objectName,
                        'cmp_flags' =>[
                            'type' => (boolean) $typeCmp,
                            'length' => (boolean) $lenCmp,
                            'null' => (boolean) $nullCmp,
                            'default' => (boolean) $defaultCmp,
                            'unsigned' => (boolean) $unsignedCmp,
                            'increment' => (boolean) $incrementCmp
                        ]
                    ]
                );
            }
        }
        return $updates;
    }

    /**
     * Prepare list of indexes to be updated
     * @return array (
     *         'name'=>'indexname',
     *         'action'=>[drop/add],
     *         )
     */
    public function prepareIndexUpdates() : array
    {
        $updates = array();
        /*
         * Get indexes form database table
         */
        $indexes = $this->db->fetchAll('SHOW INDEX FROM `' . $this->model->table() . '`');
        $realIndexes = array();

        if(empty($indexes))
            return array();

        foreach($indexes as $k => $v)
        {

            $isFulltext = (boolean) ($v['Index_type'] === 'FULLTEXT');

            if(!isset($realIndexes[$v['Key_name']]))
                $realIndexes[$v['Key_name']] = array(
                    'columns' => array() ,
                    'fulltext' => $isFulltext ,
                    'unique' => (boolean) (! $v['Non_unique'])
                );

            $realIndexes[$v['Key_name']]['columns'][] = $v['Column_name'];
        }
        /*
         * Get indexes from object config
         */
        $configIndexes = $this->objectConfig->getIndexesConfig();

        /*
         * Get indexes for Foreign Keys
         */
        $foreignKeys = $this->getOrmForeignKeys();
        /*
         * Drop invalid indexes
         */
        foreach($realIndexes as $index => $conf)
            if(!isset($configIndexes[$index]) && ! isset($foreignKeys[$index]))
                $updates[] = array(
                    'name' => $index ,
                    'action' => 'drop'
                );

      /*
       * Compare DB and Config indexes, create if not exist, drop and create if
       * invalid
       */
        if(!empty($configIndexes))
        {
            foreach($configIndexes as $index => $config)
            {
                if(! array_key_exists((string) $index , $realIndexes))
                {
                    $updates[] = array(
                        'name' => $index ,
                        'action' => 'add'
                    );
                }
                else
                {
                    if(!$this->isSameIndexes($config , $realIndexes[$index]))
                    {
                        $updates[] = array(
                            'name' => $index ,
                            'action' => 'drop'
                        );
                        $updates[] = array(
                            'name' => $index ,
                            'action' => 'add'
                        );
                    }
                }
            }
        }
        return $updates;
    }

    /**
     * Prepare list of Foreign Keys to be updated
     * @param bool $dropOnly
     * @return array
     */
    public function prepareKeysUpdate(bool $dropOnly = false) : array
    {
        $updates = [];
        $curTable = $this->model->table();
        /*
         * Get foreign keys form ORM
         */
        $configForeignKeys = $this->getOrmForeignKeys();
        /*
         * Get foreign keys form database table
         */
        $realKeys = $this->getForeignKeys($this->model->table());
        $realKeysNames = array();
        if(!empty($realKeys))
            $realKeys = \Utils::rekey('CONSTRAINT_NAME' , $realKeys);
        if(!empty($configForeignKeys))
        {
            foreach($configForeignKeys as $keyName => $item)
            {
                $realKeysNames[] = $keyName;
                if(! isset($realKeys[$keyName]) && ! $dropOnly)
                    $updates[] = array(
                        'name' => $keyName ,
                        'action' => 'add' ,
                        'config' => $item
                    );
            }
        }
        if(!empty($realKeys))
            foreach($realKeys as $name => $config)
                if(! in_array($name , $realKeysNames , true))
                    $updates[] = array(
                        'name' => $name ,
                        'action' => 'drop'
                    );
        return $updates;
    }

    /**
     * Get list of foreign keys for DB Table
     * @param string $dbTable
     * @return array
     * @todo refactor into Zend Metadata
     */
    public function getForeignKeys(string $dbTable)
    {
        $dbConfig = $this->db->getConfig();
        $sql = $this->db->select()
            ->from($this->db->quoteIdentifier('information_schema.TABLE_CONSTRAINTS'))
            ->where('`CONSTRAINT_SCHEMA` =?' , $dbConfig['dbname'])
            ->where('`TABLE_SCHEMA` =?' , $dbConfig['dbname'])
            ->where('`TABLE_NAME` =?' , $dbTable)
            ->where('`CONSTRAINT_TYPE` = "FOREIGN KEY"');

        return $this->db->fetchAll($sql);
    }

    /**
     * Compare existed index and its system config
     *
     * @param array $cfg1
     * @param array $cfg2
     * @return boolean
     */
    protected function isSameIndexes(array $cfg1 , array $cfg2)
    {
        $colDiff = array_diff($cfg1['columns'] , $cfg2['columns']);
        $colDiffReverse = array_diff($cfg2['columns'] , $cfg1['columns']);

        if($cfg1['fulltext'] !== $cfg2['fulltext'] || $cfg1['unique'] !== $cfg2['unique'] || ! empty($colDiff) || !empty($colDiffReverse))
            return false;

        return true;
    }

    /**
     * Prepare Add INDEX command
     *
     * @param string $index
     * @param array $config
     * @param boolean $create - optional use create table mode
     * @return string
     */
    protected function prepareIndex($index , array $config , $create = false)
    {
        if(isset($config['primary']) && $config['primary'])
        {
            if(! isset($config['columns'][0]))
                trigger_error('Invalid index config');

            if($create)
                return "\n" . ' PRIMARY KEY (`' . $config['columns'][0] . '`)';
            else
                return "\n" . ' ADD PRIMARY KEY (`' . $config['columns'][0] . '`)';
        }

        $createType = '';
        /*
         * Set key length for text column index
         */
        foreach($config['columns'] as &$col)
        {
            if($this->objectConfig->getField($col)->isText())
                $col = '`' . $col . '`(32)';
            else
                $col = '`' . $col . '`';
        }
        unset($col);

        $str = '`' . $index . '` (' . implode(',' , $config['columns']) . ')';

        if(isset($config['unique']) && $config['unique'])
            $createType = $indexType = 'UNIQUE';
        elseif(isset($config['fulltext']) && $config['fulltext'])
            $createType = $indexType = 'FULLTEXT';
        else
            $indexType = 'INDEX';

        if($create)
            return "\n" . ' ' . $createType . ' KEY ' . $str;
        else
            return "\n" . ' ADD ' . $indexType . ' ' . $str;
    }

    /**
     * Get property SQL query
     * @param string $name
     * @param Orm\Record\Config\Field $field
     * @return string
     */
    protected function getPropertySql(string $name , Orm\Record\Config\Field $field) : string
    {
        $property = new Orm\Record\Field\Property($name);
        $property->setData($field->__toArray());
        return $property->__toSql();
    }

    /**
     * Get SQL for table creation
     * @throws \Exception
     * @return string
     */
    protected function sqlCreate()
    {
        $fields = $this->objectConfig->get('fields');

        $sql = ' CREATE TABLE  `' . $this->model->table() . '` (';

        if(empty($fields))
            throw new \Exception('sqlCreate :: empty properties');
        /*
       * Add columns
       */
        foreach($fields as $k => $v)
            $sql .= $this->getPropertySql($k , $this->objectConfig->getField($k)) . ' ,  ' . "\n";

        $indexes = $this->createIndexes();

        /*
         * Add indexes
         */
        if(!empty($indexes))
            $sql .= ' ' . implode(', ' , $indexes);

        $sql .= "\n" . ') ENGINE=' . $this->objectConfig->get('engine') . '  DEFAULT CHARSET=utf8 ;';

        return $sql;
    }

    /**
     * Build indexes for "create" query
     * @return array - sql parts
     */
    protected function createIndexes()
    {
        $cmd = [];
        $configIndexes = $this->objectConfig->getIndexesConfig();

        foreach($configIndexes as $index => $config)
            $cmd[] = $this->prepareIndex($index , $config , true);

        return $cmd;
    }


    /**
     * Create / alter db table
     * @param bool $buildForeignKeys
     * @return boolean
     */
    public function build(bool $buildForeignKeys = true) : bool
    {
        $this->errors = array();
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }
        /*
         * Create table if not exists
         */
        if(! $this->tableExists())
        {
            $sql = '';
            try
            {
                $sql = $this->sqlCreate();
                $this->db->query($sql);
                $this->logSql($sql);
                if($buildForeignKeys)
                    return $this->buildForeignKeys();
                else
                    return true;
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br><b>SQL:</b> ' . $sql;
                return false;
            }
        }

        $engineUpdate = $this->prepareEngineUpdate();
        $colUpdates = $this->prepareColumnUpdates();
        $indexUpdates = $this->prepareIndexUpdates();

        /*
         * Remove invalid foreign keys
         */
        if($buildForeignKeys && ! $this->buildForeignKeys(true , false))
            return false;

        /*
         * Update comands
         */
        $cmd = [];

        if(!empty($colUpdates))
        {
            foreach($colUpdates as $info)
            {
                switch($info['action'])
                {
                    case 'drop' :
                        $cmd[] = "\n" . 'DROP `' . $info['name'] . '`';
                        break;
                    case 'add' :
                        $cmd[] = "\n" . 'ADD ' . $this->getPropertySql($info['name'] , $this->objectConfig->getField($info['name']));
                        break;
                    case 'change' :
                        $cmd[] = "\n" . 'CHANGE `' . $info['name'] . '`  ' . $this->getPropertySql($info['name'] , $this->objectConfig->getField($info['name']));
                        break;
                }
            }
        }

        if(!empty($indexUpdates))
        {
            $indexConfig = $this->objectConfig->getIndexesConfig();

            foreach($indexUpdates as $info)
            {
                switch($info['action'])
                {
                    case 'drop' :
                        if($info['name'] == 'PRIMARY')
                            $cmd[] = "\n" . 'DROP PRIMARY KEY';
                        else
                            $cmd[] = "\n" . 'DROP INDEX `' . $info['name'] . '`';
                        break;
                    case 'add' :
                        $cmd[] = $this->prepareIndex($info['name'] , $indexConfig[$info['name']]);
                        break;
                }
            }
        }

        if(!empty($engineUpdate))
        {
            try
            {
                $this->db->query($engineUpdate);
                $this->logSql($engineUpdate);
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $engineUpdate;
            }
        }

        if(!empty($cmd))
        {
            $dbCfg = $this->db->getConfig();
            try
            {
                $sql = 'ALTER TABLE `' . $dbCfg['dbname'] . '`.`' . $this->model->table() . '` ' . implode(',' , $cmd) . ';';
                $this->db->query($sql);
                $this->logSql($sql);
                if($buildForeignKeys)
                    return $this->buildForeignKeys(false , true);
                else
                    return true;
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
                return false;
            }
        }

        $ralationsUpdate = $this->getRelationUpdates();
        if(!empty($ralationsUpdate)){
            try{
                $this->updateRelations($ralationsUpdate);
            }catch (\Exception $e){
                $this->errors[] = $e->getMessage();
                return false;
            }
        }

        if(empty($this->errors))
            return true;
        else
            return true;
    }

    /**
     * Build Foreign Keys
     * @param bool $remove - remove keys
     * @param bool $create - create keys
     * @return boolean
     */
    public function buildForeignKeys($remove = true , $create = true) : bool
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $cmd = array();

        if($this->useForeignKeys)
            $keysUpdates = $this->prepareKeysUpdate();
        else
            $keysUpdates = $this->prepareKeysUpdate(true);

        if(!empty($keysUpdates))
        {
            foreach($keysUpdates as $info)
            {
                switch($info['action'])
                {
                    case 'drop' :
                        if($remove)
                            $cmd[] = "\n" . 'DROP FOREIGN KEY `' . $info['name'] . '`';
                        break;
                    case 'add' :
                        if($create)
                            $cmd[] = 'ADD CONSTRAINT `' . $info['name'] . '`
        						FOREIGN KEY (`' . $info['config']['curField'] . '`)
    				      		REFERENCES `' . $info['config']['toDb'] . '`.`' . $info['config']['toTable'] . '` (`' . $info['config']['toField'] . '`)
    				      		ON UPDATE ' . $info['config']['onUpdate'] . '
    				      		ON DELETE ' . $info['config']['onDelete'];
                        break;
                }
            }
        }

        if(!empty($cmd))
        {
            $dbCfg = $this->db->getConfig();
            try
            {
                $sql = 'ALTER TABLE `' . $dbCfg['dbname'] . '`.`' . $this->model->table() . '` ' . implode(',' , $cmd) . ';';
                $this->db->query($sql);
                $this->logSql($sql);
                return true;
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
                return false;
            }
        }

        return true;
    }

    /**
     * Create Orm\Record`s for relations
     * @throws Exception
     * @param array $list
     * @return bool
     */
    protected function updateRelations(array $list) : bool
    {
        $lang = Lang::lang();
        $usePrefix = true;
        $connection = $this->objectConfig->get('connection');

        $db = $this->model->getDbConnection();
        $tablePrefix = $this->model->getDbPrefix();

        $configDir  = Cfg::storage()->getWrite() . $this->configPath;
        $fieldList = Cfg::storage()->get('objects/relations/fields.php');
        $indexesList = Cfg::storage()->get('objects/relations/indexes.php');

        if(empty($fieldList))
            throw new Exception('Cannot get relation fields: ' . 'objects/relations/fields.php');

        if(empty($indexesList))
            throw new Exception('Cannot get relation indexes: ' . 'objects/relations/indexes.php');

        $fieldList= $fieldList->__toArray();
        $indexesList = $indexesList->__toArray();

        $fieldList['source_id']['link_config']['object'] = $this->objectName;

        foreach($list as $fieldName=>$info)
        {
            $newObjectName = $info['name'];
            $tableName = $newObjectName;

            $linkedObject = $this->objectConfig->getField($fieldName)->getLinkedObject();

            $fieldList['target_id']['link_config']['object'] = $linkedObject;

            $objectData = [
                'parent_object' => $this->objectName,
                'connection'=>$connection,
                'use_db_prefix'=>$usePrefix,
                'disable_keys' => false,
                'locked' => false,
                'readonly' => false,
                'primary_key' => 'id',
                'table' => $newObjectName,
                'engine' => 'InnoDB',
                'rev_control' => false,
                'link_title' => 'id',
                'save_history' => false,
                'system' => true,
                'fields' => $fieldList,
                'indexes' => $indexesList,
            ];

            $tables = $db->listTables();

            if($usePrefix){
                $tableName = $tablePrefix . $tableName;
            }

            if(in_array($tableName, $tables ,true)){
                $this->errors[] = $lang->get('INVALID_VALUE').' Table Name: '.$tableName .' '.$lang->get('SB_UNIQUE');
                return false;
            }

            if(file_exists($configDir . strtolower($newObjectName).'.php')){
                $this->errors[] = $lang->get('INVALID_VALUE').' Object Name: '.$newObjectName .' '.$lang->get('SB_UNIQUE');
                return false;
            }

            if(!is_dir($configDir) && !@mkdir($configDir, 0755, true)){
                $this->errors[] = $lang->get('CANT_WRITE_FS').' '.$configDir;
                return false;
            }

            /*
             * Write object config
             */
            $newConfig = Cfg\Factory::create($objectData, $configDir. $newObjectName . '.php');

            if(!$newConfig || Cfg::storage()->save($newConfig)){
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $configDir . $newObjectName . '.php';
                return false;
            }

            $cfg = Config::factory($newObjectName);
            $cfg->setObjectTitle($lang->get('RELATIONSHIP_MANY_TO_MANY').' '.$this->objectName.' & '.$linkedObject);

            if(!Cfg::storage()->save($cfg)){
                $this->errors[] = $lang->get('CANT_WRITE_FS');
                return false;
            }

            /*
             * Build database
            */
            return $this->build(true);
        }
    }

    /**
     * Rename database table
     *
     * @param string $newName - new table name (without prefix)
     * @return boolean
     * @throws Exception
     */
    public function renameTable($newName)
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $sql = 'RENAME TABLE `' . $this->model->table() . '` TO `' . $this->model->getDbPrefix() . $newName . '` ;';

        try
        {
            $this->db->query($sql);
            $this->logSql($sql);
            $this->objectConfig->getConfig()->set('table' , $newName);
            $this->model->refreshTableInfo();
            return true;
        }
        catch(Exception $e)
        {
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;

        }
    }
}