<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthCheck;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthCheck $health): View
    {
        $checks = $health->run();

        return view('admin.system-health', [
            'checks' => $checks,
            'overall' => SystemHealthCheck::overall($checks),
            'checkedAt' => now(),
        ]);
    }
}
