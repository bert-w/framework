<?php

namespace Illuminate\Database;

use PDO;

class FetchMode
{
    public function __construct(
        protected array $arguments = [],
    ) {
        //
    }

    public function arguments()
    {
        return $this->arguments;
    }

    public static function value(): self
    {
        return new self([PDO::FETCH_COLUMN]);
    }

    public static function keyValue(): self
    {
        return new self([PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE]);
    }
}
