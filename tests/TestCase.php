<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The signature fields a user submits with a signed workflow action.
     *
     * @return array{signature_name: string, signature_data: string}
     */
    protected function workflowSignature(User $user): array
    {
        return [
            'signature_name' => $user->name,
            'signature_data' => 'data:image/png;base64,'.base64_encode(str_repeat('signature-stroke', 12)),
        ];
    }
}
