<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

use function implode;

/**
 * Composite expression is responsible to build a group of similar expression.
 */
class CompositeExpression extends Expression
{
    /**
     * Constant that represents an AND composite expression.
     */
    public const TYPE_AND = 'AND';

    /**
     * Constant that represents an OR composite expression.
     */
    public const TYPE_OR = 'OR';

    /**
     * The instance type of composite expression (AND/OR).
     *
     * @var string
     */
    private $type;

    /**
     * Each expression part of the composite expression.
     *
     * @var Expression[]
     */
    private $parts = [];

    /**
     * @param string|Expression $a
     * @param string|Expression $b
     * @param string|Expression ...$more
     */
    public function __construct(string $type, $a, $b, ...$more)
    {
        array_unshift($more, $a, $b);

        $this->type  = $type;
        $this->parts = array_map([Expression::class, 'wrap'], $more);
    }

    public function and($expr) : Expression
    {
        if ($this->type === self::TYPE_AND) {
            return new CompositeExpression(self::TYPE_AND, ...array_merge($this->parts, [$expr]));
        }

        return new CompositeExpression(self::TYPE_AND, $this, $expr);
    }

    public function or($expr) : Expression
    {
        if ($this->type === self::TYPE_OR) {
            return new CompositeExpression(self::TYPE_OR, ...array_merge($this->parts, [$expr]));
        }

        return new CompositeExpression(self::TYPE_OR, $this, $expr);
    }

    /**
     * Retrieves the string representation of this composite expression.
     */
    public function __toString() : string
    {
        $parts = array_map(function(Expression $part) {
            return '(' . $part . ')';
        }, $this->parts);

        return implode(' ' . $this->type . ' ', $parts);
    }

    /**
     * Returns the type of this composite expression (AND/OR).
     */
    public function getType() : string
    {
        return $this->type;
    }
}
