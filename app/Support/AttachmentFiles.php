<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;

class AttachmentFiles implements ValidationRule
{
    public function __construct(private array $types, private int $maxKilobytes = 5120, private int $maxFiles = 10) {}

    public static function files(mixed $value): array
    {
        return is_array($value) ? array_values($value) : ($value instanceof UploadedFile ? [$value] : []);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) && ! $value instanceof UploadedFile) {
            $fail('Select one or more files.');

            return;
        }
        $validator = Validator::make(['files' => self::files($value)], [
            'files' => ['array', 'max:'.$this->maxFiles],
            'files.*' => [File::types($this->types)->max($this->maxKilobytes)],
        ]);
        if ($validator->fails()) {
            $fail($validator->errors()->first());
        }
    }
}
