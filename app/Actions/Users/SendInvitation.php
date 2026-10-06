<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendInvitation
{
    public function handle(User $user): string
    {
        if (! $user->is_active) {
            return 'Pasif kullanıcıya davet gönderilemez.';
        }
        try {
            $status = Password::sendResetLink(['email' => $user->email, 'is_active' => true]);
            if ($status === Password::ResetLinkSent) {
                $user->forceFill(['invited_at' => now(), 'invitation_failed_at' => null])->save();

                return 'Şifre bağlantısı gönderildi.';
            }
            if ($status === Password::ResetThrottled) {
                return 'Yeni bağlantı göndermeden önce bir dakika bekleyin.';
            }

            return 'Şifre bağlantısı gönderilemedi.';
        } catch (Throwable $exception) {
            $user->forceFill(['invitation_failed_at' => now()])->save();
            Log::error('Invitation delivery failed', ['user_id' => $user->id, 'exception_type' => $exception::class]);

            return 'Kullanıcı kaydedildi fakat davet maili gönderilemedi. Daveti yeniden gönderebilirsiniz.';
        }
    }
}
