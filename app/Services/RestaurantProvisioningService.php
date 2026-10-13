<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RestaurantProvisioningService
{
    /**
     * Provision a new restaurant and its initial owner account atomically.
     *
     * @return array{restaurant: Restaurant, owner: User}
     *
     * @throws ValidationException
     */
    public function provision(array $restaurantData, array $ownerData): array
    {
        $ownerData['email'] = mb_strtolower(trim((string) ($ownerData['email'] ?? '')));
        $this->validateData($restaurantData, $ownerData);

        return DB::transaction(function () use ($restaurantData, $ownerData) {
            $restaurantAttributes = [
                'name' => $restaurantData['name'],
                'slug' => Str::slug($restaurantData['slug'] ?? $restaurantData['name']),
                'email' => $restaurantData['email'],
                'phone' => $restaurantData['phone'] ?? null,
                'address' => $restaurantData['address'] ?? null,
                'status' => $restaurantData['status'] ?? 'active',
                'approval_status' => $restaurantData['approval_status'] ?? 'approved',
            ];
            $restaurant = Restaurant::create($restaurantAttributes);

            $owner = User::create([
                'restaurant_id' => $restaurant->id,
                'name' => $ownerData['name'],
                'email' => $ownerData['email'],
                'password' => Hash::make(Str::random(64)),
                'role' => 'owner',
                'status' => $ownerData['status'] ?? 'active',
            ]);

            return [
                'restaurant' => $restaurant,
                'owner' => $owner,
            ];
        });
    }

    /**
     * Validate provisioning input data.
     *
     * @throws ValidationException
     */
    protected function validateData(array $restaurantData, array $ownerData): void
    {
        $rules = [
            'restaurant.name' => ['required', 'string', 'max:255'],
            'restaurant.slug' => ['nullable', 'string', 'max:255', 'unique:restaurants,slug'],
            'restaurant.email' => ['required', 'email', 'max:255', 'unique:restaurants,email'],
            'restaurant.phone' => ['nullable', 'string', 'max:50'],
            'restaurant.address' => ['nullable', 'string', 'max:500'],
            'restaurant.status' => ['nullable', 'in:active,suspended,inactive'],
            'restaurant.approval_status' => ['nullable', 'in:pending,approved'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner.status' => ['nullable', 'in:active,inactive'],
        ];

        $validator = Validator::make([
            'restaurant' => $restaurantData,
            'owner' => $ownerData,
        ], $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
