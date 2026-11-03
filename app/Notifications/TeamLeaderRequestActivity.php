<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamLeaderRequestActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public string $activity, public string $dashboardUrl)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PROCURA: Team Leader request '.$this->activity)
            ->greeting('Hello,')
            ->line('A Team Leader assigned to you has '.$this->activity.' a request in PROCURA.')
            ->line($this->activity === 'withdrawn'
                ? 'Processing is paused while the withdrawal awaits staff acknowledgment.'
                : 'Sign in to review the latest request activity.')
            ->action('Open PROCURA', route('dashboard'))
            ->line('This is an automated activity notification for the assigned OM and SMT.');
    }
}
