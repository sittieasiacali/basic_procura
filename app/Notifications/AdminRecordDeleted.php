<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminRecordDeleted extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public array $details)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PROCURA: '.$this->details['request_number'].' — Admin deleted a record')
            ->line('An administrator deleted a record associated with your assigned requestor.')
            ->line('Request: '.$this->details['request_number'])
            ->line('Requestor: '.$this->details['requestor'])
            ->line('Record: '.$this->details['record'])
            ->line('Deleted by: '.$this->details['actor'])
            ->line('Reason: '.$this->details['reason'])
            ->line('Deleted at: '.$this->details['deleted_at']);
    }
}
