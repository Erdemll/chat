<?php

use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use App\Notifications\MentionDigestNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function digestNotificationForBody(string $body, string $senderName = 'Erdem Lale'): MentionDigestNotification
{
    $message = Message::factory()->make(['body' => $body, 'created_at' => now()]);
    $message->setRelation('user', User::factory()->make(['name' => $senderName]));
    $mention = MessageMention::factory()->make();
    $mention->setRelation('message', $message);

    return new MentionDigestNotification(new Collection([$mention]));
}

test('long Unicode message previews are limited without exposing the full body', function () {
    $this->freezeTime();
    $notification = digestNotificationForBody(str_repeat('Ş', 400).'SECRET_TAIL');
    $user = User::factory()->make();

    $html = (string) $notification->toMail($user)->render();

    expect($notification->items[0]['preview'])->toBe(str_repeat('Ş', 240).'...');
    expect($html)->toContain(str_repeat('Ş', 240).'...')->not->toContain('SECRET_TAIL');
});

test('recipient sender and message content are escaped in digest HTML', function () {
    $this->freezeTime();
    $notification = digestNotificationForBody('<img src=x onerror=alert(1)> & <script>body</script>', '<script>sender</script>');
    $user = User::factory()->make(['name' => '<script>recipient</script>']);

    $html = (string) $notification->toMail($user)->render();

    expect($html)->toContain('&lt;script&gt;recipient', '&lt;script&gt;sender', '&lt;img', '&lt;script&gt;body')
        ->not->toContain('<script>', '<img src=x');
});

test('digest CTA uses configured application URL including its base path', function () {
    $this->freezeTime();
    config(['app.url' => 'https://configured.example.test/company/']);
    request()->headers->set('Host', 'attacker.example.test');
    $notification = digestNotificationForBody('Raporu kontrol eder misiniz?');

    $html = (string) $notification->toMail(User::factory()->make())->render();

    expect($notification->chatUrl)->toBe('https://configured.example.test/company/chat');
    expect($html)->toContain('href="https://configured.example.test/company/chat"')
        ->not->toContain('attacker.example.test');
});

test('digest is sent synchronously with HTML and plain text through the existing mail channel', function () {
    $this->freezeTime();
    config(['mail.default' => 'array', 'app.timezone' => 'Europe/Istanbul']);
    $notification = digestNotificationForBody('@Ahmet raporu kontrol eder misin?');
    $user = User::factory()->make(['id' => 123, 'name' => 'Ahmet', 'email' => 'ahmet@example.test']);
    Queue::fake();

    $user->notify($notification);

    Queue::assertNothingPushed();
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect($message->getSubject())->toBe('TEPENET İletişim — Okunmamış bahsetmeler');
    expect($message->getTo()[0]->getAddress())->toBe('ahmet@example.test');
    expect($message->getHtmlBody())->toContain('Merhaba Ahmet', '1 mesajda', 'Erdem Lale', '@Ahmet raporu');
    expect($message->getTextBody())->toContain('Merhaba Ahmet', 'Erdem Lale', 'Mesajları görüntüle:');
});
