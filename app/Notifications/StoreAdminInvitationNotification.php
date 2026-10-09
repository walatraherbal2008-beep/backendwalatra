<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StoreAdminInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $storeName,
        private readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Undangan admin toko '.$this->storeName)
            ->greeting('Halo,')
            ->line('Anda diundang untuk menjadi admin toko '.$this->storeName.'.')
            ->action('Buka undangan', $this->url)
            ->line('Undangan ini hanya berlaku selama 24 jam dan hanya dapat digunakan satu kali.');
    }
}
