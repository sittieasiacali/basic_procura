<?php

return [
    // Temporarily disabled until Budget Request preparation needs these again.
    'budget_preparation' => [
        'additional_information' => false,
        'signature_required' => false,
    ],
    'purchase_order_number' => [
        'prefix' => env('PROCUREMENT_PO_PREFIX', 'ASI'),
        'padding' => 6,
    ],
    'company' => [
        'address' => env('PROCUREMENT_COMPANY_ADDRESS', 'Seneca Plaza, E. Rodriguez Sr. Ave, New Manila, Quezon City, Metro Manila'),
        'logo' => 'assets/images/Allianz_logo.png',
    ],
    /* Optional printed labels; recorded transaction actors are the default. */
    'budget_request_signatories' => [
        'requested_by' => env('PROCUREMENT_BR_REQUESTED_BY'),
        'approved_by' => env('PROCUREMENT_BR_APPROVED_BY'),
    ],
    /* The verified spreadsheet layout is shared by the saved transaction options. */
    'purchase_order_templates' => [
        'transactions' => [
            'ONLINE' => 'bank-deposit-spreadsheet',
            'CASH' => 'bank-deposit-spreadsheet',
            'CHECK' => 'bank-deposit-spreadsheet',
        ],
    ],
    /* System Health page and daily alert (php artisan health:check --notify). */
    'health' => [
        'stuck_days' => (int) env('HEALTH_STUCK_DAYS', 3),
        'alert_email' => env('HEALTH_ALERT_EMAIL') ?: null,
    ],
    'units' => [
        'pc' => 'Piece (pc)', 'set' => 'Set (set)', 'pair' => 'Pair (pair)', 'pack' => 'Pack (pack)',
        'box' => 'Box (box)', 'ream' => 'Ream (ream)', 'roll' => 'Roll (roll)', 'bottle' => 'Bottle (bottle)',
        'can' => 'Can (can)', 'bag' => 'Bag (bag)', 'sack' => 'Sack (sack)', 'cartridge' => 'Cartridge (cartridge)',
        'm' => 'Meter (m)', 'm²' => 'Square Meter (m²)', 'kg' => 'Kilogram (kg)', 'g' => 'Gram (g)',
        'L' => 'Liter (L)', 'mL' => 'Milliliter (mL)', 'lot' => 'Lot (lot)', 'service' => 'Service (service)',
    ],
];
