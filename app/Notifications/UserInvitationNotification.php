<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class UserInvitationNotification extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Çalışan hesabınız hazır')
            ->greeting('Merhaba!')
            ->line('Şirket mesajlaşma hesabınız oluşturuldu. Başlamak için şifrenizi belirleyin.')
            ->action('Şifremi oluştur', $this->resetUrl($notifiable))
            ->line('Bu bağlantı '.config('auth.passwords.users.expire').' dakika geçerlidir.')
            ->line('Bu daveti beklemiyorsanız bu mesajı dikkate almayın.');
    }
}
