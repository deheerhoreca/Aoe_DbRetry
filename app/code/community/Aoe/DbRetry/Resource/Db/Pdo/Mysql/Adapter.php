<?php

class Aoe_DbRetry_Resource_Db_Pdo_Mysql_Adapter extends Magento_Db_Adapter_Pdo_Mysql
{
    protected $retryOnMessages = [
        'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query',
        'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
        'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
    ];

    /**
     * Special handling for PDO query(): retries a statement that hit a lost connection, a lock wait timeout or a
     * deadlock, but only outside a transaction. Inside one the exception is thrown (see below).
     * All bind parameter names must begin with ':'.
     *
     * @param string|Zend_Db_Select $sql  The SQL statement with placeholders.
     * @param mixed                 $bind An array of data or data itself to bind to the placeholders.
     *
     * @return Zend_Db_Statement_Pdo
     * @throws Zend_Db_Adapter_Exception To re-throw PDOException.
     */
    public function query($sql, $bind = [])
    {
        // Inside a transaction a retry of this one statement is wrong. InnoDB rolls the whole transaction back on a
        // deadlock, and a lost connection ends it, so the retried statement (and every statement after it) would
        // run outside the transaction: committed on its own, without the writes before it. Varien's own query()
        // rolls the transaction back and throws, so whoever started the transaction can run it again from the start.
        if ($this->_transactionLevel > 0) {
            try {
                return parent::query($sql, $bind);
            } catch (Exception $e) {
                // Varien forgets the transaction after a deadlock or a lock wait timeout, but not after a lost
                // connection. That ended the transaction too: forget it here, and connect again on the next query.
                // Otherwise the next beginTransaction() sends no BEGIN and its statements run outside a transaction.
                if ($this->isLostConnection($e)) {
                    $this->_transactionLevel = 0;
                    $this->closeConnection();
                }
                throw $e;
            }
        }

        $this->_debugTimer();
        $result = null;
        try {
            $this->_checkDdlTransaction($sql);
            $this->_prepareQuery($sql, $bind);

            $maxTries = 1 + (isset($this->_config['retries']) ? min(max(intval($this->_config['retries']), 0), 5) : 5);
            $retryPower = (isset($this->_config['retry_power']) ? min(max(intval($this->_config['retry_power']), 1), 5) : 2);
            $try = 0;
            while ($try < $maxTries) {
                try {
                    $result = Zend_Db_Adapter_Pdo_Mysql::query($sql, $bind);
                    $try = $maxTries;
                } catch (Exception $e) {
                    $try++;
                    Mage::log("Max retry:{$maxTries} retry power:{$retryPower}", Zend_Log::DEBUG);
                    Mage::dispatchEvent('aoe_dbretry_exception', ['try' => $try, 'exception' => $e]);
                    if ($try < $maxTries) {
                        $message = null;
                        if ($e instanceof PDOException || $e instanceof Zend_Db_Adapter_Exception) {
                            $message = $e->getMessage();
                        } elseif ($e->getPrevious() instanceof PDOException || $e->getPrevious() instanceof Zend_Db_Adapter_Exception) {
                            $message = $e->getPrevious()->getMessage();
                        } else {
                            Mage::logException($e);
                            if (!empty($e->getPrevious())) {
                                Mage::logException($e);
                            } else {
                                Mage::logException($e->getPrevious());
                            }
                        }
                        if ($message && in_array($message, $this->retryOnMessages)) {
                            $sleepSeconds = pow($try, $retryPower);
                            Mage::log("Retrying query [retry:{$try} delay:{$sleepSeconds}]: {$message}", Zend_Log::DEBUG);
                            if ($try === 1) {
                                Mage::logException($e);
                            }
                            sleep($sleepSeconds);
                            continue;
                        }
                    }

                    throw $e;
                }
            }
        } catch (Exception $e) {
            $this->_debugStat(self::DEBUG_QUERY, $sql, $bind);
            $this->_debugException($e);
        }
        $this->_debugStat(self::DEBUG_QUERY, $sql, $bind, $result);

        return $result;
    }

    /**
     * Whether the exception, or the one it wraps, says the connection to the server was lost
     * ("2013 Lost connection ..." during a query, or "2006 MySQL server has gone away").
     *
     * @param Exception $e
     *
     * @return bool
     */
    protected function isLostConnection(Exception $e)
    {
        foreach ([$e, $e->getPrevious()] as $exception) {
            if ($exception && preg_match('/General error: (2006|2013) /', $exception->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run RAW Query
     *
     * @param string $sql
     *
     * @return Zend_Db_Statement_Interface
     * @throws PDOException
     */
    public function raw_query($sql)
    {
        try {
            return $this->query($sql);
        } catch (Zend_Db_Statement_Exception $e) {
            // Convert to PDOException to maintain backwards compatibility with usage of MySQL adapter
            $e = $e->getPrevious();
            if (!$e instanceof PDOException) {
                $e = new PDOException($e->getMessage(), $e->getCode());
            }
            throw $e;
        }
    }
}
