<?php

namespace App\Notifications;

use App\Models\User;
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
        return $notifiable instanceof User ? ['database'] : ['mail'];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PROCURA: '.$this->details['request_number'].' — Admin deleted '.(($this->details['whole_transaction'] ?? false) ? 'a transaction' : 'a record'))
            ->line(($this->details['whole_transaction'] ?? false)
                ? 'An administrator deleted this entire purchase request and its linked workflow records.'
                : 'An administrator deleted procurement data for the request below.')
            ->line('Request: '.$this->details['request_number'])
            ->line('Requestor: '.$this->details['requestor'])
            ->line('Record: '.$this->details['record'])
            ->line('Deleted by: '.$this->details['actor'])
            ->line('Reason: '.$this->details['reason'])
            ->line('Deleted at: '.$this->details['deleted_at']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => ($this->details['whole_transaction'] ?? false) ? 'Transaction deleted by Admin' : 'Admin deleted procurement data',
            'message' => $this->details['request_number'].': '.$this->details['record'].' deleted by '.$this->details['actor'].'. Reason: '.$this->details['reason'],
            'request_number' => $this->details['request_number'],
            'reason' => $this->details['reason'],
            'url' => route('dashboard'),
        ];
    }
}
