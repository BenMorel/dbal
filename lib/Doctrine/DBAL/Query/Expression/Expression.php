<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

abstract class Expression
{
    /**
     * Creates a conjunction of this expression and the given one.
     *
     * @param string|Expression $expr
     */
    abstract public function and($expr) : Expression;

    /**
     * Creates a disjunction of this expression and the given one.
     *
     * @param string|Expression $expr
     */
    abstract public function or($expr) : Expression;

    /**
     * Converts this expression to a string.
     */
    abstract public function __toString() : string;

    /**
     * Wraps the given expression in an Expression object.
     *
     * @param string|Expression $expr
     */
    public static function wrap($expr) : Expression
    {
        return $expr instanceof Expression ? $expr : new SingleExpression($expr);
    }
}
