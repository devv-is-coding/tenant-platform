<?php

namespace Database\Seeders;

use App\Concerns\RunsInTenant;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WishlistSeeder extends Seeder
{
    use RunsInTenant;

    /**
     * Seed a few wishlists in every agency: its own customers saving its own
     * published items, since a customer belongs to one agency and its app
     * shows only that agency's catalog.
     *
     * Run UserSeeder and CatalogSeeder first - wishlists reference the
     * agency's customers and items.
     */
    public function run(): void
    {
        $this->runInEachTenant(function (Tenant $tenant): void {
            $now = now();

            foreach ($this->wishlists($tenant->id) as $username => $slugs) {
                $customerId = $tenant->customers()->where('username', $username)->value('id');

                if ($customerId === null) {
                    continue;
                }

                foreach ($slugs as $slug) {
                    $itemId = DB::table('items')->where('slug', $slug)->value('id');

                    if ($itemId === null) {
                        continue;
                    }

                    DB::table('wishlist_items')->insertOrIgnore([
                        'customer_id' => $customerId,
                        'item_id' => $itemId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function wishlists(string $tenantId): array
    {
        return match ($tenantId) {
            'dumaguete' => [
                'beatriz' => ['hotel-palwa', 'japan-discovery'],
                'tomas' => ['japan-evisa'],
                'carmela' => ['japan-discovery', 'hotel-palwa'],
            ],
            'zamboanguita' => [
                'nadia' => ['zamboanguita-dive-resort', 'siquijor-island-hopping'],
                'ramon' => ['south-korea-evisa'],
            ],
            'tanjay' => [
                'sofia' => ['tanjay-heritage-hotel', 'seoul-busan-explorer'],
                'elena' => ['taiwan-evisa'],
            ],
            'dauin' => [
                'rafael' => ['dauin-beach-villas', 'singapore-evisa'],
                'liza' => ['apo-island-dive-snorkel'],
                'andrea' => ['dauin-beach-villas'],
            ],
            'bayawan' => [
                'paolo' => ['mabinay-caves-adventure', 'bayawan-sunrise-hotel'],
                'joy' => ['vietnam-evisa'],
            ],
            default => [],
        };
    }
}
