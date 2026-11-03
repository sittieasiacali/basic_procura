<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowHandoffEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    /** @param array{request_number:string,requestor:string,campaign:string,title:string,purpose:string,required_date:string,screening_team:string,items:list<string>,process_details:list<string>} $details */
    public function __construct(
        public string $stage,
        public array $details,
        public string $requestUrl,
        public bool $includeRequestDetails = false,
    )
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
        $message = (new MailMessage)
            ->subject('PROCURA: '.$this->details['request_number'].' - '.$this->stage)
            ->greeting('Hello,')
            ->line('The workflow for a request from your assigned Team Leader has been updated.')
            ->line('Request number: '.$this->details['request_number']);

        if ($this->includeRequestDetails) {
            $message
                ->line('Requestor: '.$this->details['requestor'])
                ->line('Campaign / Department: '.$this->details['campaign'])
                ->line('Request: '.$this->details['title'])
                ->line('Purpose: '.$this->details['purpose'])
                ->line('Required date: '.$this->details['required_date'])
                ->line('Screening team: '.$this->details['screening_team'])
                ->line('Requested items:')
                ->lines($this->details['items']);
        }

        $message->line('Current stage: '.$this->stage.'.');

        if (($this->details['process_details'] ?? []) !== []) {
            $message->line('Processing details:')->lines($this->details['process_details']);
        }

        return $message
            ->line('This is an informational update. No management review of the purchase request is required.')
            ->view(
                ['html' => 'emails.workflow-handoff', 'text' => 'emails.workflow-handoff-text'],
                $this->viewData()
            );
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $processDetails = [];
        $quotations = [];

        foreach ($this->details['process_details'] ?? [] as $detail) {
            [$label, $value] = str_contains($detail, ': ') ? explode(': ', $detail, 2) : ['Note', $detail];

            if ($label === 'Quotation') {
                $quotations[] = $this->parseQuotation($value);
            } else {
                $processDetails[] = ['label' => $label, 'value' => $value];
            }
        }

        return [
            'requestNumber' => $this->details['request_number'],
            'stage' => $this->stage,
            'requestDetails' => $this->includeRequestDetails ? [
                'Requestor' => $this->details['requestor'],
                'Campaign / Department' => $this->details['campaign'],
                'Request' => $this->details['title'],
                'Purpose' => $this->details['purpose'],
                'Required date' => $this->details['required_date'],
                'Screening team' => $this->details['screening_team'],
            ] : [],
            'items' => $this->includeRequestDetails ? $this->details['items'] : [],
            'processDetails' => $processDetails,
            'quotations' => $quotations,
            'workflowStep' => $this->details['workflow_step'] ?? null,
            'workflowSteps' => [
                'Request submitted',
                'IT / Premises screening',
                'Quotations & canvassing',
                'Supplier approval',
                'Purchase Order',
                'Budget & payment',
                'Delivery & completion',
            ],
        ];
    }

    /**
     * Splits "Supplier - PHP 1,234.00 total (VAT exclusive); recommended by Purchasing; approved"
     * into display parts for the quotation card.
     *
     * @return array{raw:string,supplier:string,amount:?string,vat:?string,highlight:bool,tags:list<array{text:string,bg:string,color:string}>}
     */
    private function parseQuotation(string $value): array
    {
        $parts = array_map('trim', explode(';', $value));
        $summary = array_shift($parts);
        $supplier = $summary;
        $amount = null;
        $vat = null;

        if (preg_match('/^(.*) - (PHP [\d,.]+) total(?: \((VAT [^)]+)\))?$/', $summary, $m)) {
            [$supplier, $amount, $vat] = [$m[1], $m[2], isset($m[3]) ? ucfirst($m[3]) : null];
        }

        $tags = [];
        $highlight = false;
        foreach ($parts as $part) {
            if ($part === 'approved') {
                $tags[] = ['text' => 'Approved', 'bg' => '#e3f4ec', 'color' => '#237a57'];
                $highlight = true;
            } elseif ($part === 'recommended by Purchasing') {
                $tags[] = ['text' => 'Recommended', 'bg' => '#fff3e8', 'color' => '#b5571a'];
                $highlight = true;
            } elseif ($part !== '') {
                $tags[] = ['text' => ucfirst($part), 'bg' => '#eef1f6', 'color' => '#475467'];
            }
        }

        return compact('supplier', 'amount', 'vat', 'highlight', 'tags') + ['raw' => $value];
    }
}
