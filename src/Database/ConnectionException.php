<?php

namespace GlpiPlugin\G4freportsana\Database;

/** An intentionally sanitized error; never attach a native driver's exception. */
class ConnectionException extends \RuntimeException
{
    private string $reason;
    private int $mysqlCode;

    public function __construct(string $reason, string $message, int $mysqlCode = 0)
    {
        parent::__construct($message, $mysqlCode);
        $this->reason = $reason;
        $this->mysqlCode = $mysqlCode;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getMysqlCode(): int
    {
        return $this->mysqlCode;
    }
}
