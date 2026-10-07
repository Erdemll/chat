<?php

namespace App\Exceptions;

use App\Services\Moderation\ModerationResult;
use RuntimeException;

class MessageRejectedException extends RuntimeException
{
    public function __construct(public readonly ModerationResult $result)
    {
        parent::__construct('Message blocked by moderation');
    }
}
