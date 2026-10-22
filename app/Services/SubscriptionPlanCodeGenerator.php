<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionPlanCodeGenerator
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  callable(SubscriptionPlan): void  $afterCreate
     */
    public function create(array $attributes, callable $afterCreate): SubscriptionPlan
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $afterCreate): SubscriptionPlan {
                    $plan = SubscriptionPlan::create([
                        ...$attributes,
                        'code' => $this->nextCode(),
                    ]);

                    $afterCreate($plan);

                    return $plan;
                });
            } catch (QueryException $exception) {
                if (! $this->isPlanCodeCollision($exception) || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('A unique subscription plan code could not be generated.');
    }

    private function nextCode(): string
    {
        $highestNumber = DB::table('subscription_plans')
            ->where('code', 'like', 'PLN%')
            ->pluck('code')
            ->reduce(function (int $highest, string $code): int {
                if (preg_match('/\APLN([0-9]+)\z/i', $code, $matches) !== 1) {
                    return $highest;
                }

                return max($highest, (int) $matches[1]);
            }, 0);

        return 'PLN'.str_pad((string) ($highestNumber + 1), 6, '0', STR_PAD_LEFT);
    }

    private function isPlanCodeCollision(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'subscription_plans.code')
            || str_contains($message, 'subscription_plans_code_unique');
    }
}
