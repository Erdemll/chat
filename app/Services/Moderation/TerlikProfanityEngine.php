<?php

namespace App\Services\Moderation;

use Terlik\Terlik;

class TerlikProfanityEngine implements ProfanityEngine
{
    public function __construct(private readonly Terlik $terlik) {}

    public function check(string $text): ModerationResult
    {
        return $this->terlik->containsProfanity($text)
            ? ModerationResult::block(category: 'profanity', reason: 'profanity_detected')
            : ModerationResult::allow();
    }
}
