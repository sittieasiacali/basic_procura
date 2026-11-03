<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkflowSignature
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'signature_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['required', 'string', 'max:500000'],
        ];
    }

    /** @param array<string, mixed> $validated */
    public static function assertValid(Request $request, array $validated): void
    {
        self::assertValidForSigner($validated, (string) $request->user()?->name,
            'This signature does not belong to the signed-in account.');
    }

    /** @param array<string, mixed> $validated */
    public static function assertValidForSigner(array $validated, string $expectedName, string $nameError = 'This signature does not match the recipient account.'): void
    {
        $signedName = preg_replace('/\s+/u', ' ', trim((string) ($validated['signature_name'] ?? ''))) ?? '';
        $accountName = preg_replace('/\s+/u', ' ', trim($expectedName)) ?? '';

        if (mb_strtolower($signedName) !== mb_strtolower($accountName)) {
            throw ValidationException::withMessages([
                'signature_name' => $nameError,
            ]);
        }

        $signature = (string) ($validated['signature_data'] ?? '');
        $prefix = 'data:image/png;base64,';
        $encoded = str_starts_with($signature, $prefix) ? substr($signature, strlen($prefix)) : false;
        $image = $encoded === false ? false : base64_decode($encoded, true);

        if ($image === false || strlen($image) < 64) {
            throw ValidationException::withMessages([
                'signature_data' => 'Draw your signature before confirming this decision.',
            ]);
        }
    }

    /**
     * Crops the empty canvas margin around a drawn signature so it fills the
     * signature box on documents. Signatures drawn on a wide pad would
     * otherwise be scaled down until the strokes are barely visible.
     */
    public static function forDisplay(?string $signature): ?string
    {
        $prefix = 'data:image/png;base64,';
        if ($signature === null || ! str_starts_with($signature, $prefix) || ! function_exists('imagecreatefromstring')) {
            return $signature;
        }

        $binary = base64_decode(substr($signature, strlen($prefix)), true);
        $image = $binary === false ? false : @imagecreatefromstring($binary);
        if ($image === false) {
            return $signature;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        [$minX, $minY, $maxX, $maxY] = [$width, $height, -1, -1];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 120) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < 0) {
            return $signature;
        }

        $padding = 4;
        $minX = max(0, $minX - $padding);
        $minY = max(0, $minY - $padding);
        $cropWidth = min($width, $maxX + $padding + 1) - $minX;
        $cropHeight = min($height, $maxY + $padding + 1) - $minY;
        if ($cropWidth === $width && $cropHeight === $height) {
            return $signature;
        }

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagefill($cropped, 0, 0, imagecolorallocatealpha($cropped, 0, 0, 0, 127));
        imagecopy($cropped, $image, 0, 0, $minX, $minY, $cropWidth, $cropHeight);

        ob_start();
        imagepng($cropped);

        return $prefix.base64_encode((string) ob_get_clean());
    }
}
