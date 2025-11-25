<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define main categories
        $categories = [
            'Events',
            'Music',
            'Sports',
            'Education',
            'Business',
            'Food & Drink',
            'Arts & Culture',
            'Technology',
            'Health & Wellness',
            'Family & Kids',
            'Charity & Causes',
            'Hobbies & Special Interest',
            'Travel & Outdoor',
            'Community & Culture'
        ];

        // Insert categories if they don't exist
        $insertCount = 0;
        foreach ($categories as $name) {
            // Check if category already exists
            $exists = DB::table('categories')
                ->where('name', $name)
                ->exists();

            if (!$exists) {
                DB::table('categories')->insert([
                    'name' => $name,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $insertCount++;
            }
        }

        // Output message
        $this->command->info("Inserted {$insertCount} categories.");
    }
}
