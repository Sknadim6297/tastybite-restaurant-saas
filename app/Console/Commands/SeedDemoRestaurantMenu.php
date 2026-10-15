<?php

namespace App\Console\Commands;

use Database\Seeders\DemoRestaurantMenuSeeder;
use Illuminate\Console\Command;
use RuntimeException;

class SeedDemoRestaurantMenu extends Command
{
    protected $signature = 'menu:seed-demo {restaurant_id : ID of the existing restaurant that will own this demo menu}';

    protected $description = 'Add missing demo menu categories, products and licensed photos to one existing restaurant';

    public function handle(DemoRestaurantMenuSeeder $seeder): int
    {
        if (app()->environment('production')) {
            $this->error('Demo menu seeding is disabled in production.');

            return self::FAILURE;
        }

        $restaurantId = filter_var($this->argument('restaurant_id'), FILTER_VALIDATE_INT);

        if (! is_int($restaurantId) || $restaurantId < 1) {
            $this->error('Provide a valid existing restaurant ID.');

            return self::FAILURE;
        }

        try {
            $result = $seeder->seedForRestaurant($restaurantId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Demo menu seeded for existing restaurant ID {$restaurantId}.");
        $this->line("Categories created: {$result['categories_created']}");
        $this->line("Products created: {$result['products_created']}");
        $this->line("Product images copied: {$result['images_created']}");
        $this->comment('Existing matching categories and products were left unchanged.');

        return self::SUCCESS;
    }
}
