<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ProcurementUpdate extends Notification
{
    public function __construct(public array $details) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->details;
    }
}
