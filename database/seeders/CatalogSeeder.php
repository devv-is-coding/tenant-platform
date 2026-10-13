<?php

namespace Database\Seeders;

use App\Enums\AvailabilityMode;
use App\Enums\InventoryBatchReason;
use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Enums\MediaType;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Seed each tenant's catalog: items, their typed profiles, itinerary stops,
     * media and options, plus the opening inventory batch for every option that
     * tracks stock. Published items are also projected into the central
     * `listings` table, mirroring what `SyncListingProjection` will eventually do
     * from a queued domain event.
     *
     * Run TenantSeeder and UserSeeder first - items are attributed to a tenant's
     * seeded staff and manager.
     */
    public function run(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            $tenant->run(function () use ($tenant): void {
                if (DB::table('items')->exists()) {
                    return;
                }

                $staffId = User::query()->where('tenant_id', $tenant->id)->where('role', Role::Staff)->value('id');
                $managerId = User::query()->where('tenant_id', $tenant->id)->where('role', Role::Manager)->value('id');
                $currency = tenant('currency') ?? 'PHP';

                foreach ($this->catalog($tenant->id) as $item) {
                    $this->seedItem($tenant, $item, $staffId, $managerId, $currency);
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedItem(Tenant $tenant, array $item, ?int $staffId, ?int $managerId, string $currency): void
    {
        $now = now();
        $createdOn = $now->copy()->subDays(30);
        $submittedOn = $now->copy()->subDays(20);
        $approvedOn = $now->copy()->subDays(15);
        $publishedOn = $now->copy()->subDays(14);

        $status = $item['status'];

        $itemId = DB::table('items')->insertGetId([
            'item_type' => $item['type']->value,
            'name' => $item['name'],
            'slug' => $item['slug'],
            'description' => $item['description'],
            'item_status' => $status->value,
            'rejection_reason' => $status === ItemStatus::Rejected ? $item['rejection_reason'] : null,
            'submitted_on' => $submittedOn,
            'approved_on' => $status === ItemStatus::Published ? $approvedOn : null,
            'rejected_on' => $status === ItemStatus::Rejected ? $approvedOn : null,
            'published_on' => $status === ItemStatus::Published ? $publishedOn : null,
            'created_by' => $staffId,
            'approved_by' => in_array($status, [ItemStatus::Published, ItemStatus::Rejected], true) ? $managerId : null,
            'is_active' => true,
            'created_on' => $createdOn,
            'updated_on' => $publishedOn,
        ]);

        $this->seedAuditTrail($itemId, $status, $staffId, $managerId, $submittedOn, $approvedOn, $publishedOn);
        $this->seedProfile($itemId, $item);

        if ($item['type'] === ItemType::Tour) {
            $this->seedItinerary($itemId, $item['stops']);
        }

        $featuredMedia = $this->seedMedia($itemId, $item['media']);
        $prices = $this->seedOptions($itemId, $item['options'], $staffId);

        if ($status === ItemStatus::Published) {
            $this->projectListing($tenant, $itemId, $item, $prices, $featuredMedia, $currency, $publishedOn);
        }
    }

    private function seedAuditTrail(
        int $itemId,
        ItemStatus $status,
        ?int $staffId,
        ?int $managerId,
        CarbonInterface $submittedOn,
        CarbonInterface $approvedOn,
        CarbonInterface $publishedOn,
    ): void {
        $rows = [
            [
                'actor_id' => $staffId,
                'actor_role' => Role::Staff->value,
                'action' => 'item.submitted',
                'subject_type' => 'item',
                'subject_id' => $itemId,
                'changes' => null,
                'created_on' => $submittedOn,
            ],
        ];

        if ($status === ItemStatus::Published) {
            $rows[] = [
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'item.approved',
                'subject_type' => 'item',
                'subject_id' => $itemId,
                'changes' => null,
                'created_on' => $approvedOn,
            ];
            $rows[] = [
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'item.published',
                'subject_type' => 'item',
                'subject_id' => $itemId,
                'changes' => null,
                'created_on' => $publishedOn,
            ];
        } elseif ($status === ItemStatus::Rejected) {
            $rows[] = [
                'actor_id' => $managerId,
                'actor_role' => Role::Manager->value,
                'action' => 'item.rejected',
                'subject_type' => 'item',
                'subject_id' => $itemId,
                'changes' => json_encode(['item_status' => ['old' => ItemStatus::Pending->value, 'new' => ItemStatus::Rejected->value]]),
                'created_on' => $approvedOn,
            ];
        }

        DB::table('audit_events')->insert($rows);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedProfile(int $itemId, array $item): void
    {
        $now = now();
        $profile = $item['profile'];
        $profile['item_id'] = $itemId;
        $profile['created_on'] = $now;
        $profile['updated_on'] = $now;

        if (isset($profile['inclusions'])) {
            $profile['inclusions'] = json_encode($profile['inclusions']);
        }

        if (isset($profile['requirements'])) {
            $profile['requirements'] = json_encode($profile['requirements']);
        }

        if (isset($profile['amenities'])) {
            $profile['amenities'] = json_encode($profile['amenities']);
        }

        DB::table($item['type']->profileTable())->insert($profile);
    }

    /**
     * @param  list<array{sequence: int, location: string, country_code: string, starts_on: string, ends_on: string, latitude: float, longitude: float}>  $stops
     */
    private function seedItinerary(int $itemId, array $stops): void
    {
        $now = now();

        DB::table('itinerary_stops')->insert(array_map(
            fn (array $stop): array => [
                ...$stop,
                'item_id' => $itemId,
                'created_on' => $now,
                'updated_on' => $now,
            ],
            $stops,
        ));
    }

    /**
     * @param  list<array{path: string, file_name: string, featured: bool}>  $media
     * @return array{disk: string, path: string}|null
     */
    private function seedMedia(int $itemId, array $media): ?array
    {
        $now = now();
        $featured = null;

        $rows = [];

        foreach ($media as $sortOrder => $file) {
            $rows[] = [
                'item_id' => $itemId,
                'disk' => 'tenant',
                'path' => $file['path'],
                'file_name' => $file['file_name'],
                'media_type' => MediaType::Image->value,
                'mime_type' => 'image/jpeg',
                'featured_for_item_id' => $file['featured'] ? $itemId : null,
                'sort_order' => $sortOrder,
                'created_on' => $now,
                'updated_on' => $now,
            ];

            if ($file['featured']) {
                $featured = ['disk' => 'tenant', 'path' => $file['path']];
            }
        }

        DB::table('media')->insert($rows);

        return $featured;
    }

    /**
     * @param  non-empty-list<array{name: string, mode: AvailabilityMode, price_minor: int, min_nights?: int, max_nights?: int, batch_quantity?: int}>  $options
     * @return array{min: int, max: int}
     */
    private function seedOptions(int $itemId, array $options, ?int $staffId): array
    {
        $now = now();

        foreach ($options as $option) {
            $optionId = DB::table('options')->insertGetId([
                'item_id' => $itemId,
                'name' => $option['name'],
                'availability_mode' => $option['mode']->value,
                'price_minor' => $option['price_minor'],
                'min_nights' => $option['min_nights'] ?? null,
                'max_nights' => $option['max_nights'] ?? null,
                'is_active' => true,
                'created_by' => $staffId,
                'created_on' => $now,
                'updated_on' => $now,
            ]);

            // Only options whose mode tracks stock carry a batch quantity;
            // Unlimited options (visas) have none and write no ledger rows.
            if (isset($option['batch_quantity'])) {
                DB::table('inventory_batches')->insert([
                    'option_id' => $optionId,
                    'quantity' => $option['batch_quantity'],
                    'reason' => InventoryBatchReason::InitialStock->value,
                    'notes' => 'Opening allocation',
                    'added_by' => $staffId,
                    'created_on' => $now,
                    'updated_on' => $now,
                ]);
            }
        }

        $prices = array_column($options, 'price_minor');

        return ['min' => min($prices), 'max' => max($prices)];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{min: int, max: int}  $prices
     * @param  array{disk: string, path: string}|null  $featuredMedia
     */
    private function projectListing(
        Tenant $tenant,
        int $itemId,
        array $item,
        array $prices,
        ?array $featuredMedia,
        string $currency,
        CarbonInterface $syncedOn,
    ): void {
        DB::connection(config('tenancy.database.central_connection'))->table('listings')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'item_id' => $itemId,
            'item_type' => $item['type']->value,
            'name' => $item['name'],
            'slug' => $item['slug'],
            'description' => $item['description'],
            'tenant_name' => $tenant->name,
            'locality' => $tenant->name,
            'country_code' => $item['country_code'],
            'currency' => $currency,
            'price_min_minor' => $prices['min'],
            'price_max_minor' => $prices['max'],
            'rating_avg' => null,
            'reviews_count' => 0,
            'available_from' => $item['profile']['departs_on'] ?? null,
            'available_to' => $item['profile']['returns_on'] ?? null,
            'featured_media_disk' => $featuredMedia['disk'] ?? null,
            'featured_media_path' => $featuredMedia['path'] ?? null,
            'latitude' => $item['stops'][0]['latitude'] ?? null,
            'longitude' => $item['stops'][0]['longitude'] ?? null,
            'synced_on' => $syncedOn,
            'created_on' => $syncedOn,
            'updated_on' => $syncedOn,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(string $tenantId): array
    {
        return match ($tenantId) {
            'dumaguete' => $this->dumagueteCatalog(),
            'tanjay' => $this->tanjayCatalog(),
            default => $this->zamboanguitaCatalog(),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dumagueteCatalog(): array
    {
        return [
            [
                'type' => ItemType::Tour,
                'name' => 'Japan Discovery: Tokyo, Hokkaido & Okinawa',
                'slug' => 'japan-discovery',
                'description' => 'A 12-day loop through Tokyo, Hokkaido and Okinawa, with an English-speaking guide throughout.',
                'status' => ItemStatus::Published,
                'country_code' => 'JP',
                'profile' => [
                    'max_adults' => 4,
                    'max_kids' => 2,
                    'baggage_allowance_kg' => 20,
                    'meeting_point' => 'NAIA Terminal 3, Manila',
                    'inclusions' => ['Return airfare', 'Hotel accommodation', 'Daily breakfast', 'English-speaking guide'],
                    'departs_on' => '2026-12-01',
                    'returns_on' => '2026-12-12',
                ],
                'stops' => [
                    ['sequence' => 1, 'location' => 'Tokyo', 'country_code' => 'JP', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-04', 'latitude' => 35.676200, 'longitude' => 139.650300],
                    ['sequence' => 2, 'location' => 'Hokkaido', 'country_code' => 'JP', 'starts_on' => '2026-12-05', 'ends_on' => '2026-12-08', 'latitude' => 43.061800, 'longitude' => 141.354500],
                    ['sequence' => 3, 'location' => 'Okinawa', 'country_code' => 'JP', 'starts_on' => '2026-12-09', 'ends_on' => '2026-12-12', 'latitude' => 26.212400, 'longitude' => 127.680900],
                ],
                'media' => [
                    ['path' => 'items/japan-discovery/tokyo-skyline.jpg', 'file_name' => 'tokyo-skyline.jpg', 'featured' => true],
                    ['path' => 'items/japan-discovery/okinawa-beach.jpg', 'file_name' => 'okinawa-beach.jpg', 'featured' => false],
                ],
                'options' => [
                    ['name' => 'Solo', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 49900, 'batch_quantity' => 20],
                    ['name' => 'Couple', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 69900, 'batch_quantity' => 20],
                    ['name' => 'Family (5)', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 99900, 'batch_quantity' => 10],
                ],
            ],
            [
                'type' => ItemType::Hotel,
                'name' => 'Hotel Palwa Dumaguete',
                'slug' => 'hotel-palwa',
                'description' => 'A boulevard-front hotel in Dumaguete City, five minutes from Silliman University.',
                'status' => ItemStatus::Published,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 2,
                    'amenities' => ['Wi-Fi', 'Air conditioning', 'Hot shower', 'Breakfast included'],
                    'check_in_time' => '14:00:00',
                    'check_out_time' => '12:00:00',
                ],
                'stops' => [],
                'media' => [
                    ['path' => 'items/hotel-palwa/lobby.jpg', 'file_name' => 'lobby.jpg', 'featured' => true],
                    ['path' => 'items/hotel-palwa/room-101.jpg', 'file_name' => 'room-101.jpg', 'featured' => false],
                ],
                'options' => [
                    ['name' => 'Room 101', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 19900, 'min_nights' => 1, 'max_nights' => 30, 'batch_quantity' => 2],
                    ['name' => 'Standard Double, 2 Queen Beds', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 16000, 'min_nights' => 1, 'max_nights' => 30, 'batch_quantity' => 3],
                ],
            ],
            [
                'type' => ItemType::Visa,
                'name' => 'Japan Tourist e-Visa',
                'slug' => 'japan-evisa',
                'description' => 'Tourist e-visa processing for Japan, submitted through our accredited visa desk.',
                'status' => ItemStatus::Published,
                'country_code' => 'JP',
                'profile' => [
                    'processing_days_min' => 5,
                    'processing_days_max' => 10,
                    'validity_days' => 90,
                    'requirements' => ['Valid passport (6+ months)', 'Proof of funds', 'Return ticket', 'Completed application form'],
                ],
                'stops' => [],
                'media' => [
                    ['path' => 'items/japan-evisa/passport-stamp.jpg', 'file_name' => 'passport-stamp.jpg', 'featured' => true],
                ],
                'options' => [
                    ['name' => 'e-Visa', 'mode' => AvailabilityMode::Unlimited, 'price_minor' => 49900],
                    ['name' => 'In-person appointment', 'mode' => AvailabilityMode::Unlimited, 'price_minor' => 49900],
                ],
            ],
            [
                'type' => ItemType::Tour,
                'name' => 'Bohol Countryside & Chocolate Hills',
                'slug' => 'bohol-countryside',
                'description' => 'A day tour of the Chocolate Hills, the tarsier sanctuary and the Loboc river cruise.',
                'status' => ItemStatus::Pending,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 6,
                    'max_kids' => 3,
                    'baggage_allowance_kg' => null,
                    'meeting_point' => 'Tagbilaran Port, Bohol',
                    'inclusions' => ['Van transfers', 'Lunch buffet', 'Entrance fees'],
                    'departs_on' => '2026-11-15',
                    'returns_on' => '2026-11-15',
                ],
                'stops' => [
                    ['sequence' => 1, 'location' => 'Chocolate Hills, Carmen', 'country_code' => 'PH', 'starts_on' => '2026-11-15', 'ends_on' => '2026-11-15', 'latitude' => 9.924700, 'longitude' => 124.136100],
                ],
                'media' => [
                    ['path' => 'items/bohol-countryside/chocolate-hills.jpg', 'file_name' => 'chocolate-hills.jpg', 'featured' => true],
                ],
                'options' => [
                    ['name' => 'Per person', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 189900, 'batch_quantity' => 15],
                ],
            ],
            [
                'type' => ItemType::Hotel,
                'name' => 'Budget Inn Dumaguete',
                'slug' => 'budget-inn-dumaguete',
                'description' => 'Simple rooms near the Dumaguete public market.',
                'status' => ItemStatus::Rejected,
                'rejection_reason' => 'No photographs, and the itinerary has no meeting point.',
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 2,
                    'amenities' => ['Fan', 'Shared bathroom'],
                    'check_in_time' => '14:00:00',
                    'check_out_time' => '12:00:00',
                ],
                'stops' => [],
                'media' => [],
                'options' => [
                    ['name' => 'Single Room', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 8000, 'min_nights' => 1, 'max_nights' => 14, 'batch_quantity' => 4],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zamboanguitaCatalog(): array
    {
        return [
            [
                'type' => ItemType::Tour,
                'name' => 'Siquijor Island Hopping & Falls',
                'slug' => 'siquijor-island-hopping',
                'description' => 'A day of island hopping, Salagdoong cliff diving and the Cambugahay Falls rope swing.',
                'status' => ItemStatus::Published,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 8,
                    'max_kids' => 4,
                    'baggage_allowance_kg' => null,
                    'meeting_point' => 'Zamboanguita Port',
                    'inclusions' => ['Boat transfers', 'Lunch', 'Life vests', 'Habal-habal island tour'],
                    'departs_on' => '2026-11-20',
                    'returns_on' => '2026-11-20',
                ],
                'stops' => [
                    ['sequence' => 1, 'location' => 'San Juan, Siquijor', 'country_code' => 'PH', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'latitude' => 9.173300, 'longitude' => 123.598000],
                    ['sequence' => 2, 'location' => 'Salagdoong Beach', 'country_code' => 'PH', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'latitude' => 9.139200, 'longitude' => 123.655800],
                    ['sequence' => 3, 'location' => 'Cambugahay Falls', 'country_code' => 'PH', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'latitude' => 9.171700, 'longitude' => 123.628100],
                ],
                'media' => [
                    ['path' => 'items/siquijor-island-hopping/cambugahay-falls.jpg', 'file_name' => 'cambugahay-falls.jpg', 'featured' => true],
                    ['path' => 'items/siquijor-island-hopping/salagdoong-cliff.jpg', 'file_name' => 'salagdoong-cliff.jpg', 'featured' => false],
                ],
                'options' => [
                    ['name' => 'Solo', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 129900, 'batch_quantity' => 20],
                    ['name' => 'Couple', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 229900, 'batch_quantity' => 20],
                    ['name' => 'Group (5)', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 549900, 'batch_quantity' => 8],
                ],
            ],
            [
                'type' => ItemType::Hotel,
                'name' => 'Zamboanguita Dive Resort',
                'slug' => 'zamboanguita-dive-resort',
                'description' => 'A beachfront resort steps from the Apo Island dive boats, with a house reef out front.',
                'status' => ItemStatus::Published,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 3,
                    'amenities' => ['Wi-Fi', 'Air conditioning', 'Dive gear storage', 'Beachfront'],
                    'check_in_time' => '14:00:00',
                    'check_out_time' => '11:00:00',
                ],
                'stops' => [],
                'media' => [
                    ['path' => 'items/zamboanguita-dive-resort/beachfront.jpg', 'file_name' => 'beachfront.jpg', 'featured' => true],
                    ['path' => 'items/zamboanguita-dive-resort/cabana.jpg', 'file_name' => 'cabana.jpg', 'featured' => false],
                ],
                'options' => [
                    ['name' => 'Garden View Room', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 220000, 'min_nights' => 1, 'max_nights' => 21, 'batch_quantity' => 4],
                    ['name' => 'Beachfront Cabana', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 350000, 'min_nights' => 2, 'max_nights' => 14, 'batch_quantity' => 2],
                ],
            ],
            [
                'type' => ItemType::Visa,
                'name' => 'South Korea Tourist e-Visa',
                'slug' => 'south-korea-evisa',
                'description' => 'K-ETA and tourist e-visa processing for South Korea.',
                'status' => ItemStatus::Published,
                'country_code' => 'KR',
                'profile' => [
                    'processing_days_min' => 3,
                    'processing_days_max' => 7,
                    'validity_days' => 180,
                    'requirements' => ['Valid passport', 'Proof of funds', 'Confirmed round-trip ticket'],
                ],
                'stops' => [],
                'media' => [
                    ['path' => 'items/south-korea-evisa/keta-approval.jpg', 'file_name' => 'keta-approval.jpg', 'featured' => true],
                ],
                'options' => [
                    ['name' => 'K-ETA', 'mode' => AvailabilityMode::Unlimited, 'price_minor' => 39900],
                    ['name' => 'Express Processing', 'mode' => AvailabilityMode::Unlimited, 'price_minor' => 59900],
                ],
            ],
            [
                'type' => ItemType::Tour,
                'name' => 'Apo Island Snorkeling Day Trip',
                'slug' => 'apo-island-snorkeling',
                'description' => 'Snorkeling with sea turtles in the Apo Island marine sanctuary.',
                'status' => ItemStatus::Pending,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 10,
                    'max_kids' => 2,
                    'baggage_allowance_kg' => null,
                    'meeting_point' => 'Malatapay Port, Zamboanguita',
                    'inclusions' => ['Boat transfers', 'Snorkel gear', 'Marine sanctuary fee'],
                    'departs_on' => '2026-11-22',
                    'returns_on' => '2026-11-22',
                ],
                'stops' => [
                    ['sequence' => 1, 'location' => 'Apo Island Marine Sanctuary', 'country_code' => 'PH', 'starts_on' => '2026-11-22', 'ends_on' => '2026-11-22', 'latitude' => 9.073900, 'longitude' => 123.264700],
                ],
                'media' => [
                    ['path' => 'items/apo-island-snorkeling/sea-turtle.jpg', 'file_name' => 'sea-turtle.jpg', 'featured' => true],
                ],
                'options' => [
                    ['name' => 'Per person', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 99900, 'batch_quantity' => 15],
                ],
            ],
            [
                'type' => ItemType::Hotel,
                'name' => 'Seaside Inn Zamboanguita',
                'slug' => 'seaside-inn-zamboanguita',
                'description' => 'A budget inn a short tricycle ride from the port.',
                'status' => ItemStatus::Rejected,
                'rejection_reason' => 'No photographs, and the room descriptions are missing.',
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 2,
                    'amenities' => ['Fan'],
                    'check_in_time' => '13:00:00',
                    'check_out_time' => '11:00:00',
                ],
                'stops' => [],
                'media' => [],
                'options' => [
                    ['name' => 'Single Room', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 7500, 'min_nights' => 1, 'max_nights' => 14, 'batch_quantity' => 3],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tanjayCatalog(): array
    {
        return [
            [
                'type' => ItemType::Tour,
                'name' => 'Seoul & Busan Explorer',
                'slug' => 'seoul-busan-explorer',
                'description' => 'An 8-day loop through Seoul, Busan and Jeju Island, with a Filipino-speaking tour leader.',
                'status' => ItemStatus::Published,
                'country_code' => 'KR',
                'profile' => [
                    'max_adults' => 4,
                    'max_kids' => 2,
                    'baggage_allowance_kg' => 20,
                    'meeting_point' => 'Mactan-Cebu International Airport',
                    'inclusions' => ['Return airfare', 'Hotel accommodation', 'Daily breakfast', 'Filipino-speaking tour leader'],
                    'departs_on' => '2027-01-10',
                    'returns_on' => '2027-01-17',
                ],
                'stops' => [
                    ['sequence' => 1, 'location' => 'Seoul', 'country_code' => 'KR', 'starts_on' => '2027-01-10', 'ends_on' => '2027-01-13', 'latitude' => 37.566500, 'longitude' => 126.978000],
                    ['sequence' => 2, 'location' => 'Busan', 'country_code' => 'KR', 'starts_on' => '2027-01-14', 'ends_on' => '2027-01-15', 'latitude' => 35.179600, 'longitude' => 129.075600],
                    ['sequence' => 3, 'location' => 'Jeju Island', 'country_code' => 'KR', 'starts_on' => '2027-01-16', 'ends_on' => '2027-01-17', 'latitude' => 33.499600, 'longitude' => 126.531200],
                ],
                'media' => [
                    ['path' => 'items/seoul-busan-explorer/seoul-palace.jpg', 'file_name' => 'seoul-palace.jpg', 'featured' => true],
                    ['path' => 'items/seoul-busan-explorer/jeju-coast.jpg', 'file_name' => 'jeju-coast.jpg', 'featured' => false],
                ],
                'options' => [
                    ['name' => 'Solo', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 589900, 'batch_quantity' => 16],
                    ['name' => 'Couple', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 1099900, 'batch_quantity' => 16],
                    ['name' => 'Family (5)', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 2599900, 'batch_quantity' => 6],
                ],
            ],
            [
                'type' => ItemType::Hotel,
                'name' => 'Tanjay Heritage Hotel',
                'slug' => 'tanjay-heritage-hotel',
                'description' => 'A restored ancestral house turned boutique hotel in the heart of Tanjay City.',
                'status' => ItemStatus::Published,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 2,
                    'amenities' => ['Wi-Fi', 'Air conditioning', 'Breakfast included', 'Courtyard garden'],
                    'check_in_time' => '14:00:00',
                    'check_out_time' => '12:00:00',
                ],
                'stops' => [],
                'media' => [
                    ['path' => 'items/tanjay-heritage-hotel/courtyard.jpg', 'file_name' => 'courtyard.jpg', 'featured' => true],
                    ['path' => 'items/tanjay-heritage-hotel/suite.jpg', 'file_name' => 'suite.jpg', 'featured' => false],
                ],
                'options' => [
                    ['name' => 'Deluxe Room', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 180000, 'min_nights' => 1, 'max_nights' => 30, 'batch_quantity' => 5],
                    ['name' => 'Suite', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 320000, 'min_nights' => 1, 'max_nights' => 14, 'batch_quantity' => 2],
                ],
            ],
            [
                'type' => ItemType::Visa,
                'name' => 'Taiwan Tourist e-Visa',
                'slug' => 'taiwan-evisa',
                'description' => 'Tourist e-visa processing for Taiwan.',
                'status' => ItemStatus::Published,
                'country_code' => 'TW',
                'profile' => [
                    'processing_days_min' => 4,
                    'processing_days_max' => 8,
                    'validity_days' => 90,
                    'requirements' => ['Valid passport (6+ months)', 'Proof of funds', 'Return ticket'],
                ],
                'stops' => [],
                'media' => [
                    ['path' => 'items/taiwan-evisa/taipei-101.jpg', 'file_name' => 'taipei-101.jpg', 'featured' => true],
                ],
                'options' => [
                    ['name' => 'e-Visa', 'mode' => AvailabilityMode::Unlimited, 'price_minor' => 45900],
                    ['name' => 'Expedited e-Visa', 'mode' => AvailabilityMode::Unlimited, 'price_minor' => 65900],
                ],
            ],
            [
                'type' => ItemType::Tour,
                'name' => 'Negros Oriental Waterfalls Tour',
                'slug' => 'negros-oriental-waterfalls',
                'description' => 'A day trip to Casaroro Falls and Pulangbato Falls, with a stop at Forest Camp.',
                'status' => ItemStatus::Pending,
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 6,
                    'max_kids' => 3,
                    'baggage_allowance_kg' => null,
                    'meeting_point' => 'Tanjay City Plaza',
                    'inclusions' => ['Van transfers', 'Lunch', 'Entrance fees'],
                    'departs_on' => '2026-11-18',
                    'returns_on' => '2026-11-18',
                ],
                'stops' => [
                    ['sequence' => 1, 'location' => 'Casaroro Falls, Valencia', 'country_code' => 'PH', 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'latitude' => 9.260000, 'longitude' => 123.209400],
                ],
                'media' => [
                    ['path' => 'items/negros-oriental-waterfalls/casaroro-falls.jpg', 'file_name' => 'casaroro-falls.jpg', 'featured' => true],
                ],
                'options' => [
                    ['name' => 'Per person', 'mode' => AvailabilityMode::Capacity, 'price_minor' => 149900, 'batch_quantity' => 12],
                ],
            ],
            [
                'type' => ItemType::Hotel,
                'name' => 'Tanjay Budget Lodge',
                'slug' => 'tanjay-budget-lodge',
                'description' => 'Basic rooms near the Tanjay public terminal.',
                'status' => ItemStatus::Rejected,
                'rejection_reason' => 'No photographs, and the room descriptions are missing.',
                'country_code' => 'PH',
                'profile' => [
                    'max_adults' => 2,
                    'amenities' => ['Fan', 'Shared bathroom'],
                    'check_in_time' => '14:00:00',
                    'check_out_time' => '12:00:00',
                ],
                'stops' => [],
                'media' => [],
                'options' => [
                    ['name' => 'Single Room', 'mode' => AvailabilityMode::Nightly, 'price_minor' => 7000, 'min_nights' => 1, 'max_nights' => 14, 'batch_quantity' => 3],
                ],
            ],
        ];
    }
}
