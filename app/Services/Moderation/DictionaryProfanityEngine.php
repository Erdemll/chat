<?php

namespace App\Services\Moderation;

use Normalizer;
use RuntimeException;

class DictionaryProfanityEngine implements ProfanityEngine
{
    /** @var array<string, true>|null */
    private ?array $words = null;

    public function __construct(private readonly string $dictionaryPath) {}

    public function check(string $text): ModerationResult
    {
        $words = $this->dictionary();
        $text = $this->normalize($text);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            if (isset($words[$token])) {
                return ModerationResult::block(category: 'profanity', reason: 'profanity_detected');
            }
        }

        /** Keep word boundaries at whitespace when joining punctuation-separated letters. */
        $chunks = preg_split('/[\p{Z}\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chunks as $chunk) {
            $joined = preg_replace('/[^\p{L}\p{N}]/u', '', $chunk);

            if ($joined !== null && isset($words[$joined])) {
                return ModerationResult::block(category: 'profanity', reason: 'profanity_detected');
            }
        }

        return ModerationResult::allow();
    }

    /** @return array<string, true> */
    private function dictionary(): array
    {
        if ($this->words !== null) {
            return $this->words;
        }

        $contents = file_get_contents($this->dictionaryPath);

        if ($contents === false) {
            throw new RuntimeException('Supplemental profanity dictionary could not be read.');
        }

        $entries = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('Supplemental profanity dictionary must be a list of words.');
        }

        $words = [];

        foreach ($entries as $entry) {
            if (! is_string($entry) || $entry === '') {
                throw new RuntimeException('Supplemental profanity dictionary entries must be non-empty words.');
            }

            $words[$this->normalize($entry)] = true;
        }

        return $this->words = $words;
    }

    private function normalize(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);

        if ($normalized === false) {
            throw new RuntimeException('Message could not be normalized.');
        }

        $lowercase = mb_strtolower(str_replace('İ', 'i', $normalized), 'UTF-8');

        return strtr($lowercase, [
            'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
            '$' => 's', '@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e',
            '4' => 'a', '5' => 's', '7' => 't', '8' => 'b',
        ]);
    }
}
