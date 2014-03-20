<?php

/**
 * @category  SchumacherFM
 * @package   SchumacherFM_FastIndexer
 * @copyright Copyright (c) http://www.schumacher.fm
 * @license   private!
 * @author    Cyrill at Schumacher dot fm @SchumacherFM
 */
abstract class SchumacherFM_FastIndexer_Model_AbstractTable
{
    const FINDEX_TBL_PREFIX = 'afstidex_';

    const DISABLE_CHECKDDLTRANSACTION = '/*disable _checkDdlTransaction*/ ';

    /**
     * @var Mage_Core_Model_Resource
     */
    protected $_resource = null;

    /**
     * @var Varien_Db_Adapter_Pdo_Mysql
     */
    protected $_connection = null;

    protected $_isEchoOn = false;
    protected $_dbName = null;

    /**
     * @param bool $quote
     *
     * @return string
     */
    protected function _getDbName($quote = true)
    {
        if (null === $this->_dbName) {
            $this->_dbName = trim(Mage::getStoreConfig('system/fastindexer/dbName'));
        }
        if (true === $quote) {
            return $this->_quote($this->_dbName);
        }
        return $this->_dbName;
    }

    /**
     * @return Varien_Db_Adapter_Pdo_Mysql
     */
    protected function _getConnection()
    {
        if ($this->_connection === null) {
            $this->_connection = $this->_getResource()->getConnection(Mage_Core_Model_Resource::DEFAULT_WRITE_RESOURCE);
        }
        return $this->_connection;
    }

    /**
     * @param Mage_Core_Model_Resource $resource
     *
     * @return $this
     */
    protected function _setResource(Mage_Core_Model_Resource $resource)
    {
        $this->_resource = $resource;
        return $this;
    }

    /**
     * @return Mage_Core_Model_Resource
     */
    protected function _getResource()
    {
        return $this->_resource;
    }

    /**
     * exception 'PDOException' with message 'SQLSTATE[HY000]: General error: 1005 Can't create table 'dbname.afstidex_catalog_product_flat_5' (errno: 121)'
     * @todo bug: before the temp flat table will be created change the original index names
     *       use event: catalog_product_flat_prepare_indexes this applies only for products ... not for categories ...
     *       have to find another solution
     *
     * restores the original name of the foreign key
     * FIRST drop the original table AND THEN rename the indexes
     *
     * @param string $_originalTableName
     *
     * @return $this
     */
    protected function _restoreTableKeys($_originalTableName)
    {
        $_originalFks = $this->_getConnection()->getForeignKeys($_originalTableName);

        if (count($_originalFks) > 0) {
            // because key name contains: FINDEX_TBL_PREFIX
            // drop and create of a FK only possible in ONE statement = RENAME
            $sqlFk = array();
            foreach ($_originalFks as $_fk) {
                if ($this->_isEchoOn === true) {
                    echo 'Drop FK: ' . $_originalTableName . ' -> ' . $_fk['FK_NAME'] . PHP_EOL;
                }
                $sqlFk[] = 'DROP FOREIGN KEY ' . $this->_quote($_fk['FK_NAME']);

                $originalFkName = $this->_removeTablePrefix($_fk['FK_NAME']);
                if ($this->_isEchoOn === true) {
                    echo 'Add FK: ' . $_originalTableName . ' -> ' . $originalFkName . PHP_EOL;
                }

                $query = sprintf('ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
                    $this->_quote($originalFkName),
                    $this->_quote($_fk['COLUMN_NAME']),
                    $this->_quote($_fk['REF_TABLE_NAME']),
                    $this->_quote($_fk['REF_COLUMN_NAME'])
                );

                if (!empty($_fk['ON_DELETE'])) {
                    $query .= ' ON DELETE ' . strtoupper($_fk['ON_DELETE']);
                }
                if (!empty($_fk['ON_UPDATE'])) {
                    $query .= ' ON UPDATE ' . strtoupper($_fk['ON_UPDATE']);
                }

                $sqlFk[] = $query;
            }
            $sql = '/*disable*/ ALTER TABLE ' . $this->_getTableName($_originalTableName) . ' ' . implode(',', $sqlFk);
            $this->_rawQuery($sql);
        }

        $_originalIndexList = $this->_getIndexList($_originalTableName);

        if (count($_originalIndexList) > 0) {
            // drop and create of an index only possible in ONE statement = RENAME
            $sqlIndex = array();
            foreach ($_originalIndexList as $_key) {
                if ($this->_isEchoOn === true) {
                    echo 'Drop IDX: ' . $_originalTableName . ' -> ' . $_key['KEY_NAME'] . PHP_EOL;
                }
                $sqlIndex[] = 'DROP INDEX ' . $this->_quote($_key['KEY_NAME']);

                $originalIdxName = $this->_removeTablePrefix($_key['KEY_NAME']);
                if ($this->_isEchoOn === true) {
                    echo 'Add IDX: ' . $_originalTableName . ' -> ' . $originalIdxName . PHP_EOL;
                }

                switch (strtolower($_key['INDEX_TYPE'])) {
                    case Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE:
                        $condition = 'UNIQUE ' . $this->_quote($originalIdxName);
                        break;
                    case Varien_Db_Adapter_Interface::INDEX_TYPE_FULLTEXT:
                        $condition = 'FULLTEXT ' . $this->_quote($originalIdxName);
                        break;
                    default:
                        $condition = 'INDEX ' . $this->_quote($originalIdxName);
                        break;
                }

                foreach ($_key['COLUMNS_LIST'] as $k => $v) {
                    $_key['COLUMNS_LIST'][$k] = $this->_quote($v);
                }
                $sqlIndex[] = sprintf('ADD %s (%s)', $condition, implode(',', $_key['COLUMNS_LIST']));
            }
            $sql = '/*disable*/ ALTER TABLE ' . $this->_quote($_originalTableName) . ' ' . implode(',', $sqlIndex);
            $this->_rawQuery($sql);
        }

        return $this;
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    protected function _getIndexList($tableName)
    {
        $index = $this->_getConnection()->getIndexList($tableName);
        if (isset($index['PRIMARY'])) {
            unset($index['PRIMARY']);
        }
        return $index;
    }

    /**
     * returns all non PRI columns
     *
     * @param $tableName
     *
     * @return array
     */
    protected function _getColumnsFromTable($tableName)
    {
        /** @var Varien_Db_Statement_Pdo_Mysql $stmt */
        $stmt          = $this->_getConnection()->query('SHOW COLUMNS FROM ' . $this->_getTableName($tableName));
        $columnsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns       = array();
        foreach ($columnsResult as $col) {
            if ($col['Key'] !== 'PRI') {
                $columns[] = '`' . $col['Field'] . '`';
            }
        }
        return $columns;
    }

    /**
     * @param $tableName
     *
     * @return int
     */
    protected function _getTableCount($tableName)
    {
        /** @var Varien_Db_Statement_Pdo_Mysql $stmt */
        $stmt    = $this->_getConnection()->query('SELECT COUNT(*) AS counted FROM ' . $this->_getTableName($tableName));
        $counter = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return isset($counter[0]) ? (int)$counter[0]['counted'] : 0;
    }

    /**
     * @param string $tableName
     *
     * @return bool
     */
    protected function _dropTable($tableName)
    {
        $this->_rawQuery('DROP TABLE IF EXISTS ' . $this->_getTableName($tableName));
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    protected function _getTableName($tableName)
    {
        return $this->_quote($tableName);
    }

    /**
     * @param string $sql
     *
     * @return Zend_Db_Statement_Interface
     */
    protected function _rawQuery($sql)
    {
        Mage::log($sql, null, 'findexer.log');
        return $this->_getConnection()->raw_query($sql);
    }

    /**
     * index names are always upper case
     *
     * @param $string
     *
     * @return mixed
     */
    protected function _removeTablePrefix($string)
    {
        return str_replace(strtoupper(self::FINDEX_TBL_PREFIX), '', $string);
    }

    /**
     * @param string $string
     *
     * @return string
     */
    protected function _quote($string)
    {
        return $this->_getConnection()->quoteIdentifier($string);
    }
}