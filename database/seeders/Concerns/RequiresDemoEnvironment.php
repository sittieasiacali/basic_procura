<?php

namespace Database\Seeders\Concerns;

trait RequiresDemoEnvironment
{
    private function requireDemoEnvironment(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo seeders are restricted to local and testing environments.');
        }
    }
}
