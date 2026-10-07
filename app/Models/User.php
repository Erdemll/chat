<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\UserInvitationNotification;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $role
 * @property bool $is_active
 * @property Carbon|null $invited_at
 * @property Carbon|null $invitation_failed_at
 * @property Carbon|null $password_set_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_active_at
 * @property Carbon|null $last_message_read_at
 * @property-read Role $assignedRole
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_ADMIN = Role::ADMIN;

    public const ROLE_EMPLOYEE = Role::EMPLOYEE;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'invitation_failed_at' => 'datetime',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'last_message_read_at' => 'datetime',
        ];
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<MessageRead, $this> */
    public function messageReads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    /** @return HasMany<MessageMention, $this> */
    public function messageMentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === self::ROLE_ADMIN;
    }

    /** @return BelongsTo<Role, $this> */
    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'slug');
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] mixed $token): void
    {
        if (! $this->is_active) {
            return;
        }

        $this->notify($this->password_set_at === null
            ? new UserInvitationNotification($token)
            : new ResetPassword($token));
    }
}
