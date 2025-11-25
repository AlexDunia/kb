<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting to seed events...');

        // Create default organizers first
        $organizerIds = $this->createOrganizers();

        // Create address records
        $addressIds = $this->createAddresses();

        // Get category IDs
        $categoryIds = $this->getCategoryIds();

        // Get subcategory IDs
        $subCategoryIds = $this->getSubCategoryIds();

        // Create the events
        $this->createEvents($organizerIds, $addressIds, $categoryIds, $subCategoryIds);

        $this->command->info('Events seeded successfully!');
    }

    /**
     * Create organizers and return the IDs
     */
    private function createOrganizers(): array
    {
        $organizers = [
            'Global Events Co.',
            'Comedy Central',
            'Arts Council',
            'National Basketball Association',
            'Culinary Arts Foundation',
            'TechHub',
        ];

        $organizerIds = [];

        foreach ($organizers as $name) {
            $exists = DB::table('organizers')
                ->where('name', $name)
                ->exists();

            if (!$exists) {
                $id = DB::table('organizers')->insertGetId([
                    'name' => $name,
                    'email' => Str::slug($name, '') . '@example.com',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $organizerIds[$name] = $id;
            } else {
                $organizerIds[$name] = DB::table('organizers')
                    ->where('name', $name)
                    ->first()->id;
            }
        }

        $this->command->info('Created ' . count($organizerIds) . ' organizers');
        return $organizerIds;
    }

    /**
     * Create addresses and return the IDs
     */
    private function createAddresses(): array
    {
        $addresses = [
            'Central Park' => [
                'venue_name' => 'Central Park',
                'address_line1' => '59th to 110th Street',
                'address_line2' => '',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10022',
                'country' => 'United States',
            ],
            'Laugh Factory' => [
                'venue_name' => 'Laugh Factory',
                'address_line1' => '8001 Sunset Boulevard',
                'address_line2' => '',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90046',
                'country' => 'United States',
            ],
            'Modern Gallery' => [
                'venue_name' => 'Modern Gallery',
                'address_line1' => '220 E Chicago Avenue',
                'address_line2' => '',
                'city' => 'Chicago',
                'state' => 'IL',
                'postal_code' => '60611',
                'country' => 'United States',
            ],
            'Sports Arena' => [
                'venue_name' => 'Sports Arena',
                'address_line1' => '601 Biscayne Boulevard',
                'address_line2' => '',
                'city' => 'Miami',
                'state' => 'FL',
                'postal_code' => '33132',
                'country' => 'United States',
            ],
            'Waterfront Park' => [
                'venue_name' => 'Waterfront Park',
                'address_line1' => 'Embarcadero',
                'address_line2' => '',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94105',
                'country' => 'United States',
            ],
            'Convention Center' => [
                'venue_name' => 'Convention Center',
                'address_line1' => '705 Pike Street',
                'address_line2' => '',
                'city' => 'Seattle',
                'state' => 'WA',
                'postal_code' => '98101',
                'country' => 'United States',
            ],
        ];

        $addressIds = [];

        foreach ($addresses as $key => $data) {
            $exists = DB::table('event_addresses')
                ->where('venue_name', $data['venue_name'])
                ->exists();

            if (!$exists) {
                $id = DB::table('event_addresses')->insertGetId(array_merge($data, [
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]));
                $addressIds[$key] = $id;
            } else {
                $addressIds[$key] = DB::table('event_addresses')
                    ->where('venue_name', $data['venue_name'])
                    ->first()->id;
            }
        }

        $this->command->info('Created ' . count($addressIds) . ' addresses');
        return $addressIds;
    }

    /**
     * Get category IDs
     */
    private function getCategoryIds(): array
    {
        $categoryMapping = [
            'music' => 'Music',
            'comedy' => 'Comedy',
            'arts' => 'Arts & Culture',
            'sports' => 'Sports',
            'festivals' => 'Food & Drink',
            'others' => 'Technology',
        ];

        $categoryIds = [];

        foreach ($categoryMapping as $key => $name) {
            $category = DB::table('categories')->where('name', $name)->first();

            if ($category) {
                $categoryIds[$key] = $category->id;
            } else {
                // If the category doesn't exist, create it
                $id = DB::table('categories')->insertGetId([
                    'name' => $name,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $categoryIds[$key] = $id;
            }
        }

        return $categoryIds;
    }

    /**
     * Get subcategory IDs
     */
    private function getSubCategoryIds(): array
    {
        $subCategoryMapping = [
            'sub1' => 'Workshop',
            'sub2' => 'Conference',
            'sub3' => 'Meetup',
            'sub4' => 'Webinar',
            'sub5' => 'Party',
            'sub6' => 'Exhibition',
            'sub7' => 'Concert',
            'sub8' => 'Tournament',
            'sub9' => 'Tasting',
            'sub10' => 'Hackathon',
            'sub11' => 'Networking',
            'sub12' => 'Retreat',
        ];

        $subCategoryIds = [];

        foreach ($subCategoryMapping as $key => $name) {
            $subCategory = DB::table('sub_categories')->where('name', $name)->first();

            if ($subCategory) {
                $subCategoryIds[$key] = $subCategory->id;
            } else {
                // If the subcategory doesn't exist, create it
                $id = DB::table('sub_categories')->insertGetId([
                    'name' => $name,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $subCategoryIds[$key] = $id;
            }
        }

        return $subCategoryIds;
    }

    /**
     * Create events with all related data
     */
    private function createEvents(array $organizerIds, array $addressIds, array $categoryIds, array $subCategoryIds): void
    {
        // Create demo events from the provided data
        $events = [
            [
                'title' => 'Summer Music Festival',
                'description' => 'Join us for three days of amazing music from top artists across the globe.',
                'category_id' => $categoryIds['music'],
                'subCategories' => ['sub1', 'sub7'],
                'date' => '2024-08-15 16:00:00',
                'location' => 'Central Park',
                'price' => 150.0,
                'total_tickets' => 5000,
                'featured' => true,
                'organizer' => 'Global Events Co.',
                'duration' => '3 days',
                'ticketTypes' => [
                    [
                        'name' => 'Early Bird',
                        'price' => 120.0,
                        'quantity' => 1000,
                        'description' => 'Limited time offer at a discounted price',
                        'sales_end_date' => '2024-07-15',
                        'is_featured' => true,
                    ],
                    [
                        'name' => 'VIP',
                        'price' => 250.0,
                        'quantity' => 500,
                        'description' => 'Premium experience with exclusive benefits',
                        'sales_end_date' => '2024-08-14',
                        'is_featured' => true,
                    ],
                    [
                        'name' => 'General Admission',
                        'price' => 150.0,
                        'quantity' => 3500,
                        'description' => 'Standard festival entry',
                        'sales_end_date' => null,
                        'is_featured' => false,
                    ],
                ],
                'faqs' => [
                    [
                        'question' => "What's included in the ticket price?",
                        'answer' => 'Your ticket includes entry to all stages and performances throughout the festival.',
                    ],
                    [
                        'question' => 'Is there camping available?',
                        'answer' => 'Yes, camping options are available for an additional fee.',
                    ],
                ],
            ],
            [
                'title' => 'Comedy Night',
                'description' => 'An evening of laughter with the best stand-up comedians in the country.',
                'category_id' => $categoryIds['comedy'],
                'date' => '2024-07-22 19:30:00',
                'location' => 'Laugh Factory',
                'price' => 75.0,
                'total_tickets' => 800,
                'featured' => true,
                'organizer' => 'Comedy Central',
                'duration' => '3 hours',
            ],
            [
                'title' => 'Art Exhibition Opening',
                'description' => 'Exclusive opening night of contemporary art from emerging artists.',
                'category_id' => $categoryIds['arts'],
                'date' => '2024-08-05 18:00:00',
                'location' => 'Modern Gallery',
                'price' => 50.0,
                'total_tickets' => 600,
                'featured' => false,
                'organizer' => 'Arts Council',
                'duration' => '4 hours',
            ],
            [
                'title' => 'Basketball Championship',
                'description' => 'The final game of the season. Who will be crowned champions?',
                'category_id' => $categoryIds['sports'],
                'date' => '2024-06-30 19:00:00',
                'location' => 'Sports Arena',
                'price' => 120.0,
                'total_tickets' => 10000,
                'featured' => true,
                'organizer' => 'National Basketball Association',
                'duration' => '3 hours',
            ],
            [
                'title' => 'Food & Wine Festival',
                'description' => 'Taste the finest cuisines and wines from top chefs and wineries.',
                'category_id' => $categoryIds['festivals'],
                'date' => '2024-09-05 11:00:00',
                'location' => 'Waterfront Park',
                'price' => 125.0,
                'total_tickets' => 3000,
                'featured' => false,
                'organizer' => 'Culinary Arts Foundation',
                'duration' => '2 days',
            ],
            [
                'title' => 'Tech Conference 2024',
                'description' => 'Learn about the latest technologies and network with industry professionals.',
                'category_id' => $categoryIds['others'],
                'date' => '2024-10-12 09:00:00',
                'location' => 'Convention Center',
                'price' => 499.99,
                'total_tickets' => 2000,
                'featured' => false,
                'organizer' => 'TechHub',
                'duration' => '3 days',
            ],
        ];

        $insertCount = 0;

        foreach ($events as $eventData) {
            // Check if the event already exists to avoid duplicates
            $exists = DB::table('events')
                ->where('title', $eventData['title'])
                ->exists();

            if (!$exists) {
                // Make sure we have the right foreign keys
                $organizerId = $organizerIds[$eventData['organizer']] ?? null;
                $addressId = $addressIds[$eventData['location']] ?? null;

                if (!$organizerId || !$addressId) {
                    $this->command->error("Missing organizer or address for event: {$eventData['title']}");
                    continue;
                }

                // Create a dummy user id for created_by if not present
                $userId = DB::table('users')->first()->id ?? 1;

                // Create a default image path
                $mainImagePath = 'events/images/default-event.jpg';
                $bannerImagePath = 'events/images/default-banner.jpg';

                // Make sure the storage directory exists
                if (!Storage::disk('public')->exists('events/images')) {
                    Storage::disk('public')->makeDirectory('events/images');
                }

                // Create placeholder images if they don't exist
                if (!Storage::disk('public')->exists($mainImagePath)) {
                    Storage::disk('public')->put($mainImagePath, 'Placeholder image');
                }

                if (!Storage::disk('public')->exists($bannerImagePath)) {
                    Storage::disk('public')->put($bannerImagePath, 'Placeholder image');
                }

                // Insert the event
                $eventId = DB::table('events')->insertGetId([
                    'title' => $eventData['title'],
                    'description' => $eventData['description'],
                    'category_id' => $eventData['category_id'],
                    'organizer_id' => $organizerId,
                    'address_id' => $addressId,
                    'date' => $eventData['date'],
                    'price' => $eventData['price'],
                    'total_tickets' => $eventData['total_tickets'],
                    'duration' => $eventData['duration'],
                    'featured' => $eventData['featured'] ?? false,
                    'main_image' => $mainImagePath,
                    'banner_image' => $bannerImagePath,
                    'created_by' => $userId,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                // Add subcategories if specified
                if (isset($eventData['subCategories'])) {
                    foreach ($eventData['subCategories'] as $subCategoryKey) {
                        if (isset($subCategoryIds[$subCategoryKey])) {
                            DB::table('event_sub_category')->insert([
                                'event_id' => $eventId,
                                'sub_category_id' => $subCategoryIds[$subCategoryKey],
                            ]);
                        }
                    }
                }

                // Add ticket types if specified
                if (isset($eventData['ticketTypes'])) {
                    foreach ($eventData['ticketTypes'] as $ticketData) {
                        DB::table('ticket_types')->insert([
                            'event_id' => $eventId,
                            'name' => $ticketData['name'],
                            'price' => $ticketData['price'],
                            'quantity' => $ticketData['quantity'],
                            'description' => $ticketData['description'] ?? null,
                            'sales_end_date' => $ticketData['sales_end_date'] ?? null,
                            'is_featured' => $ticketData['is_featured'] ?? false,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }
                }

                // Add FAQs if specified
                if (isset($eventData['faqs'])) {
                    foreach ($eventData['faqs'] as $faqData) {
                        DB::table('faqs')->insert([
                            'event_id' => $eventId,
                            'question' => $faqData['question'],
                            'answer' => $faqData['answer'],
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }
                }

                $insertCount++;
            }
        }

        $this->command->info("Created {$insertCount} events with related data");
    }
}
