<?php

namespace Tests\Feature;

use App\Support\WorkflowUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WorkflowSubdirectoryUrlTest extends TestCase
{
    public function test_workflow_links_retain_the_apache_subdirectory_and_current_origin(): void
    {
        $request = Request::create('http://localhost/basic%20procura/public/dashboard', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/basic procura/public/index.php',
            'SCRIPT_FILENAME' => 'C:/xampp/htdocs/basic procura/public/index.php',
        ]);
        URL::setRequest($request);
        $this->assertSame('/basic%20procura/public', $request->getBaseUrl());
        $this->assertSame('/workflow/done', route('workflow.done', [], false));
        $this->assertSame('/basic%20procura/public/workflow/done', WorkflowUrl::route('workflow.done'));
        foreach ([
            ['purchase-requests.withdrawal.acknowledge', ['purchaseRequest' => 12]],
            ['purchase-requests.show', ['purchaseRequest' => 12, 'preview' => 1]],
            ['budget.create', ['purchase_request_id' => 12]],
            ['canvassing.quotations.store', ['purchaseRequest' => 12]],
            ['president-approvals.supplier.decision', ['purchaseRequest' => 12]],
        ] as [$name, $parameters]) {
            $path = WorkflowUrl::route($name, $parameters);
            $this->assertStringStartsWith('/basic%20procura/public/', $path);
            $this->assertStringNotContainsString('http:', $path);
        }
        $this->assertSame('/basic%20procura/public/budget/create?purchase_request_id=12', WorkflowUrl::route('budget.create', ['purchase_request_id' => 12]));
    }

    public function test_root_hosted_workflow_links_continue_to_work(): void
    {
        URL::setRequest(Request::create('https://procura.test/dashboard'));
        $this->assertSame('/workflow/done', WorkflowUrl::route('workflow.done'));
        $this->assertSame('/budget/create?purchase_request_id=12', WorkflowUrl::route('budget.create', ['purchase_request_id' => 12]));
    }
}
