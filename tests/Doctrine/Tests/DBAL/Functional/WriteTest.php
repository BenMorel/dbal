<?php

declare(strict_types=1);

namespace Doctrine\Tests\DBAL\Functional;

use DateTime;
use Doctrine\DBAL\Driver\AbstractOracleDriver;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Tests\DbalFunctionalTestCase;
use Doctrine\Tests\TestUtil;
use Throwable;
use function array_filter;
use function strtolower;

class WriteTest extends DbalFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        $table = new Table('write_table');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('test_int', 'integer');
        $table->addColumn('test_string', 'string', [
            'length' => 32,
            'notnull' => false,
        ]);
        $table->setPrimaryKey(['id']);

        $this->connection->getSchemaManager()->dropAndCreateTable($table);

        $this->connection->executeUpdate('DELETE FROM write_table');
    }

    public function testExecuteUpdate() : void
    {
        $sql      = 'INSERT INTO write_table (test_int) VALUES (1)';
        $affected = $this->connection->executeUpdate($sql);

        self::assertEquals(1, $affected, 'executeUpdate() should return the number of affected rows!');
    }

    public function testExecuteUpdateWithTypes() : void
    {
        $sql      = 'INSERT INTO write_table (test_int, test_string) VALUES (?, ?)';
        $affected = $this->connection->executeUpdate(
            $sql,
            [1, 'foo'],
            [ParameterType::INTEGER, ParameterType::STRING]
        );

        self::assertEquals(1, $affected, 'executeUpdate() should return the number of affected rows!');
    }

    public function testPrepareRowCountReturnsAffectedRows() : void
    {
        $sql  = 'INSERT INTO write_table (test_int, test_string) VALUES (?, ?)';
        $stmt = $this->connection->prepare($sql);

        $stmt->bindValue(1, 1);
        $stmt->bindValue(2, 'foo');
        $stmt->execute();

        self::assertEquals(1, $stmt->rowCount());
    }

    public function testPrepareWithPdoTypes() : void
    {
        $sql  = 'INSERT INTO write_table (test_int, test_string) VALUES (?, ?)';
        $stmt = $this->connection->prepare($sql);

        $stmt->bindValue(1, 1, ParameterType::INTEGER);
        $stmt->bindValue(2, 'foo', ParameterType::STRING);
        $stmt->execute();

        self::assertEquals(1, $stmt->rowCount());
    }

    public function testPrepareWithDbalTypes() : void
    {
        $sql  = 'INSERT INTO write_table (test_int, test_string) VALUES (?, ?)';
        $stmt = $this->connection->prepare($sql);

        $stmt->bindValue(1, 1, ParameterType::INTEGER);
        $stmt->bindValue(2, 'foo', ParameterType::STRING);
        $stmt->execute();

        self::assertEquals(1, $stmt->rowCount());
    }

    public function testPrepareWithDbalTypeNames() : void
    {
        $sql  = 'INSERT INTO write_table (test_int, test_string) VALUES (?, ?)';
        $stmt = $this->connection->prepare($sql);

        $stmt->bindValue(1, 1, ParameterType::INTEGER);
        $stmt->bindValue(2, 'foo', ParameterType::STRING);
        $stmt->execute();

        self::assertEquals(1, $stmt->rowCount());
    }

    public function insertRows() : void
    {
        self::assertEquals(1, $this->connection->insert('write_table', ['test_int' => 1, 'test_string' => 'foo']));
        self::assertEquals(1, $this->connection->insert('write_table', ['test_int' => 2, 'test_string' => 'bar']));
    }

    public function testInsert() : void
    {
        $this->insertRows();
    }

    public function testDelete() : void
    {
        $this->insertRows();

        self::assertEquals(1, $this->connection->delete('write_table', ['test_int' => 2]));
        self::assertCount(1, $this->connection->fetchAll('SELECT * FROM write_table'));

        self::assertEquals(1, $this->connection->delete('write_table', ['test_int' => 1]));
        self::assertCount(0, $this->connection->fetchAll('SELECT * FROM write_table'));
    }

    public function testUpdate() : void
    {
        $this->insertRows();

        self::assertEquals(1, $this->connection->update('write_table', ['test_string' => 'bar'], ['test_string' => 'foo']));
        self::assertEquals(2, $this->connection->update('write_table', ['test_string' => 'baz'], ['test_string' => 'bar']));
        self::assertEquals(0, $this->connection->update('write_table', ['test_string' => 'baz'], ['test_string' => 'bar']));
    }

    public function testLastInsertId() : void
    {
        if (! $this->connection->getDatabasePlatform()->supportsIdentityColumns()) {
            $this->markTestSkipped('This test targets platforms that support identity columns.');
        }

        self::assertEquals(1, $this->connection->insert('write_table', ['test_int' => 2, 'test_string' => 'bar']));
        $num = $this->connection->lastInsertId();

        self::assertGreaterThan(0, $num, 'LastInsertId() should be non-negative number.');
    }

    public function testLastInsertIdNotSupported() : void
    {
        if ($this->connection->getDatabasePlatform()->supportsIdentityColumns()) {
            $this->markTestSkipped('This test targets platforms that don\'t support identity columns.');
        }

        self::assertEquals(1, $this->connection->insert('write_table', ['test_int' => 2, 'test_string' => 'bar']));

        self::expectException(DriverException::class);
        $this->connection->lastInsertId();
    }

    public function testLastInsertIdNewConnection() : void
    {
        $connection = TestUtil::getConnection();

        $this->expectException(DriverException::class);
        $connection->lastInsertId();
    }

    public function testGetSequenceNumber() : void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $platform->supportsSequences()) {
            $this->markTestSkipped('This test targets platforms that support sequences.');
        }

        if ($platform instanceof OraclePlatform && $this->connection->getWrappedConnection() instanceof PDOConnection) {
            $this->markTestSkipped('Oracle supports sequences, but PDO OCI driver does not support lastInsertId()');
        }

        $sequence = new Sequence('write_table_id_seq');
        try {
            $this->connection->getSchemaManager()->createSequence($sequence);
        } catch (Throwable $e) {
        }

        $sequences = $this->connection->getSchemaManager()->listSequences();
        self::assertCount(1, array_filter($sequences, static function ($sequence) {
            return strtolower($sequence->getName()) === 'write_table_id_seq';
        }));

        $stmt            = $this->connection->query($this->connection->getDatabasePlatform()->getSequenceNextValSQL('write_table_id_seq'));
        $nextSequenceVal = $stmt->fetchColumn();

        $sequenceNumber = $this->connection->getSequenceNumber('write_table_id_seq');

        self::assertGreaterThan(0, $sequenceNumber);
        self::assertEquals($nextSequenceVal, $sequenceNumber);
    }

    public function testGetSequenceNumberNotSupportedOrSequenceDoesNotExist() : void
    {
        $this->expectException(DriverException::class);
        $this->connection->getSequenceNumber('unknown-sequence');
    }

    /**
     * @group DBAL-445
     */
    public function testInsertWithKeyValueTypes() : void
    {
        $testString = new DateTime('2013-04-14 10:10:10');

        $this->connection->insert(
            'write_table',
            ['test_int' => '30', 'test_string' => $testString],
            ['test_string' => 'datetime', 'test_int' => 'integer']
        );

        $data = $this->connection->fetchColumn('SELECT test_string FROM write_table WHERE test_int = 30');

        self::assertEquals($testString->format($this->connection->getDatabasePlatform()->getDateTimeFormatString()), $data);
    }

    /**
     * @group DBAL-445
     */
    public function testUpdateWithKeyValueTypes() : void
    {
        $testString = new DateTime('2013-04-14 10:10:10');

        $this->connection->insert(
            'write_table',
            ['test_int' => '30', 'test_string' => $testString],
            ['test_string' => 'datetime', 'test_int' => 'integer']
        );

        $testString = new DateTime('2013-04-15 10:10:10');

        $this->connection->update(
            'write_table',
            ['test_string' => $testString],
            ['test_int' => '30'],
            ['test_string' => 'datetime', 'test_int' => 'integer']
        );

        $data = $this->connection->fetchColumn('SELECT test_string FROM write_table WHERE test_int = 30');

        self::assertEquals($testString->format($this->connection->getDatabasePlatform()->getDateTimeFormatString()), $data);
    }

    /**
     * @group DBAL-445
     */
    public function testDeleteWithKeyValueTypes() : void
    {
        $val = new DateTime('2013-04-14 10:10:10');
        $this->connection->insert(
            'write_table',
            ['test_int' => '30', 'test_string' => $val],
            ['test_string' => 'datetime', 'test_int' => 'integer']
        );

        $this->connection->delete('write_table', ['test_int' => 30, 'test_string' => $val], ['test_string' => 'datetime', 'test_int' => 'integer']);

        $data = $this->connection->fetchColumn('SELECT test_string FROM write_table WHERE test_int = 30');

        self::assertFalse($data);
    }

    public function testEmptyIdentityInsert() : void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $platform->supportsIdentityColumns() || $platform->usesSequenceEmulatedIdentityColumns()) {
            $this->markTestSkipped(
                'Test only works on platforms with native support for identity columns.'
            );
        }

        $table = new Table('test_empty_identity');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        try {
            $this->connection->getSchemaManager()->dropTable($table->getQuotedName($platform));
        } catch (Throwable $e) {
        }

        foreach ($platform->getCreateTableSQL($table) as $sql) {
            $this->connection->exec($sql);
        }

        $sql = $platform->getEmptyIdentityInsertSQL('test_empty_identity', 'id');

        $this->connection->exec($sql);
        $firstId = $this->connection->lastInsertId();

        $this->connection->exec($sql);
        $secondId = $this->connection->lastInsertId();

        self::assertGreaterThan($firstId, $secondId);
    }

    public function testEmptyIdentityInsertEmulated() : void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $platform->usesSequenceEmulatedIdentityColumns()) {
            $this->markTestSkipped(
                'Test only works on platforms with emulated identity columns.'
            );
        }

        if ($this->connection->getDriver() instanceof AbstractOracleDriver) {
            $this->markTestSkipped('The driver does not support lastInsertId()');
        }

        $table = new Table('test_empty_identity');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        try {
            $this->connection->getSchemaManager()->dropTable($table->getQuotedName($platform));
        } catch (Throwable $e) {
        }

        foreach ($platform->getCreateTableSQL($table) as $sql) {
            $this->connection->exec($sql);
        }

        $seqName = $platform->getIdentitySequenceName('test_empty_identity', 'id');

        $sql = $platform->getEmptyIdentityInsertSQL('test_empty_identity', 'id');

        $this->connection->exec($sql);
        $firstId = $this->connection->getSequenceNumber($seqName);

        $this->connection->exec($sql);
        $secondId = $this->connection->getSequenceNumber($seqName);

        self::assertGreaterThan($firstId, $secondId);
    }

    /**
     * @group DBAL-2688
     */
    public function testUpdateWhereIsNull() : void
    {
        $this->connection->insert(
            'write_table',
            ['test_int' => '30', 'test_string' => null],
            ['test_string' => 'string', 'test_int' => 'integer']
        );

        $data = $this->connection->fetchAll('SELECT * FROM write_table WHERE test_int = 30');

        self::assertCount(1, $data);

        $this->connection->update('write_table', ['test_int' => 10], ['test_string' => null], ['test_string' => 'string', 'test_int' => 'integer']);

        $data = $this->connection->fetchAll('SELECT * FROM write_table WHERE test_int = 30');

        self::assertCount(0, $data);
    }

    public function testDeleteWhereIsNull() : void
    {
        $this->connection->insert(
            'write_table',
            ['test_int' => '30', 'test_string' => null],
            ['test_string' => 'string', 'test_int' => 'integer']
        );

        $data = $this->connection->fetchAll('SELECT * FROM write_table WHERE test_int = 30');

        self::assertCount(1, $data);

        $this->connection->delete('write_table', ['test_string' => null], ['test_string' => 'string']);

        $data = $this->connection->fetchAll('SELECT * FROM write_table WHERE test_int = 30');

        self::assertCount(0, $data);
    }
}
