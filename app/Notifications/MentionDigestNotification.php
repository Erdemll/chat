<?php

namespace App\Notifications;

use App\Models\MessageMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class MentionDigestNotification extends Notification
{
    /** @var list<array{sender: string, preview: string, created_at: string}> */
    public readonly array $items;

    public readonly string $chatUrl;

    /** @param Collection<int, MessageMention> $mentions */
    public function __construct(Collection $mentions)
    {
        $this->items = array_values($mentions->map(fn (MessageMention $mention): array => [
            'sender' => $mention->message->user->name,
            'preview' => Str::limit(Str::squish($mention->message->body), 240),
            'created_at' => $mention->message->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i'),
        ])->all());
        $this->chatUrl = rtrim(config('app.url'), '/').'/chat';
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('TEPENET İletişim — Okunmamış bahsetmeler')
            ->view(['mail.mention-digest', 'mail.mention-digest-text'], [
                'recipientName' => $notifiable->name,
                'items' => $this->items,
                'chatUrl' => $this->chatUrl,
            ]);
    }
}
