<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password#'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);

        return (new MailMessage)
            ->subject('Restablece tu contraseña de Kova')
            ->greeting('Hola')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Crear una nueva contraseña', $url)
            ->line('Este enlace vence en '.config('auth.passwords.users.expire').' minutos y solo puede usarse una vez.')
            ->line('Si no solicitaste este cambio, puedes ignorar este correo. Tu contraseña no cambiará.');
    }
}
