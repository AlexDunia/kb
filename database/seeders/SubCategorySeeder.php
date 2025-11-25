<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SubCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define subcategory data
        $subcategories = [
            // Event subcategories
            'Workshop', 'Conference', 'Meetup', 'Webinar',
            'Party', 'Exhibition', 'Concert', 'Tournament',
            'Tasting', 'Hackathon', 'Networking', 'Retreat',

            // Music subcategories
            'Rock', 'Jazz', 'Pop', 'Classical', 'Electronic',
            'Hip Hop', 'R&B', 'Country', 'Metal', 'Blues',
            'Indie', 'Folk',

            // Sports subcategories
            'Football', 'Basketball', 'Tennis', 'Cricket',
            'Golf', 'Rugby', 'Swimming', 'Athletics', 'Boxing',
            'Motor Sports',

            // Education subcategories
            'Seminar', 'Lecture', 'Training', 'Course',
            'Symposium', 'Masterclass',

            // Business subcategories
            'Trade Show', 'Summit', 'Meeting',

            // Food & Drink subcategories
            'Festival', 'Cooking Class', 'Market', 'Dining Event',
            'Food Tour', 'Beer Festival', 'Wine Tasting',

            // Arts & Culture subcategories
            'Theatre', 'Performance', 'Gallery Opening',
            'Museum Event', 'Film Screening', 'Cultural Celebration'
        ];

        // Insert only unique subcategories
        $insertCount = 0;
        foreach ($subcategories as $name) {
            // Check if subcategory already exists
            $exists = DB::table('sub_categories')
                ->where('name', $name)
                ->exists();

            if (!$exists) {
                DB::table('sub_categories')->insert([
                    'name' => $name,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $insertCount++;
            }
        }

        // Output message
        $this->command->info("Inserted {$insertCount} subcategories.");
    }
}
