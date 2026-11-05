<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemHealthAlert extends Notification
{
    /** @param list<array{label: string, status: string, summary: string, help: ?string}> $problems */
    public function __construct(private array $problems) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject(config('app.name').': system health problem')
            ->line('The daily health check found '.count($this->problems).' red item(s):');

        foreach ($this->problems as $problem) {
            $mail->line("**{$problem['label']}**: {$problem['summary']}");
            if ($problem['help']) {
                $mail->line("What to do: {$problem['help']}");
            }
        }

        return $mail->action('Open System Health', route('admin.system-health'));
    }
}
