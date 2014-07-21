<?php

namespace Doctrine\DBAL;

/**
 * Defines the parameters of a transaction.
 */
class TransactionDefinition
{
    /**
     * The transaction manager that created this definition.
     *
     * @var \Doctrine\DBAL\TransactionManager
     */
    private $transactionManager;

    /**
     * The isolation level for this transaction.
     *
     * @var integer|null
     */
    private $isolationLevel = null;

    /**
     * Class constructor.
     *
     * @param TransactionManager $manager
     */
    public function __construct(TransactionManager $manager)
    {
        $this->transactionManager = $manager;
    }

    /**
     * Sets the isolation level for this transaction.
     *
     * @param integer $isolationLevel One of the Connection::TRANSACTION_* constants.
     *
     * @return \Doctrine\DBAL\TransactionDefinition The current instance for chaining.
     */
    public function withIsolationLevel($isolationLevel)
    {
        $this->isolationLevel = $isolationLevel;

        return $this;
    }

    /**
     * Begins the transaction and returns the associated Transaction object.
     *
     * @return \Doctrine\DBAL\Transaction
     */
    public function begin()
    {
        return $this->transactionManager->createTransaction($this);
    }
}
