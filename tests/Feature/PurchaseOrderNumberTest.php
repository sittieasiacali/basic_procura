<?php

namespace Tests\Feature;

use App\Support\PurchaseOrderNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_purchase_order_numbers_are_reserved_once_in_sequence(): void
    {
        [$first, $second] = DB::transaction(fn (): array => [
            PurchaseOrderNumber::reserve(),
            PurchaseOrderNumber::reserve(),
        ]);

        $this->assertSame('ASI004611', $first);
        $this->assertSame('ASI004612', $second);
        $this->assertNotSame($first, $second);
        $this->assertDatabaseHas('procurement_sequences', [
            'name' => 'purchase_order',
            'current_value' => 4612,
        ]);
    }
}