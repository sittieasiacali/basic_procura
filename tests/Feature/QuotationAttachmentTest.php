<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\SupplierQuotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuotationAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Notification::fake();
    }

    public function test_manual_and_bulk_screening_quotations_require_attachments(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            foreach (['manual', 'bulk'] as $mode) {
                [$actor, $request] = $this->screeningRequest($role);
                $quote = $this->quote($request, $mode);
                unset($quote['attachment']);
                $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [$quote],
                ])->assertSessionHasErrors('quotations.0.attachment');
                $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
                $this->assertSame(0, $request->quotations()->count());
                $this->assertSame([], Storage::disk('local')->allFiles());
            }
        }
    }

    public function test_manual_and_bulk_uploads_are_saved_and_protected_by_request_permissions(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            foreach (['manual', 'bulk'] as $mode) {
                [$actor, $request] = $this->screeningRequest($role);
                $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()
                    ->assertSee('enctype="multipart/form-data"', false)
                    ->assertSee('name="quotations[0][attachment]"', false);
                // Gaps occur when an earlier supplier row is removed or left blank.
                $this->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [0 => ['supplier_name' => ''], 3 => $this->quote($request, $mode)],
                ])->assertSessionHasNoErrors();
                $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
                $quotations = $request->quotations()->get();
                $this->assertCount($mode === 'bulk' ? 2 : 1, $quotations);
                foreach ($quotations as $quotation) {
                    $attachment = $quotation->attachments()->sole();
                    $this->assertSame('quotation.pdf', $attachment->original_name);
                    Storage::disk('local')->assertExists($attachment->path);
                    $this->assertSame(route('attachments.download', $attachment), $quotation->quotationDestination());
                    $this->actingAs($actor)->get(route('attachments.download', $attachment))->assertOk();
                    $this->get(route('attachments.view', $attachment))->assertOk();
                    $this->actingAs($this->user(RoleSlug::Requestor))
                        ->get(route('attachments.download', $attachment))->assertForbidden();
                }
                $this->actingAs($request->requestor)->get(route('purchase-requests.show', $request))->assertOk()
                    ->assertSee('quotation.pdf');
            }
        }
    }

    public function test_invalid_or_oversized_quotation_attachments_are_rejected(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        foreach ([
            UploadedFile::fake()->create('script.html', 1, 'text/html'),
            UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
        ] as $file) {
            $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => 'manual', 'quotations' => [[...$this->quote($request), 'attachment' => $file]],
            ])->assertSessionHasErrors('quotations.0.attachment');
        }
        $this->assertSame(0, $request->quotations()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_canvassing_requires_and_saves_attachments_in_manual_and_bulk_modes(): void
    {
        foreach ([RoleSlug::PurchasingTeam, RoleSlug::CanvassingStaff] as $role) {
            foreach (['manual', 'bulk'] as $mode) {
                [, $request] = $this->screeningRequest(RoleSlug::ItStaff);
                $request->update(['status' => PurchaseRequestStatus::ForCanvassing]);
                $actor = $this->user($role);
                $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()
                    ->assertSee('name="quotations[0][attachment]"', false)
                    ->assertSee('Quotation attachment');

                $quote = [...$this->quote($request, $mode), 'recommended' => 1];
                $withoutFile = $quote;
                unset($withoutFile['attachment']);
                $this->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [$withoutFile],
                ])->assertSessionHasErrors('quotations.0.attachment');
                $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
                $this->assertSame(0, $request->quotations()->count());

                $this->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [$quote],
                ])->assertSessionHasNoErrors();
                $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
                $quotations = $request->quotations()->get();
                $this->assertCount($mode === 'bulk' ? 2 : 1, $quotations);
                foreach ($quotations as $quotation) {
                    $attachment = $quotation->attachments()->sole();
                    Storage::disk('local')->assertExists($attachment->path);
                    $this->assertSame('quotation.pdf', $attachment->original_name);
                    $this->assertTrue($quotation->is_recommended);
                }
            }
        }
    }

    public function test_multiple_suppliers_keep_their_own_uploaded_documents(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'manual',
            'quotations' => [
                2 => [...$this->quote($request), 'supplier_name' => 'First supplier',
                    'attachment' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf')],
                7 => [...$this->quote($request), 'supplier_name' => 'Second supplier',
                    'attachment' => UploadedFile::fake()->image('second.png')],
            ],
        ])->assertSessionHasNoErrors();
        foreach (['First supplier' => 'first.pdf', 'Second supplier' => 'second.png'] as $name => $filename) {
            $quotation = $request->quotations()->whereHas('supplier', fn ($query) => $query->where('name', $name))->sole();
            $this->assertSame($filename, $quotation->attachments()->sole()->original_name);
        }
    }

    public function test_a_file_only_row_is_validated_instead_of_silently_skipped(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'manual', 'confirmed_without_quotation' => 1,
            'quotations' => [5 => ['attachment' => UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf')]],
        ])->assertSessionHasErrors('quotations.5.supplier_name');
        $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
    }

    private function screeningRequest(RoleSlug $role): array
    {
        $actor = $this->user($role);
        $owner = $this->user(RoleSlug::Requestor);
        $request = PurchaseRequest::create([
            'request_number' => 'REQ-ATTACH-'.$actor->id, 'title' => 'Attachment test',
            'requestor_id' => $owner->id, 'department_campaign' => 'Operations',
            'purpose' => 'Quotation upload', 'required_date' => now()->addWeek(),
            'delivery_location' => SupplierQuotation::DELIVERY_LOCATIONS[0],
            'status' => PurchaseRequestStatus::ForProcurement,
            'request_type' => $role === RoleSlug::ItStaff ? 'IT' : 'PREMISES', 'assigned_user_id' => $actor->id,
        ]);
        $request->screening()->create([
            'screened_by' => $actor->id, 'team' => $request->request_type, 'decision' => 'UNAVAILABLE', 'decided_at' => now(),
        ]);
        foreach (['Laptop', 'Monitor'] as $description) {
            $request->items()->create(['description' => $description, 'quantity' => 1, 'unit' => 'pc']);
        }

        return [$actor, $request];
    }

    private function quote(PurchaseRequest $request, string $mode = 'manual'): array
    {
        return [
            'supplier_name' => 'Supplier '.$request->id, 'quoted_amount' => 100,
            'vat_treatment' => 'EXEMPT', 'delivery_lead_time' => '3 days',
            'delivery_location' => SupplierQuotation::DELIVERY_LOCATIONS[0], 'warranty' => '1 year',
            'quotation_url' => 'https://supplier.example/quotation', 'bank_details' => 'Supplier bank details',
            'attachment' => UploadedFile::fake()->create('quotation.pdf', 20, 'application/pdf'),
            ...($mode === 'bulk' ? ['items' => $request->items->map(fn ($item) => [
                'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 50,
            ])->all()] : []),
        ];
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }
}
