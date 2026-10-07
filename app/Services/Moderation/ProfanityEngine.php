<?php

namespace App\Services\Moderation;

interface ProfanityEngine
{
    public function check(string $text): ModerationResult;
}
