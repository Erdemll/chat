<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageRead;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('initial load and backward and forward batches use independently configured limits', function () {
    config(['chat.initial_message_limit' => 7, 'chat.history_batch_size' => 3]);
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(12)->for($user)->for($channel)->create();

    $this->actingAs($user)->get(route('chat'))->assertInertia(fn (Assert $page) => $page
        ->has('history.data', 7)->where('history.before_id', $messages[5]->id)->where('history.after_id', $messages[11]->id)->where('history.has_more', true));
    $this->getJson(route('messages.index', ['before_id' => $messages[5]->id]))
        ->assertJsonCount(3, 'data')->assertJsonPath('data.*.id', [$messages[2]->id, $messages[3]->id, $messages[4]->id])->assertJsonPath('has_more', true);
    $this->getJson(route('messages.index', ['after_id' => $messages[5]->id]))
        ->assertJsonCount(3, 'data')->assertJsonPath('data.*.id', [$messages[6]->id, $messages[7]->id, $messages[8]->id])->assertJsonPath('has_more', true);
});

test('empty small and exactly full initial histories correctly report the end', function (int $count) {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count($count)->for($user)->for($channel)->create();

    $this->actingAs($user)->getJson(route('messages.index'))
        ->assertJsonCount($count, 'data')->assertJsonPath('has_more', false)
        ->assertJsonPath('before_id', $messages->first()?->id)->assertJsonPath('after_id', $messages->last()?->id);
})->with([0, 10, 40]);

test('pagination crosses deleted cursor IDs and gaps without duplicates or false end flags', function () {
    config(['chat.initial_message_limit' => 3, 'chat.history_batch_size' => 3]);
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(11)->for($user)->for($channel)->create();
    foreach ([1, 4, 7, 10] as $offset) {
        $messages[$offset]->delete();
    }
    $this->actingAs($user);

    $initial = $this->getJson(route('messages.index'))->assertJsonPath('has_more', true)->json();
    $older = $this->getJson(route('messages.index', ['before_id' => $initial['before_id']]))->assertJsonPath('has_more', true)->json();
    $last = $this->getJson(route('messages.index', ['before_id' => $older['before_id']]))->assertJsonCount(1, 'data')->assertJsonPath('has_more', false)->json();

    expect([...array_column($last['data'], 'id'), ...array_column($older['data'], 'id'), ...array_column($initial['data'], 'id')])
        ->toBe([$messages[0]->id, $messages[2]->id, $messages[3]->id, $messages[5]->id, $messages[6]->id, $messages[8]->id, $messages[9]->id]);
    $this->getJson(route('messages.index', ['before_id' => $messages[4]->id]))
        ->assertJsonPath('data.*.id', [$messages[0]->id, $messages[2]->id, $messages[3]->id])->assertJsonPath('has_more', false);
    $this->getJson(route('messages.index', ['after_id' => $messages[7]->id]))
        ->assertJsonPath('data.*.id', [$messages[8]->id, $messages[9]->id])->assertJsonPath('has_more', false);
    $this->getJson(route('messages.index', ['before_id' => $messages[0]->id]))->assertJsonCount(0, 'data')->assertJsonPath('has_more', false)->assertJsonPath('before_id', null);
    $this->getJson(route('messages.index', ['after_id' => $messages[10]->id]))->assertJsonCount(0, 'data')->assertJsonPath('has_more', false)->assertJsonPath('after_id', null);
});

test('malformed or conflicting history cursors return 422', function (array $query, string $field) {
    $this->actingAs(User::factory()->create())->getJson(route('messages.index', $query))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero before' => [['before_id' => 0], 'before_id'],
    'negative before' => [['before_id' => -1], 'before_id'],
    'text before' => [['before_id' => 'invalid'], 'before_id'],
    'array before' => [['before_id' => [1]], 'before_id'],
    'decimal before' => [['before_id' => '1.5'], 'before_id'],
    'negative after' => [['after_id' => -1], 'after_id'],
    'text after' => [['after_id' => 'invalid'], 'after_id'],
    'array after' => [['after_id' => [1]], 'after_id'],
    'both directions' => [['before_id' => 10, 'after_id' => 0], 'before_id'],
]);

test('large histories keep all page directions in three indexed queries with mentions and read counts', function (int $count) {
    $user = User::factory()->create();
    $reader = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $factory = Message::factory()->for($user)->for($channel)->state(['created_at' => now(), 'updated_at' => now()]);
    $attributes = $factory->count($count)->make()->map(fn (Message $message): array => $message->getAttributes());
    foreach ($attributes->chunk(250) as $chunk) {
        Message::query()->insert($chunk->all());
    }
    $first = Message::query()->oldest('id')->firstOrFail();
    $last = Message::query()->latest('id')->firstOrFail();
    MessageMention::factory()->for($first)->for($reader)->create();
    MessageMention::factory()->for($last)->for($reader)->create();
    MessageRead::factory()->for($first)->for($reader)->create();
    MessageRead::factory()->for($last)->for($reader)->create();
    $service = app(MessageService::class);
    $this->actingAs($user);

    foreach ([[null, null], [$last->id, null], [null, 0]] as [$beforeId, $afterId]) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        $history = $service->history($channel, $beforeId, $afterId);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(3);
        expect($history['data'])->toHaveCount(40);
        expect($history['has_more'])->toBeTrue();
        $ids = array_column($history['data'], 'id');
        expect($ids)->toBe(array_values(array_unique($ids)));
        expect($ids)->toBe(range($beforeId === null && $afterId === null ? $count - 39 : ($afterId === null ? $count - 40 : 1), $beforeId === null && $afterId === null ? $count : ($afterId === null ? $count - 1 : 40)));
        if ($beforeId === null) {
            $row = $afterId === null ? $history['data'][39] : $history['data'][0];
            expect($row['read_count'])->toBe(1);
            expect($row['mentions'])->toBe([['id' => $reader->id, 'name' => $reader->name]]);
        }
        $plan = DB::select('EXPLAIN QUERY PLAN '.$queries[0]['query'], $queries[0]['bindings']);
        $details = implode(' ', array_column($plan, 'detail'));
        expect($details)->toContain('messages_channel_id_deleted_at_id_index')->not->toContain('USE TEMP B-TREE')->not->toContain('SCAN messages');
        if (getenv('CHAT_HISTORY_BENCHMARK')) {
            fwrite(STDOUT, sprintf("\n%d messages, before=%s after=%s: %.2fms, %d queries\n", $count, $beforeId ?? 'none', $afterId ?? 'none', $elapsed, count($queries)));
        }
    }
})->with([1000, 5000])->group('performance');
