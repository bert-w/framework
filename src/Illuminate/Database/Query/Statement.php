<?php

namespace Illuminate\Database\Query;

use Illuminate\Support\Traits\Macroable;
use PDOStatement;

class Statement
{
    use Macroable {
        __call as macroCall;
    }

    public function __construct(
        protected PDOStatement $statement,
    ) {
        //
    }

    public function statement(): PDOStatement
    {
        return $this->statement;
    }

    /**
     * @return mixed
     */
    public function fetch(FetchMode $mode): mixed
    {
        return $this->statement->fetch(...$mode->arguments());
    }

    /**
     * @return mixed
     */
    public function fetchAll(FetchMode $mode): mixed
    {
        return $this->statement->fetchAll(...$mode->arguments());
    }

    /**
     * @param string $method
     * @param mixed $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->statement->$method(...$parameters);
    }
}
