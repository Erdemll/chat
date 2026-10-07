<?php

namespace App\Services\Moderation;

class MessageModerationService
{
    public function __construct(private readonly ProfanityEngine $profanity) {}

    public function moderate(string $text): ModerationResult
    {
        if (! config('moderation.enabled')) {
            return ModerationResult::allow();
        }

        return $this->profanity->check($text);
    }
}
