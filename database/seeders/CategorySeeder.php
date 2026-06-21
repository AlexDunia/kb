<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Conference & Summit',
            'Music & Concert',
            'Food & Dining',
            'Art & Culture',
            'Business & Networking',
            'Sports & Fitness',
            'Workshop & Training',
            'Party & Social',
            'Startup & Tech',
            'Faith & Community',
            'Theatre & Performing Arts',
            'Education & Learning',
        ];

        foreach ($categories as $name) {
            DB::table('categories')->insertOrIgnore([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
