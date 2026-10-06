<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->word(), 'slug' => fake()->unique()->slug()];
    }

    public function general(): static
    {
        return $this->state(fn () => ['name' => 'Genel', 'slug' => 'general']);
    }
}
