<?php

use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\User;
use App\Services\Moderation\DictionaryProfanityEngine;
use App\Services\Moderation\ModerationResult;
use App\Services\Moderation\TerlikProfanityEngine;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Terlik\Terlik;

beforeEach(function () {
    config(['moderation.enabled' => true]);
});

test('fallback profanity returns the existing 422 without message event broadcast or transaction', function () {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    $events = [];
    Event::listen(MessageCreated::class, function (MessageCreated $event) use (&$events): void {
        $events[] = $event;
    });
    Event::fake([TransactionBeginning::class]);
    Broadcast::shouldReceive('queue')->never();
    $error = 'Mesajınız şirket iletişim kurallarına uygun olmadığı için gönderilemedi.';

    $this->actingAs($user)->postJson(route('messages.store'), ['body' => '$1kt1rler'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => $error, 'errors' => ['body' => [$error]]]);

    $this->assertDatabaseCount('messages', 0);
    Event::assertNotDispatched(TransactionBeginning::class);
    expect($events)->toBeEmpty();
});

test('normal Turkish messages and similar innocent words remain allowed by the complete profanity chain', function (string $body) {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($user)->postJson(route('messages.store'), ['body' => $body])
        ->assertCreated()
        ->assertJsonPath('data.body', $body);

    $this->assertDatabaseHas('messages', ['body' => $body, 'user_id' => $user->id, 'channel_id' => $channel->id]);
    Event::assertDispatched(MessageCreated::class);
})->with([
    'normal message' => 'Merhaba, toplantı saat kaçta başlayacak?',
    'similar innocent words' => 'Amsterdam boksör malzeme dolunay sıkıntı',
    'substring boundaries' => 'Eksiktirler. AMK123',
    'distinct Turkish vowels' => 'Limonu sıktım. Son sıkım tamamlandı.',
]);

test('disabled moderation bypasses both Terlik and the supplemental dictionary', function () {
    config(['moderation.enabled' => false]);
    $this->app->instance(TerlikProfanityEngine::class, new class(new Terlik) extends TerlikProfanityEngine
    {
        public function check(string $text): ModerationResult
        {
            throw new RuntimeException('Disabled moderation must skip Terlik.');
        }
    });
    $this->app->instance(DictionaryProfanityEngine::class, new class('/dictionary-must-not-be-opened.json') extends DictionaryProfanityEngine
    {
        public function check(string $text): ModerationResult
        {
            throw new RuntimeException('Disabled moderation must skip the supplemental dictionary.');
        }
    });
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($user)->postJson(route('messages.store'), ['body' => '$1kt1rler'])
        ->assertCreated()
        ->assertJsonPath('data.body', '$1kt1rler');

    $this->assertDatabaseHas('messages', ['body' => '$1kt1rler', 'user_id' => $user->id, 'channel_id' => $channel->id]);
    Event::assertDispatched(MessageCreated::class);
});
