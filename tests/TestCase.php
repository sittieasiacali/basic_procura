<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function signatureImage(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';
    }

    /**
     * The signature fields a user submits with a signed workflow action.
     *
     * @return array{signature_name: string, signature_data: string}
     */
    protected function workflowSignature(User $user): array
    {
        return [
            'signature_name' => $user->name,
            'signature_data' => $this->signatureImage(),
        ];
    }
}
