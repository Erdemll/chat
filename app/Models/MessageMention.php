<?php

namespace App\Models;

use Database\Factories\MessageMentionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $message_id
 * @property int $user_id
 * @property Carbon $created_at
 * @property-read Message $message
 * @property-read User $user
 */
#[Fillable(['created_at'])]
class MessageMention extends Model
{
    /** @use HasFactory<MessageMentionFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<MessageMention> $query */
    #[Scope]
    protected function unreadFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->whereHas('message', fn (Builder $messages) => $messages->visibleTo($user)->whereDoesntHave('reads', fn (Builder $reads) => $reads->where('user_id', $user->id)));
    }
}
