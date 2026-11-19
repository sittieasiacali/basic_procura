<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_categories', function (Blueprint $table) {
            $table->id();
            $table->enum('team', ['IT', 'PREMISES']);
            $table->string('code', 50);
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['team', 'code']);
        });

        $defaults = [
            'IT' => [
                'COMPUTERS' => 'Computers and laptops',
                'PERIPHERALS' => 'Peripherals and accessories',
                'NETWORK' => 'Network equipment',
                'PRINTERS' => 'Printers and consumables',
                'SOFTWARE' => 'Software and licenses',
                'IT_REPAIR' => 'IT repair and parts',
            ],
            'PREMISES' => [
                'FURNITURE' => 'Furniture and fixtures',
                'OFFICE_SUPPLIES' => 'Office supplies',
                'PANTRY' => 'Pantry and cleaning supplies',
                'FACILITY_REPAIR' => 'Repair and maintenance',
                'ELECTRICAL' => 'Electrical and lighting',
                'SAFETY' => 'Safety and security',
            ],
        ];

        foreach ($defaults as $team => $categories) {
            $sortOrder = 0;
            foreach ($categories as $code => $label) {
                DB::table('request_categories')->insert([
                    'team' => $team, 'code' => $code, 'label' => $label, 'sort_order' => ++$sortOrder,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_categories');
    }
};
