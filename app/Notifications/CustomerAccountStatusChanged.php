<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerAccountStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $status,
        private readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = match ($this->status) {
            'active' => 'diaktifkan kembali',
            'suspended' => 'ditangguhkan sementara',
            'blocked' => 'diblokir',
            'inactive' => 'dinonaktifkan',
            default => $this->status,
        };

        return (new MailMessage)
            ->subject('Perubahan status akun Walatra Herbal')
            ->greeting('Halo '.($notifiable->name ?: 'Customer').',')
            ->line('Status akun Anda telah '.$status.'.')
            ->line('Alasan: '.$this->reason)
            ->line('Jika memerlukan bantuan, silakan hubungi layanan Walatra Herbal.');
    }
}
