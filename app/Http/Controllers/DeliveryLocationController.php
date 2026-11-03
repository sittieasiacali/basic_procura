<?php

namespace App\Http\Controllers;

use App\Models\DeliveryLocation;
use App\Models\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryLocationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', DeliveryLocation::class);

        return response()->json(['locations' => $this->locations()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', DeliveryLocation::class);
        $name = $this->validatedName($request);

        $location = DB::transaction(function () use ($name) {
            $existing = DeliveryLocation::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->lockForUpdate()->first();

            if ($existing && ! $existing->trashed()) {
                throw ValidationException::withMessages(['name' => 'This location already exists.']);
            }

            if ($existing) {
                $existing->restore();
                $existing->forceFill(['name' => $name, 'is_active' => true])->save();

                return $existing;
            }

            return DeliveryLocation::query()->create(['name' => $name, 'is_active' => true]);
        });

        return response()->json(['message' => 'Location added.', 'location' => $this->present($location)], 201);
    }

    public function update(Request $request, DeliveryLocation $deliveryLocation): JsonResponse
    {
        $this->authorize('update', $deliveryLocation);
        $name = $this->validatedName($request, $deliveryLocation);
        $deliveryLocation->update(['name' => $name]);

        return response()->json(['message' => 'Location updated. Historical requests keep their saved location value.', 'location' => $this->present($deliveryLocation)]);
    }

    public function destroy(DeliveryLocation $deliveryLocation): JsonResponse
    {
        $this->authorize('delete', $deliveryLocation);
        $references = PurchaseRequest::query()->where('delivery_location', $deliveryLocation->name)->count();

        if ($references > 0) {
            $deliveryLocation->forceFill(['is_active' => false])->save();

            return response()->json([
                'message' => "This location is used by {$references} request(s), so it was archived instead of deleted.",
                'archived' => true,
            ]);
        }

        $deliveryLocation->delete();

        return response()->json(['message' => 'Unused location deleted.']);
    }

    private function validatedName(Request $request, ?DeliveryLocation $ignore = null): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name')));
        $request->merge(['name' => $name]);
        $request->validate(['name' => ['required', 'string', 'max:255']]);

        $duplicate = DeliveryLocation::withTrashed()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignore, fn ($query) => $query->where('id', '<>', $ignore->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'This location already exists.']);
        }

        return $name;
    }

    private function locations(): array
    {
        return DeliveryLocation::query()->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (DeliveryLocation $location) => $this->present($location))->all();
    }

    private function present(DeliveryLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'is_active' => $location->is_active,
            'references' => PurchaseRequest::query()->where('delivery_location', $location->name)->count(),
        ];
    }
}
