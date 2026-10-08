<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationDelivery> */
class NotificationDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => NotificationDelivery::TYPE_MENTION,
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'reference_type' => NotificationDelivery::REFERENCE_MESSAGE,
            'reference_id' => Message::factory(),
            'status' => NotificationDelivery::STATUS_PENDING,
            'attempt_count' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationDelivery::STATUS_SENT,
            'attempt_count' => 1,
            'last_attempt_at' => now(),
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempt_count' => 1,
            'last_attempt_at' => now(),
            'failed_at' => now(),
            'last_error' => 'RuntimeException',
        ]);
    }
}
