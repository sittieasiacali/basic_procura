<?php

namespace Tests\Unit;

use App\Models\SupplierQuotation;
use PHPUnit\Framework\TestCase;

class SupplierQuotationVatTest extends TestCase
{
    public function test_vat_breakdown_handles_exclusive_inclusive_exempt_and_legacy_amounts(): void
    {
        $exclusive = new SupplierQuotation(['price' => 1000, 'vat_treatment' => 'EXCLUSIVE', 'base_amount' => 1000, 'vat_rate' => 12, 'vat_amount' => 120, 'total_amount' => 1120]);
        $inclusive = new SupplierQuotation(['price' => 1120, 'vat_treatment' => 'INCLUSIVE', 'base_amount' => 1000, 'vat_rate' => 12, 'vat_amount' => 120, 'total_amount' => 1120]);
        $exempt = new SupplierQuotation(['price' => 1000, 'vat_treatment' => 'EXEMPT', 'base_amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total_amount' => 1000]);
        $legacy = new SupplierQuotation(['price' => 750]);

        $this->assertSame(['treatment' => 'EXCLUSIVE', 'base' => 1000.0, 'rate' => 12.0, 'vat' => 120.0, 'total' => 1120.0], $exclusive->vatBreakdown());
        $this->assertSame(['treatment' => 'INCLUSIVE', 'base' => 1000.0, 'rate' => 12.0, 'vat' => 120.0, 'total' => 1120.0], $inclusive->vatBreakdown());
        $this->assertSame(['treatment' => 'EXEMPT', 'base' => 1000.0, 'rate' => 0.0, 'vat' => 0.0, 'total' => 1000.0], $exempt->vatBreakdown());
        $this->assertSame(['treatment' => 'EXEMPT', 'base' => 750.0, 'rate' => 0.0, 'vat' => 0.0, 'total' => 750.0], $legacy->vatBreakdown());
    }
}