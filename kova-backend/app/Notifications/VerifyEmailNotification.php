<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class VerifyEmailNotification extends VerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        if (app()->environment('local') && config('mail.default') === 'log') {
            Log::info('Kova verification URL - copy the url value exactly', [
                'url' => $url,
            ]);
        }

        return (new MailMessage)
            ->subject('Verifica tu cuenta de Kova')
            ->greeting('¡Bienvenido a Kova!')
            ->line('Confirma tu correo para activar tu cuenta y comenzar a configurar tu negocio.')
            ->action('Verificar mi correo', $url)
            ->line('Este enlace vence en 60 minutos.')
            ->line('Si no creaste esta cuenta, puedes ignorar este mensaje.');
    }
}
