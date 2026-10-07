<?php

namespace App\Services\Moderation;

final readonly class ModerationResult
{
    private function __construct(
        public bool $allowed,
        public ?string $category,
        public ?string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, null, null);
    }

    public static function block(string $category, string $reason): self
    {
        return new self(false, $category, $reason);
    }

    public function blocked(): bool
    {
        return ! $this->allowed;
    }
}
