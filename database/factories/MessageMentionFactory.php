<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageMention>
 */
class MessageMentionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'user_id' => User::factory(),
            'created_at' => now(),
        ];
    }
}
