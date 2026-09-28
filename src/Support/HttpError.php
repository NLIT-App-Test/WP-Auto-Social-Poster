<?php

namespace Admun\GrowthEngine\Support;

defined("ABSPATH") || exit;

class HttpError extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var mixed */
    public $body;

    public function __construct(string $message, int $status = 0, $body = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->body = $body;
    }
}
