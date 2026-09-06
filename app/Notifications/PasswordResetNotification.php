<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;

class PasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = Crypt::encryptString(json_encode([
            'email' => $notifiable->getEmailForPasswordReset(),
            'token' => $this->token,
        ], JSON_THROW_ON_ERROR));

        $opaqueToken = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $url = route('password.reset', [
            'token' => $opaqueToken,
        ]);

        return (new MailMessage)
            ->subject('Sistema UGDA - Restablecer contraseña')
            ->view('emails.password_reset', ['url' => $url]);
    }
}
