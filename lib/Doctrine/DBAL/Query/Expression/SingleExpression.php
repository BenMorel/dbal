<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

class SingleExpression extends Expression
{
    /**
     * @var string
     */
    private $expr;

    public function __construct(string $expr)
    {
        $this->expr = $expr;
    }

    public function and($expr) : Expression
    {
        $expr = Expression::wrap($expr);

        return new CompositeExpression(CompositeExpression::TYPE_AND, $this, $expr);
    }

    public function or($expr) : Expression
    {
        $expr = Expression::wrap($expr);

        return new CompositeExpression(CompositeExpression::TYPE_OR, $this, $expr);
    }

    public function __toString() : string
    {
        return $this->expr;
    }
}
