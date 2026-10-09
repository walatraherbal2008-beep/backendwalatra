<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyStoreAdminEmailNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Notification::route('mail', ...) menggunakan AnonymousNotifiable,
        // sehingga tidak selalu memiliki properti `name` seperti model User.
        $greetingName = $notifiable instanceof \App\Models\User
            ? $notifiable->name
            : 'Admin Toko';

        return (new MailMessage)
            ->subject('Verifikasi email admin toko Walatra')
            ->greeting('Halo '.$greetingName.',')
            ->line('Silakan verifikasi email untuk mengaktifkan akses admin toko.')
            ->action('Verifikasi email', $this->url)
            ->line('Tautan ini hanya berlaku selama 24 jam.');
    }
}
