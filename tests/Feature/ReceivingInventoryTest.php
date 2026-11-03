<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceivingInventoryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    public function test_partial_payments_keep_the_request_for_payment_until_the_balance_is_cleared(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();

        $this->actingAs($finance)->post(route('payments.store', $request), [...$this->payment(), 'receipts' => []])
            ->assertSessionHasErrors('receipts');
        $this->post(route('payments.store', $request), [...$this->payment(), 'amount' => 150])
            ->assertSessionHasErrors('amount');

        $this->post(route('payments.store', $request), [...$this->payment(), 'amount' => 40, 'receiving_status' => 'RECEIVED'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, '₱ 60.00'));
        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        $first = $request->payments()->sole();
        $this->assertSame('PARTIAL', $first->payment_status);
        $this->assertSame('PARTIALLY_PAID', $request->budgetRequest()->first()->status);
        $receipt = $first->attachments()->sole();
        $this->assertSame('PAYMENT_RECEIPT', $receipt->category);
        Storage::disk('local')->assertExists($receipt->path);
        $this->get(route('attachments.download', $receipt))->assertOk();
        $this->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Partial payments recorded')->assertSee('max="60"', false)->assertSee('receipt.jpg');

        // Partially paid requests leave Awaiting payment for their own monitoring tab.
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('awaitingPaymentRequests', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('partialPaymentRequests', fn ($rows) => $rows->total() === 1);
        $this->get(route('dashboard', ['payment_tab' => 'partial']))->assertOk()
            ->assertSee('Partially paid')->assertSee($request->title)
            ->assertSee('40.00')->assertSee('60.00')
            ->assertSee('data-workflow-action-title="Mark as fully paid"', false);

        $this->post(route('payments.store', $request), [...$this->payment(), 'amount' => 60])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForReceiving, $request->fresh()->status);
        $this->assertSame('PAID', $request->payments()->latest('id')->first()->payment_status);
        $this->assertSame('PAID', $request->budgetRequest()->first()->status);
        $this->assertEquals(100, (float) $request->payments()->sum('amount'));
    }

    public function test_payment_marked_received_adds_every_po_item_to_inventory_automatically(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();
        $chairs = $request->purchaseOrder->items()->where('description', 'Ergonomic chair')->firstOrFail();

        // The goods section is read-only data from the PO: only serials, remarks and a signature are inputs.
        $this->actingAs($finance)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Ergonomic chair')->assertSee('Desk lamp')->assertSee('Main Office')
            ->assertSee('name="serial_numbers[po-'.$chairs->id.']"', false)
            ->assertDontSee('name="quantity"', false)
            ->assertDontSee('name="description"', false)
            ->assertSee('Received by');

        $this->post(route('payments.store', $request), [
            ...$this->payment(),
            ...$this->signature($finance),
            'receiving_status' => 'RECEIVED',
            'serial_numbers' => ['po-'.$chairs->id => 'SN-CHAIR-01'],
            'remarks' => 'Delivered complete',
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(1, $request->payments()->count());
        $items = InventoryItem::query()->where('purchase_request_id', $request->id)->orderBy('id')->get();
        $this->assertSame(['Ergonomic chair', 'Desk lamp'], $items->pluck('description')->all());
        $this->assertSame(['4.00', '2.00'], $items->pluck('quantity')->all());
        $this->assertSame(['pc', 'set'], $items->pluck('unit')->all());
        $this->assertSame(['SN-CHAIR-01', null], $items->pluck('serial_number')->all());
        $this->assertSame(['Main Office', 'Main Office'], $items->pluck('location')->all());
        $this->assertTrue($items->every(fn ($item) => $item->logged_by === $finance->id && $item->goods_receipt_item_id !== null));

        $receipt = $request->goodsReceipts()->firstOrFail();
        $this->assertSame($finance->name, $receipt->signature_name);
        $this->assertStringStartsWith('data:image/png;base64,', $receipt->signature_data);
        $this->assertSame('Delivered complete', $receipt->remarks);
    }

    public function test_payment_for_receiving_waits_on_the_finance_dashboard_until_finance_marks_it_received(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();

        // Awaiting payment is the Finance dashboard's default tab, shown as a table.
        $this->actingAs($finance)->get(route('dashboard'))->assertOk()
            ->assertSee('payment-queue-heading', false)
            // Rows are identified by BR number and project title, not the request or PO code.
            ->assertSee($request->title)->assertDontSee('<th>Request</th>', false)
            ->assertSee('data-workflow-action-title="Record payment"', false)
            ->assertSee('BR-RCV-', false)
            ->assertSee('data-br-preview-url', false)
            ->assertDontSee('dashboard-actions-panel', false);

        // A request whose Budget Request is not yet finally approved is not payable from here.
        $pending = $this->awaitingPayment();
        $pending->budgetRequest()->firstOrFail()->update(['final_approval_status' => null, 'status' => 'CHECKER_APPROVED']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee($pending->title);

        // Receiving fields (and the signature) are not required while goods are still For receiving.
        $this->post(route('payments.store', $request), [...$this->payment(), 'receiving_status' => 'FOR_RECEIVING'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForReceiving, $request->fresh()->status);
        $this->assertSame(0, InventoryItem::query()->count());

        $this->get(route('dashboard', ['payment_tab' => 'receiving']))->assertOk()
            ->assertSee('For receiving')
            ->assertSee($request->title)
            ->assertSee('data-workflow-action-title="Mark as received"', false);

        $this->post(route('receiving.store', $request), $this->signature($finance))->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(2, InventoryItem::query()->where('purchase_request_id', $request->id)->count());
    }

    public function test_receiving_uses_request_items_when_legacy_request_has_no_purchase_order(): void
    {
        $staff = $this->user(RoleSlug::InventoryStaff);
        $requestor = $this->user(RoleSlug::Requestor);
        $request = PurchaseRequest::query()->create([
            'request_number' => 'REQ-UAT-RECEIVING-TEST',
            'title' => 'Legacy receiving request',
            'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations',
            'request_type' => 'OTHER',
            'purpose' => 'Verify legacy receiving',
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
            'status' => PurchaseRequestStatus::ForReceiving,
            'current_responsible_role_id' => $staff->role_id,
        ]);
        $request->items()->create(['description' => 'Office monitor', 'quantity' => 2, 'unit' => 'pc']);

        $this->actingAs($staff)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Record Receiving and Update Inventory')
            ->assertSee('Office monitor');
        $this->post(route('receiving.store', $request), [
            ...$this->signature($staff), 'remarks' => 'Delivered and checked.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Completed, $request->fresh()->status);
        $receipt = $request->goodsReceipts()->firstOrFail();
        $this->assertNull($receipt->purchase_order_id);
        $this->assertSame($staff->name, $receipt->signature_name);
        $stock = InventoryItem::query()->where('purchase_request_id', $request->id)->firstOrFail();
        $this->assertSame('Office monitor', $stock->description);
        $this->assertSame('2.00', $stock->quantity);
    }

    public function test_receiving_completes_an_existing_receipt_without_duplicate_stock(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();
        $this->actingAs($finance)->post(route('payments.store', $request), [
            ...$this->payment(), 'receiving_status' => 'FOR_RECEIVING',
        ])->assertSessionHasNoErrors();
        $order = $request->purchaseOrder;
        $receipt = $request->goodsReceipts()->create([
            'receipt_number' => 'GR-LEGACY-'.$request->id,
            'purchase_order_id' => $order->id,
            'received_by' => $finance->id,
            'received_at' => now(),
            'status' => 'RECEIVED',
        ]);
        $chair = $order->items()->where('description', 'Ergonomic chair')->firstOrFail();
        $receipt->items()->create([
            'purchase_order_item_id' => $chair->id, 'description' => 'Ergonomic chair',
            'quantity_received' => 4, 'unit' => 'pc', 'condition' => 'GOOD',
        ]);

        $this->post(route('receiving.store', $request), $this->signature($finance))->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(1, $request->goodsReceipts()->count());
        $this->assertSame(2, $receipt->items()->count());
        $this->assertSame(2, InventoryItem::query()->where('purchase_request_id', $request->id)->count());
        $this->assertSame($finance->name, $receipt->fresh()->signature_name);
    }

    public function test_received_goods_require_the_receivers_own_signature(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();

        $this->actingAs($finance)
            ->post(route('payments.store', $request), [...$this->payment(), 'receiving_status' => 'RECEIVED'])
            ->assertSessionHasErrors(['signature_name', 'signature_data']);
        $this->post(route('payments.store', $request), [...$this->payment(), 'receiving_status' => 'RECEIVED', ...$this->signature($this->user(RoleSlug::Finance))])
            ->assertSessionHasErrors('signature_name');

        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        $this->assertSame(0, $request->payments()->count());
        $this->assertSame(0, InventoryItem::query()->count());
    }

    public function test_payment_without_a_budget_request_explains_why_instead_of_a_404(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();
        $request->budgetRequest()->firstOrFail()->allocations()->delete();

        $this->actingAs($finance)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('No Budget Request linked');
        // Awaiting payment lists only budget-approved requests.
        $this->get(route('dashboard'))->assertOk()->assertDontSee($request->request_number);

        $this->from(route('purchase-requests.show', $request))
            ->post(route('payments.store', $request), [...$this->payment(), 'receiving_status' => 'FOR_RECEIVING'])
            ->assertRedirect(route('purchase-requests.show', $request))
            ->assertSessionHasErrors('status');

        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        $this->assertSame(0, $request->payments()->count());
    }

    public function test_inventory_is_filled_only_by_receiving_and_finance_cannot_add_or_release(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $request = $this->awaitingPayment();

        // Finance never adds by hand: no Add item button, and the server refuses it.
        $this->actingAs($finance)->get(route('inventory.index'))->assertOk()
            ->assertDontSee('Add item')
            ->assertDontSee('inventory-add-modal', false)
            ->assertDontSee('data-inventory-release-url', false);
        $this->post(route('inventory.store'), ['description' => 'Spare keyboards', 'quantity' => 10, 'unit' => 'pc'])->assertForbidden();
        $this->assertSame(0, InventoryItem::query()->count());

        // Marking the goods Received is what fills the inventory.
        $this->post(route('payments.store', $request), [...$this->payment(), ...$this->signature($finance), 'receiving_status' => 'RECEIVED'])
            ->assertSessionHasNoErrors();
        $item = InventoryItem::query()->where('purchase_request_id', $request->id)->firstOrFail();

        $this->get(route('inventory.index'))->assertSee('Ergonomic chair')->assertSee($request->request_number)->assertSee('View only');
        $this->post(route('inventory.release', $item), ['quantity' => 1, 'released_to' => 'Finance desk'])->assertForbidden();
        $this->assertSame('4.00', $item->fresh()->quantity);
    }

    public function test_finance_dashboard_inventory_tab_is_view_only(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $finance->id,
            'description' => 'Dashboard tab monitor', 'quantity' => 3, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);

        $this->actingAs($finance)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Awaiting payment', 'For receiving', 'Inventory'])
            ->assertDontSee('Dashboard tab monitor');

        $this->get(route('dashboard', ['payment_tab' => 'inventory']))->assertOk()
            ->assertSee('Dashboard tab monitor')
            ->assertSee('added automatically')
            ->assertDontSee('inventory-add-modal', false)
            ->assertDontSee('data-inventory-release-url', false);
        $this->get(route('dashboard', ['payment_tab' => 'inventory', 'inventory_search' => 'nothing-like-this']))->assertOk()
            ->assertDontSee('Dashboard tab monitor');
    }

    public function test_it_and_premises_open_inventory_from_dashboard_greeting_to_view_add_and_release(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $staff = $this->user($role);
            $description = 'Spare toner for '.$role->value;

            $this->actingAs($staff)->get(route('dashboard'))->assertOk()
                ->assertSee('Processed by me')
                ->assertSee('class="btn btn-outline-primary dashboard-inventory-action', false)
                ->assertSee('href="'.route('inventory.index').'"', false)
                ->assertDontSee('inventory-account-button', false)
                ->assertDontSee('inventory-add-modal', false);

            $tab = route('inventory.index');
            $this->get($tab)->assertOk()
                ->assertSee('All stock')
                ->assertSee('Added items')
                ->assertSee('Released items')
                ->assertSee('data-bs-target="#inventory-add-modal"', false)
                ->assertSee('id="inventory-release-modal"', false);

            $this->from($tab)->post(route('inventory.store'), ['description' => $description, 'quantity' => 4, 'unit' => 'box', 'location' => 'Supply room'])
                ->assertRedirect($tab)
                ->assertSessionHasNoErrors();
            $item = InventoryItem::query()->where('description', $description)->firstOrFail();
            $this->assertNull($item->purchase_request_id);
            $this->assertSame($staff->id, $item->logged_by);

            $this->get($tab)->assertSee($description)->assertSee('data-inventory-release-url="'.route('inventory.release', $item).'"', false);
            $recipient = $this->user(RoleSlug::Requestor);
            $this->from($tab)->post(route('inventory.release', $item), [
                ...$this->signature($staff), 'quantity' => 1, 'recipient_user_id' => $recipient->id,
            ])
                ->assertRedirect($tab)
                ->assertSessionHasNoErrors();
            $this->assertSame('3.00', $item->fresh()->quantity);
            $this->get(route('inventory.index', ['view' => 'released']))->assertOk()->assertSee($description)->assertSee($recipient->name);
        }

        // Inventory role releases but does not add by hand.
        $this->actingAs($this->user(RoleSlug::Inventory))
            ->post(route('inventory.store'), ['description' => 'X', 'quantity' => 1, 'unit' => 'pc'])->assertForbidden();
    }

    public function test_inventory_it_and_premises_release_stock_within_the_available_quantity(): void
    {
        $item = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $this->user(RoleSlug::Finance)->id,
            'description' => 'Network cables', 'quantity' => 5, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);

        $premisesItem = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $this->user(RoleSlug::PremisesStaff)->id,
            'description' => 'Door hinges', 'quantity' => 5, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);

        foreach ([RoleSlug::Inventory, RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $page = $this->actingAs($this->user($role))->get(route('inventory.index'))->assertOk()
                ->assertSee('data-inventory-release-url', false);
            if ($role === RoleSlug::ItStaff) {
                $page->assertSee('Network cables')->assertDontSee('Door hinges');
            } elseif ($role === RoleSlug::PremisesStaff) {
                $page->assertSee('Door hinges')->assertDontSee('Network cables');
            } else {
                $page->assertSee('Network cables')->assertSee('Door hinges');
            }
            if ($role === RoleSlug::Inventory) {
                $page->assertDontSee('inventory-add-modal', false);
            } else {
                $page->assertSee('inventory-add-modal', false);
            }
        }

        $releaser = $this->user(RoleSlug::ItStaff);
        $recipient = $this->user(RoleSlug::Requestor);
        $this->actingAs($releaser)->post(route('inventory.release', $premisesItem), [
            ...$this->signature($releaser), 'quantity' => 1, 'recipient_user_id' => $recipient->id,
        ])->assertForbidden();
        $this->actingAs($this->user(RoleSlug::PremisesStaff))->post(route('inventory.release', $item), [
            ...$this->signature($this->user(RoleSlug::PremisesStaff)), 'quantity' => 1, 'recipient_user_id' => $recipient->id,
        ])->assertForbidden();
        $this->actingAs($releaser);
        $this->actingAs($releaser)->post(route('inventory.release', $item), [
            ...$this->signature($releaser), 'quantity' => 6, 'recipient_user_id' => $recipient->id,
        ])
            ->assertSessionHasErrors('quantity');
        $this->post(route('inventory.release', $item), [
            ...$this->signature($releaser), 'quantity' => 2, 'recipient_user_id' => $recipient->id, 'remarks' => 'New hires',
        ])
            ->assertSessionHasNoErrors();
        $this->assertSame('3.00', $item->fresh()->quantity);
        $this->assertSame(1, $item->releases()->count());
        $this->assertSame($releaser->id, $item->releases()->first()->released_by);

        $this->post(route('inventory.release', $item), [
            ...$this->signature($releaser), 'quantity' => 3, 'recipient_user_id' => $recipient->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('RELEASED', $item->fresh()->status);
        $this->get(route('inventory.index'))->assertOk()->assertSee('All stock')->assertSee('Network cables');
        $this->post(route('inventory.release', $item), ['quantity' => 1, 'released_to' => 'Team C'])->assertForbidden();
    }

    public function test_recipient_claims_released_item_with_private_proof_and_role_activity(): void
    {
        Storage::fake('local');
        $staff = $this->user(RoleSlug::ItStaff);
        $recipient = $this->user(RoleSlug::Requestor);
        $outsider = $this->user(RoleSlug::Requestor);
        $item = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $staff->id,
            'description' => 'Claimable monitor', 'quantity' => 2, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);

        $this->actingAs($staff)->post(route('inventory.release', $item), [
            'quantity' => 1, 'recipient_user_id' => $recipient->id,
        ])->assertSessionHasErrors('signature_data');
        $this->assertSame('2.00', $item->fresh()->quantity);

        $this->post(route('inventory.release', $item), [
            ...$this->signature($staff), 'quantity' => 1, 'recipient_user_id' => $recipient->id,
        ])->assertSessionHasNoErrors();
        $release = $item->releases()->firstOrFail();
        $this->assertSame($staff->name, $release->signature_name);
        $this->assertSame($recipient->id, $release->recipient_user_id);
        $this->get(route('inventory.index', ['view' => 'released']))
            ->assertOk()->assertSee('Claim item')
            ->assertSee('data-inventory-claim-id="'.$release->id.'"', false);

        $this->actingAs($outsider)->post(route('inventory.claim', $release), [
            'photos' => [UploadedFile::fake()->image('proof.png')],
        ])->assertForbidden();
        $this->get(route('inventory.index', ['view' => 'released']))->assertOk()->assertDontSee('Claimable monitor');

        $this->actingAs($recipient)->get(route('dashboard'))->assertOk()->assertSee('My items');
        $this->get(route('inventory.index', ['view' => 'released']))->assertOk()
            ->assertSee('Claimable monitor')->assertSee('Claim item')
            ->assertSee('id="inventory-claim-modal"', false)
            ->assertSee('name="remarks"', false)
            ->assertSee('name="signature_data"', false)
            ->assertSee('name="photos[]"', false);
        $this->post(route('inventory.claim', $release), [
            ...$this->signature($recipient), 'remarks' => 'Received in good condition.',
        ])->assertSessionHasErrors('photos');
        $this->post(route('inventory.claim', $release), [
            ...$this->signature($staff), 'remarks' => 'Wrong account signature.',
            'photos' => [UploadedFile::fake()->image('wrong-signature.png')],
        ])->assertSessionHasErrors('signature_name');
        $this->post(route('inventory.claim', $release), [
            ...$this->signature($recipient), 'remarks' => 'Received in good condition.',
            'photos' => [UploadedFile::fake()->image('proof.png')],
        ])->assertRedirect(route('inventory.index', ['view' => 'claimed']))->assertSessionHasNoErrors();

        $release->refresh();
        $this->assertSame($recipient->id, $release->claimed_by);
        $this->assertSame('Received in good condition.', $release->claim_remarks);
        $this->assertSame($recipient->name, $release->claim_signature_name);
        $this->assertSame($this->signature($recipient)['signature_data'], $release->claim_signature_data);
        $proof = $release->proofs()->firstOrFail();
        Storage::disk('local')->assertExists($proof->path);
        $this->post(route('inventory.claim', $release), [
            ...$this->signature($recipient), 'remarks' => 'Duplicate claim.',
            'photos' => [UploadedFile::fake()->image('another-proof.png')],
        ])->assertSessionHasErrors('photos');
        $this->assertSame(1, $release->proofs()->count());
        $this->get(route('inventory.proofs.show', $proof))->assertOk();
        $this->actingAs($outsider)->get(route('inventory.proofs.show', $proof))->assertForbidden();
        $this->actingAs($staff)->get(route('inventory.index', ['view' => 'claimed']))
            ->assertOk()->assertSee('Claimable monitor')->assertSee($recipient->name)
            ->assertSee('Received in good condition.')
            ->assertSee(route('inventory.proofs.show', $proof), false);
        $this->get(route('inventory.index'))->assertOk()->assertSee('Activity log')->assertSee($staff->role->name);
        $this->assertDatabaseHas('inventory_activities', [
            'inventory_item_id' => $item->id, 'actor_id' => $recipient->id, 'action' => 'CLAIMED',
        ]);
    }

    public function test_it_staff_records_claim_after_recipient_signs(): void
    {
        Storage::fake('local');
        $staff = $this->user(RoleSlug::ItStaff);
        $recipient = $this->user(RoleSlug::Requestor);
        $item = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $staff->id,
            'description' => 'Signed laptop', 'quantity' => 1, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);
        $this->actingAs($staff)->post(route('inventory.release', $item), [
            ...$this->signature($staff), 'quantity' => 1, 'recipient_user_id' => $recipient->id,
        ])->assertSessionHasNoErrors();
        $release = $item->releases()->firstOrFail();

        $this->post(route('inventory.claim', $release), [
            ...$this->signature($staff), 'remarks' => 'Recipient received the laptop.',
            'photos' => [UploadedFile::fake()->image('laptop.png')],
        ])->assertSessionHasErrors('signature_name');
        $this->post(route('inventory.claim', $release), [
            ...$this->signature($recipient), 'remarks' => 'Recipient received the laptop.',
            'photos' => [UploadedFile::fake()->image('laptop.png')],
        ])->assertRedirect(route('inventory.index', ['view' => 'claimed']))->assertSessionHasNoErrors();

        $release->refresh();
        $this->assertSame($staff->id, $release->claimed_by);
        $this->assertSame($recipient->name, $release->claim_signature_name);
        $this->assertSame(1, $release->proofs()->count());
        $this->assertDatabaseHas('inventory_activities', [
            'inventory_release_id' => $release->id, 'actor_id' => $staff->id, 'action' => 'CLAIMED',
        ]);
        $this->get(route('inventory.index', ['view' => 'claimed']))->assertOk()
            ->assertSee($recipient->name)->assertSee($staff->name)->assertSee('Recipient received the laptop.');
    }

    public function test_inventory_staff_can_link_legacy_release_to_recipient_account(): void
    {
        $staff = $this->user(RoleSlug::InventoryStaff);
        $recipient = $this->user(RoleSlug::Requestor);
        $item = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $staff->id,
            'description' => 'Legacy desk', 'quantity' => 0, 'unit' => 'pc', 'status' => 'RELEASED', 'logged_at' => now(),
        ]);
        $release = $item->releases()->create([
            'released_by' => $staff->id, 'quantity' => 1, 'released_to' => 'Office A', 'released_at' => now(),
        ]);

        $this->actingAs($this->user(RoleSlug::Finance))
            ->patch(route('inventory.link-recipient', $release), ['recipient_user_id' => $recipient->id])
            ->assertForbidden();
        $this->actingAs($staff)->get(route('inventory.index', ['view' => 'released']))
            ->assertOk()->assertSee('No account linked')->assertSee(route('inventory.link-recipient', $release), false);
        $this->patch(route('inventory.link-recipient', $release), ['recipient_user_id' => $recipient->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($recipient->id, $release->fresh()->recipient_user_id);
        $this->assertStringContainsString('Original recipient label: Office A', $release->fresh()->remarks);
        $this->actingAs($recipient)->get(route('inventory.index', ['view' => 'released']))->assertOk()->assertSee('Legacy desk');
    }

    public function test_roles_outside_inventory_cannot_open_or_change_it(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $item = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $requestor->id,
            'description' => 'Locked item', 'quantity' => 1, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);
        $this->actingAs($requestor)->get(route('inventory.index'))->assertForbidden();
        $this->post(route('inventory.store'), ['description' => 'X', 'quantity' => 1, 'unit' => 'pc'])->assertForbidden();
        $this->post(route('inventory.release', $item), ['quantity' => 1, 'released_to' => 'X'])->assertForbidden();
    }

    /** @return array{signature_name: string, signature_data: string} */
    private function signature(User $user): array
    {
        return [
            'signature_name' => $user->name,
            'signature_data' => 'data:image/png;base64,'.base64_encode(str_repeat('signature-stroke', 12)),
        ];
    }

    /** @return array<string, mixed> */
    private function payment(): array
    {
        return [
            'payment_method' => 'ONLINE', 'amount' => 100, 'reference_number' => 'REF-'.$this->sequence, 'paid_at' => now()->toDateString(),
            'account_type' => 'ACA', 'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations',
            'receipts' => [UploadedFile::fake()->image('receipt.jpg')],
        ];
    }

    private function awaitingPayment(): PurchaseRequest
    {
        $this->sequence++;
        $requestor = $this->user(RoleSlug::Requestor);
        $request = PurchaseRequest::query()->create([
            'request_number' => 'RCV'.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT),
            'title' => 'Receiving test request '.$this->sequence,
            'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations',
            'request_type' => 'OTHER',
            'purpose' => 'Receiving and inventory verification',
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
            'status' => PurchaseRequestStatus::ForPayment,
            'current_responsible_role_id' => Role::query()->where('slug', RoleSlug::Finance->value)->value('id'),
            'total_amount' => 100,
        ]);
        $supplier = Supplier::query()->create(['name' => 'Receiving Supplier '.$this->sequence, 'is_active' => true]);
        $order = PurchaseOrder::query()->create([
            'po_number' => 'PO-RCV-'.$this->sequence, 'purchase_request_id' => $request->id, 'supplier_id' => $supplier->id,
            'created_by' => $requestor->id, 'issued_at' => now()->toDateString(), 'status' => 'APPROVED',
            'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        ]);
        $order->items()->createMany([
            ['description' => 'Ergonomic chair', 'quantity' => 4, 'unit' => 'pc', 'unit_price' => 20, 'total_price' => 80],
            ['description' => 'Desk lamp', 'quantity' => 2, 'unit' => 'set', 'unit_price' => 10, 'total_price' => 20],
        ]);
        $budget = BudgetRequest::query()->create([
            'budget_request_number' => 'BR-RCV-'.$this->sequence, 'purchase_request_id' => $request->id,
            'purchase_order_id' => $order->id, 'requested_by' => $requestor->id, 'payment_method' => 'ONLINE',
            'amount' => 100, 'status' => 'PROCEEDER_APPROVED', 'final_approval_status' => 'APPROVED', 'requested_at' => now()->toDateString(),
        ]);
        $budget->allocations()->create(['purchase_request_id' => $request->id, 'purchase_order_id' => $order->id, 'amount' => 100]);

        return $request;
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'department' => 'Operations',
        ]);
    }
}
