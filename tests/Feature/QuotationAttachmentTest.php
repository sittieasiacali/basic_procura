<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
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

    public function test_manual_and_bulk_screening_quotations_allow_missing_files_urls_and_lead_time(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            foreach (['manual', 'bulk'] as $mode) {
                [$actor, $request] = $this->screeningRequest($role);
                $quote = $this->quote($request, $mode);
                unset($quote['attachment'], $quote['quotation_url'], $quote['delivery_lead_time']);
                $quote['shipping_fee'] = 25;
                $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [$quote],
                ])->assertSessionHasNoErrors();
                $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
                $this->assertSame($mode === 'bulk' ? 2 : 1, $request->quotations()->count());
                $this->assertEquals(25, $request->quotations()->sum('shipping_fee'));
                $this->assertEquals(125, $request->quotations()->get()->sum(fn ($quote) => $quote->vatBreakdown()['total']));
                $this->assertSame($role === RoleSlug::ItStaff ? 'IT' : 'PREMISES', $request->quotations()->first()->supplier->team);
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
                    ->assertSee('name="quotations[0][attachment][]"', false);
                // Gaps occur when an earlier supplier row is removed or left blank.
                $this->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [0 => ['supplier_name' => '', 'attachment' => [], 'email_evidence' => []], 3 => $this->quote($request, $mode)],
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

    public function test_canvassing_allows_optional_attachments_and_saves_uploaded_files(): void
    {
        foreach ([RoleSlug::PurchasingTeam, RoleSlug::CanvassingStaff] as $role) {
            foreach (['manual', 'bulk'] as $mode) {
                [, $request] = $this->screeningRequest(RoleSlug::ItStaff);
                $request->update(['status' => PurchaseRequestStatus::ForCanvassing]);
                $actor = $this->user($role);
                $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()
                    ->assertSee('name="quotations[0][attachment][]"', false)
                    ->assertSee('Quotation attachment');

                $quote = [...$this->quote($request, $mode), 'recommended' => 1];
                $withoutFile = $quote;
                unset($withoutFile['attachment'], $withoutFile['quotation_url'], $withoutFile['delivery_lead_time']);
                $this->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [$withoutFile],
                ])->assertSessionHasNoErrors();
                $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
                $this->assertSame($mode === 'bulk' ? 2 : 1, $request->quotations()->count());

                [, $request] = $this->screeningRequest(RoleSlug::ItStaff);
                $request->update(['status' => PurchaseRequestStatus::ForCanvassing]);
                $quote = [...$this->quote($request, $mode), 'recommended' => 1];
                $this->post(route('canvassing.quotations.store', $request), [
                    'quotation_mode' => $mode, 'quotations' => [$quote],
                ])->assertRedirect()->assertSessionHasNoErrors();
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

    public function test_quotation_suggestions_and_supplier_matching_stay_in_the_active_team(): void
    {
        $suppliers = [];
        foreach (Supplier::TEAMS as $team => $label) {
            $suppliers[$team] = Supplier::create([
                'team' => $team, 'name' => 'Existing vendor', 'contact_person' => $label.' private contact', 'bank_details' => $label.' private bank', 'is_active' => true,
            ]);
        }
        foreach (['it-staff' => 'IT', 'premises-staff' => 'PREMISES', 'purchasing-team' => 'PURCHASING', 'admin' => 'IT'] as $role => $team) {
            [$actor, $request] = $this->screeningRequest($team === 'PREMISES' ? RoleSlug::PremisesStaff : RoleSlug::ItStaff);
            if ($role === 'admin') {
                $actor = $this->user(RoleSlug::Admin);
            }
            if ($team === 'PURCHASING') {
                $actor = $this->user(RoleSlug::PurchasingTeam);
                $request->update(['status' => PurchaseRequestStatus::ForCanvassing]);
            }
            $response = $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()
                ->assertSee($suppliers[$team]->fresh()->quotationDefaults()['bank_details'])->assertDontSee('Saved supplier details');
            foreach (array_diff(array_keys(Supplier::TEAMS), [$team]) as $otherTeam) {
                $response->assertDontSee(Supplier::TEAMS[$otherTeam].' private contact')->assertDontSee(Supplier::TEAMS[$otherTeam].' private bank');
            }
            $quote = [...$this->quote($request), 'supplier_name' => 'Existing vendor', 'recommended' => 1];
            $this->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => 'manual', 'quotations' => [$quote],
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($suppliers[$team]->id, $request->quotations()->sole()->supplier_id);
        }
    }

    public function test_supplier_picker_provides_quotation_details_and_uses_the_selected_record(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $supplier = Supplier::create([
            'team' => 'IT', 'name' => 'Saved IT vendor', 'contact_person' => 'Supplier contact',
            'email' => 'sales@vendor.test', 'phone' => '555-1234', 'address' => 'Quezon City',
            'tax_id' => 'TIN-123', 'bank_details' => "Bank: BDO\nAccount: 123456", 'is_active' => true,
        ]);
        $page = $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('data-supplier-picker', false)->assertDontSee('list="active-suppliers"', false);
        preg_match('/<script type="application\/json" data-supplier-directory>(.*?)<\/script>/s', $page->getContent(), $matches);
        $directory = collect(json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR));
        $saved = $directory->firstWhere('id', $supplier->id);
        $this->assertSame($supplier->bank_details, $saved['bank_details']);
        $this->assertArrayNotHasKey('contact_person', $saved);
        $this->assertArrayNotHasKey('address', $saved);
        $page->assertDontSee('Saved supplier details');
        $quote = [...$this->quote($request), 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'bank_details' => 'Quotation-specific instructions'];
        $this->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => [$quote]])->assertRedirect()->assertSessionHasNoErrors();
        $stored = $request->quotations()->sole();
        $this->assertSame($supplier->id, $stored->supplier_id);
        $this->assertSame('Quotation-specific instructions', $stored->bank_details);
        $this->assertSame("Bank: BDO\nAccount: 123456", $supplier->fresh()->bank_details);
    }

    public function test_selected_supplier_ids_cannot_bypass_team_or_availability_rules(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $foreign = Supplier::create(['team' => 'PREMISES', 'name' => 'Foreign vendor', 'is_active' => true]);
        $inactive = Supplier::create(['team' => 'IT', 'name' => 'Inactive vendor', 'is_active' => false]);
        $deleted = Supplier::create(['team' => 'IT', 'name' => 'Deleted vendor', 'is_active' => true]);
        $deleted->delete();
        $this->actingAs($actor);
        foreach ([$foreign, $inactive, $deleted] as $supplier) {
            $quote = [...$this->quote($request), 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name];
            $this->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => [$quote]])->assertSessionHasErrors('quotations.0.supplier_id');
        }
        $own = Supplier::create(['team' => 'IT', 'name' => 'Actual supplier', 'is_active' => true]);
        $quote = [...$this->quote($request), 'supplier_id' => $own->id, 'supplier_name' => 'Different supplier'];
        $this->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => [$quote]])->assertSessionHasErrors('quotations');
        $this->assertSame(0, $request->quotations()->count());
    }

    public function test_supplier_defaults_reuse_the_latest_saved_and_edited_quotation(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $supplier = Supplier::create(['team' => 'IT', 'name' => 'Reusable vendor', 'bank_details' => 'Original master bank', 'is_active' => true]);
        $quotation = $supplier->quotations()->create([
            'purchase_request_id' => $request->id, 'created_by' => $actor->id, 'price' => 100,
            'item_or_service' => 'Laptop', 'quoted_at' => now(), 'bank_details' => 'Bank entered on quotation',
            'warranty' => 'Two years', 'shipping_fee' => 150, 'vat_treatment' => 'INCLUSIVE',
            'quotation_url' => 'https://vendor.test/quote', 'delivery_location' => SupplierQuotation::DELIVERY_LOCATIONS[0],
        ]);
        $older = $supplier->quotations()->create([
            'purchase_request_id' => $request->id, 'created_by' => $actor->id, 'price' => 200,
            'item_or_service' => 'Other item', 'quoted_at' => now(), 'bank_details' => 'Older quotation bank',
        ]);
        $older->forceFill(['updated_at' => now()->subDay()])->save();
        $page = $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()->assertDontSee('Saved supplier details');
        preg_match('/<script type="application\/json" data-supplier-directory>(.*?)<\/script>/s', $page->getContent(), $matches);
        $saved = collect(json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR))->firstWhere('id', $supplier->id);
        $this->assertSame('Bank entered on quotation', $saved['bank_details']);
        $this->assertSame('Two years', $saved['warranty']);
        $this->assertSame('150.00', $saved['shipping_fee']);
        $this->assertSame('INCLUSIVE', $saved['vat_treatment']);
        $this->assertSame('https://vendor.test/quote', $saved['quotation_url']);
        $this->assertSame(SupplierQuotation::DELIVERY_LOCATIONS[0], $saved['delivery_location']);
        $this->assertArrayNotHasKey('price', $saved);
        $quotation->update(['bank_details' => 'Edited bank details']);
        $this->assertSame('Edited bank details', $supplier->fresh()->quotationDefaults()['bank_details']);
        $this->assertSame('Original master bank', $supplier->fresh()->bank_details);
    }

    public function test_multiple_quotation_files_and_email_evidence_are_saved_for_both_screening_teams(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            [$actor, $request] = $this->screeningRequest($role);
            $quote = [...$this->quote($request), 'attachment' => [
                UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('second.png'),
            ], 'email_evidence' => [UploadedFile::fake()->image('email.png'), UploadedFile::fake()->create('email.pdf', 10, 'application/pdf')]];
            $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => [$quote]])->assertSessionHasNoErrors();
            $quotation = $request->quotations()->sole();
            $this->assertSame(2, $quotation->attachments()->where('category', 'supplier_quotation')->count());
            $this->assertSame(2, $quotation->attachments()->where('category', 'supplier_email_evidence')->count());
            foreach ($quotation->attachments as $attachment) {
                Storage::disk('local')->assertExists($attachment->path);
                $this->get(route('attachments.view', $attachment))->assertOk();
            }
        }
    }

    public function test_screening_email_and_supporting_evidence_accept_multiple_files(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            [$actor, $request] = $this->screeningRequest($role);
            $request->screening()->delete();
            $request->update(['status' => $role === RoleSlug::ItStaff ? PurchaseRequestStatus::ForItScreening : PurchaseRequestStatus::ForPremisesScreening]);
            $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('name="email_evidence[]"', false);
            $this->post(route('request-flow.screening.store', $request), [
                'decision' => 'UNAVAILABLE', ...$this->workflowSignature($actor),
                'email_evidence' => [UploadedFile::fake()->image('email-one.png'), UploadedFile::fake()->image('email-two.png'), UploadedFile::fake()->create('approval.eml', 1, 'message/rfc822')],
                'quotation_evidence' => [UploadedFile::fake()->create('one.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('two.pdf', 10, 'application/pdf')],
            ])->assertSessionHasNoErrors();
            $screening = $request->fresh()->screening;
            $this->assertSame(5, $screening->attachments()->count());
            $this->assertSame(3, $screening->attachments()->where('category', 'screening_email_evidence')->count());
            foreach ($screening->attachments as $attachment) {
                Storage::disk('local')->assertExists($attachment->path);
            }
        }
    }

    public function test_previous_amount_shipping_and_files_can_be_reused_without_uploading_again(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $quote = [...$this->quote($request), 'shipping_fee' => 25, 'attachment' => [UploadedFile::fake()->image('quote.png'), UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')]];
        $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => [$quote]])->assertSessionHasNoErrors();
        $request->update(['status' => PurchaseRequestStatus::ForProcurement]);
        $quotation = $request->quotations()->sole();
        $defaults = $quotation->supplier->quotationDefaults($actor);
        $this->assertSame(100.0, $defaults['quoted_amount']);
        $this->assertSame('25.00', $defaults['shipping_fee']);
        $this->assertCount(2, $defaults['attachments']);
        [, $next] = $this->screeningRequest(RoleSlug::ItStaff);
        $next->update(['assigned_user_id' => $actor->id]);
        $next->screening()->update(['screened_by' => $actor->id]);
        $reuse = [...$this->quote($next), 'supplier_name' => $quotation->supplier->name, 'supplier_id' => $quotation->supplier_id,
            'attachment' => null, 'reused_attachment_ids' => array_column($defaults['attachments'], 'id')];
        $this->post(route('canvassing.quotations.store', $next), ['quotation_mode' => 'manual', 'quotations' => [$reuse]])->assertSessionHasNoErrors();
        $this->assertSame(2, $next->quotations()->sole()->attachments()->count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $foreignSupplier = Supplier::create(['team' => 'PREMISES', 'name' => 'Foreign reuse vendor']);
        $foreignQuotation = $request->quotations()->create(['supplier_id' => $foreignSupplier->id, 'created_by' => $actor->id, 'price' => 100, 'item_or_service' => 'Other quotation', 'quoted_at' => now()]);
        $foreignAttachment = $foreignQuotation->attachments()->create(['uploaded_by' => $actor->id, 'category' => 'supplier_quotation', 'original_name' => 'quote.png', 'disk' => 'local', 'path' => $quotation->attachments->first()->path, 'mime_type' => 'image/png', 'size' => 10]);
        $next->refresh()->update(['status' => PurchaseRequestStatus::ForProcurement]);
        $this->post(route('canvassing.quotations.store', $next), ['quotation_mode' => 'manual', 'quotations' => [[...$reuse, 'reused_attachment_ids' => [$foreignAttachment->id]]]])->assertStatus(302)->assertSessionHasErrors('quotations');
        $this->assertSame(2, $next->quotations()->sole()->attachments()->count());
    }

    public function test_invalid_files_inside_a_multiple_upload_are_rejected(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $quote = [...$this->quote($request), 'attachment' => [UploadedFile::fake()->image('valid.png'), UploadedFile::fake()->create('script.html', 1, 'text/html')]];
        $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => [$quote]])->assertSessionHasErrors('quotations.0.attachment');
        $this->assertSame(0, $request->quotations()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_screening_handoff_requires_valid_priority_and_saves_it_atomically(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            [$actor, $request] = $this->screeningRequest($role);
            $request->update(['priority_level' => null]);
            $payload = ['quotation_mode' => 'manual', 'quotations' => [$this->quote($request)]];
            $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), $payload)
                ->assertSessionHasErrors('priority_level');
            $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
            $this->assertSame(0, $request->quotations()->count());
            $this->post(route('canvassing.quotations.store', $request), [...$payload, 'priority_level' => 'INVALID'])
                ->assertSessionHasErrors('priority_level');
            $supplier = Supplier::create(['name' => 'Exact supplier '.$actor->id, 'team' => $request->request_type, 'is_active' => true]);
            $mismatched = $this->quote($request);
            $mismatched['supplier_id'] = $supplier->id;
            $this->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => 'manual', 'quotations' => [$mismatched], 'priority_level' => 'URGENT',
            ])->assertSessionHasErrors('quotations');
            $this->assertNull($request->fresh()->priority_level);
            $this->assertSame(0, $request->quotations()->count());
            $this->post(route('canvassing.quotations.store', $request), [...$payload, 'priority_level' => 'URGENT'])
                ->assertSessionHasNoErrors();
            $this->assertSame('URGENT', $request->fresh()->priority_level);
            $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
        }
    }

    public function test_empty_screening_handoff_also_requires_priority(): void
    {
        [$actor, $request] = $this->screeningRequest(RoleSlug::ItStaff);
        $request->update(['priority_level' => null]);
        $payload = ['quotation_mode' => 'manual', 'quotations' => [], 'confirmed_without_quotation' => 1];
        $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), $payload)
            ->assertSessionHasErrors('priority_level');
        $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
        $this->post(route('canvassing.quotations.store', $request), [...$payload, 'priority_level' => 'NORMAL'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
    }

    public function test_screening_receipt_does_not_block_the_same_users_separate_po_canvassing_task(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            [$actor, $request] = $this->screeningRequest($role);
            $purchasingRole = Role::where('slug', RoleSlug::PurchasingTeam->value)->firstOrFail();
            $actor->roles()->attach($purchasingRole);
            $screeningQuote = $this->quote($request);
            unset($screeningQuote['attachment']);
            $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => 'manual', 'quotations' => [$screeningQuote],
            ])->assertSessionHasNoErrors();
            $original = $request->quotations()->sole();
            $this->assertSame($request->request_type, $original->supplier->team);
            $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
            $this->get(route('purchase-requests.show', $request))->assertOk()
                ->assertSee('Your quotation stage is closed')->assertDontSee('data-quotation-form data-request-code', false);

            $this->withSession(['workspace_role_id' => $purchasingRole->id])
                ->get(route('purchase-requests.show', $request))->assertOk()
                ->assertSee('data-quotation-form', false)->assertSee('Proceed to President review')
                ->assertDontSee('Your quotation stage is closed')
                ->assertSee($role === RoleSlug::ItStaff ? 'IT quotation' : 'Premises quotation');
            $this->patch(route('canvassing.quotations.update', [$request, $original]), [
                ...$screeningQuote, 'quoted_amount' => 9999,
            ])->assertForbidden();
            $poQuote = [...$screeningQuote, 'quoted_amount' => 5756, 'recommended' => 1, 'bank_details' => 'PO supplier bank'];
            $this->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => 'manual', 'quotations' => [$poQuote],
            ])->assertSessionHasNoErrors();
            $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
            $this->assertSame(2, $request->quotations()->count());
            $this->assertSame('100.00', $original->fresh()->price);
            $this->assertSame($screeningQuote['bank_details'], $original->fresh()->bank_details);
            $canvassing = $request->quotations()->whereKeyNot($original->id)->sole();
            $this->assertSame('PURCHASING', $canvassing->supplier->team);
            $this->assertSame('5756.00', $canvassing->price);
            $this->assertTrue($canvassing->is_recommended);
            $this->assertFalse($original->fresh()->is_recommended);
            $html = view('purchase-requests.partials.quotation-modals', [
                'quotations' => collect([$canvassing]), 'purchaseRequest' => $request->fresh(),
            ])->render();
            $this->assertStringContainsString('5,756.00', $html);
            $this->assertStringContainsString('Non-VAT', $html);
            $this->assertStringContainsString('PO Canvassing quotation', $html);
            $this->assertStringNotContainsString("\u{00E2}\u{20AC}", $html);
            $this->get(route('purchase-requests.show', $request))->assertOk()
                ->assertSee('Your canvassing stage is closed')->assertDontSee('data-quotation-form data-request-code', false);
            $this->withSession(['workspace_role_id' => $actor->role_id])
                ->get(route('purchase-requests.show', $request))->assertOk()->assertDontSee('data-quotation-form data-request-code', false);
        }
    }

    private function screeningRequest(RoleSlug $role): array
    {
        $actor = $this->user($role);
        $owner = $this->user(RoleSlug::Requestor);
        $request = PurchaseRequest::create([
            'request_number' => 'REQ-ATTACH-'.$actor->id, 'title' => 'Attachment test',
            'requestor_id' => $owner->id, 'department_campaign' => 'Operations',
            'priority_level' => 'HIGH', 'purpose' => 'Quotation upload', 'required_date' => now()->addWeek(),
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
