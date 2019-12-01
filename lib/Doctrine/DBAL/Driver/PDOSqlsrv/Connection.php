<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\PDOSqlsrv;

use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\DBAL\Driver\PDOStatement;
use function strpos;
use function substr;
use function var_export;

/**
 * Sqlsrv Connection implementation.
 */
class Connection extends PDOConnection
{
    /**
     * {@inheritDoc}
     */
    public function getSequenceNumber(string $name) : string
    {
        $stmt = $this->prepare('SELECT CONVERT(VARCHAR(MAX), current_value) FROM sys.sequences WHERE name = ?');
        $stmt->execute([$name]);

        $sequenceNumber = $stmt->fetchColumn();

        if (! is_string($sequenceNumber)) {
            var_export($sequenceNumber);
            throw new \Exception('@todo');
        }

        return $sequenceNumber;
    }

    /**
     * {@inheritDoc}
     */
    public function quote(string $input) : string
    {
        $val = parent::quote($input);

        // Fix for a driver version terminating all values with null byte
        if (strpos($val, "\0") !== false) {
            $val = substr($val, 0, -1);
        }

        return $val;
    }

    /**
     * {@inheritDoc}
     */
    protected function createStatement(\PDOStatement $stmt) : PDOStatement
    {
        return new Statement($stmt);
    }
}
