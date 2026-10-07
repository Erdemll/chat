<?php

namespace App\Services\Moderation;

class CompositeProfanityEngine implements ProfanityEngine
{
    public function __construct(
        private readonly TerlikProfanityEngine $terlik,
        private readonly DictionaryProfanityEngine $dictionary,
    ) {}

    public function check(string $text): ModerationResult
    {
        $result = $this->terlik->check($text);

        if ($result->blocked()) {
            return $result;
        }

        return $this->dictionary->check($text);
    }
}
