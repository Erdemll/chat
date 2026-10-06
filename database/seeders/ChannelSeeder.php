<?php

namespace Database\Seeders;

use App\Models\Channel;
use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        Channel::query()->firstOrCreate(['slug' => 'general'], ['name' => 'Genel']);
    }
}
