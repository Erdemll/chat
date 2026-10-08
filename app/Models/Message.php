<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $edited_at
 * @property-read User $user
 * @property-read int $reads_count
 */
#[Fillable(['body'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function editExpiresAt(): CarbonImmutable
    {
        return $this->created_at->copy()->addMinutes(max(0, (int) config('chat.message_edit_window_minutes')));
    }

    public function isWithinEditWindow(): bool
    {
        return (int) config('chat.message_edit_window_minutes') > 0 && now()->lte($this->editExpiresAt());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return HasMany<MessageRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    /** @return HasMany<MessageMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function mentionedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_mentions')->withPivot('created_at')->orderBy('users.id');
    }

    /** @param Builder<Message> $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->is_active) {
            $query->whereRaw('1 = 0');
        }

        $query->whereHas('channel', fn (Builder $channels) => $channels->where('slug', 'general'));
    }
}
