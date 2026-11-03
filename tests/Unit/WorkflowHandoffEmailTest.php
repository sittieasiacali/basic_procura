<?php

namespace Tests\Unit;

use App\Notifications\WorkflowHandoffEmail;
use PHPUnit\Framework\TestCase;

class WorkflowHandoffEmailTest extends TestCase
{
    public function test_it_renders_stage_specific_processing_details(): void
    {
        $notification = new WorkflowHandoffEmail('Purchasing canvassing', [
            'request_number' => 'REQ00134',
            'requestor' => 'Cali',
            'campaign' => 'Operations',
            'title' => 'Laptop request',
            'purpose' => 'New hire',
            'required_date' => 'Oct 31, 2026',
            'screening_team' => 'IT',
            'items' => ['Laptop - 1 pc'],
            'process_details' => [
                'Processed by: IT Staff',
                'Quotation: Supplier One - PHP 6,108.48 total (VAT exclusive)',
            ],
        ], 'https://procura.example.test/purchase-requests/134');

        $message = $notification->toMail(new \stdClass);

        $this->assertSame('PROCURA: REQ00134 - Purchasing canvassing', $message->subject);
        $this->assertContains('Processing details:', $message->introLines);
        $this->assertContains('Processed by: IT Staff', $message->introLines);
        $this->assertContains('Quotation: Supplier One - PHP 6,108.48 total (VAT exclusive)', $message->introLines);
        $this->assertNull($message->actionUrl);
        $this->assertNotContains('Requestor: Cali', $message->introLines);
        $this->assertNotContains('Requested items:', $message->introLines);
    }

    public function test_initial_submission_includes_full_request_details(): void
    {
        $notification = new WorkflowHandoffEmail('IT screening', [
            'request_number' => 'REQ00135', 'requestor' => 'Cali', 'campaign' => 'Operations',
            'title' => 'Laptop request', 'purpose' => 'New hire', 'required_date' => 'Oct 31, 2026',
            'screening_team' => 'IT', 'items' => ['Laptop - 1 pc'], 'process_details' => [],
        ], 'https://procura.example.test/purchase-requests/135', true);

        $message = $notification->toMail(new \stdClass);

        $this->assertContains('Requestor: Cali', $message->introLines);
        $this->assertContains('Requested items:', $message->introLines);
        $this->assertContains('Laptop - 1 pc', $message->introLines);
    }
    public function test_it_passes_the_workflow_tracker_position_to_the_email_view(): void
    {
        $notification = new WorkflowHandoffEmail('Purchasing canvassing', [
            'request_number' => 'REQ00134', 'requestor' => 'Cali', 'campaign' => 'Operations',
            'title' => 'Laptop request', 'purpose' => 'New hire', 'required_date' => 'Oct 31, 2026',
            'screening_team' => 'IT', 'items' => [], 'process_details' => [], 'workflow_step' => 3,
        ], 'https://procura.example.test/purchase-requests/134');

        $message = $notification->toMail(new \stdClass);

        $this->assertSame(3, $message->viewData['workflowStep']);
        $this->assertSame('Quotations & canvassing', $message->viewData['workflowSteps'][2]);
    }
}
