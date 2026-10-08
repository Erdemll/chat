<?php

namespace App\Models;

use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $channel
 * @property string $reference_type
 * @property int $reference_id
 * @property string $status
 * @property int $attempt_count
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $last_error
 */
#[Fillable(['type', 'channel', 'reference_type', 'reference_id', 'status', 'attempt_count', 'last_attempt_at', 'sent_at', 'failed_at', 'last_error'])]
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    public const TYPE_MENTION = 'mention';

    public const CHANNEL_EMAIL = 'email';

    public const REFERENCE_MESSAGE = 'message';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
