<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WishlistSeeder extends Seeder
{
    /**
     * Seed a few cross-tenant wishlists: a customer saving a listing from one
     * agency next to a listing from another, which is the reason this table is
     * central rather than per-tenant.
     *
     * Run CatalogSeeder first - it is what populates the central `listings`
     * projection this table points at.
     */
    public function run(): void
    {
        $now = now();

        foreach ($this->wishlists() as $username => $slugs) {
            $userId = User::query()->where('username', $username)->value('id');

            if ($userId === null) {
                continue;
            }

            foreach ($slugs as $slug) {
                $listingId = DB::table('listings')->where('slug', $slug)->value('id');

                if ($listingId === null) {
                    continue;
                }

                $alreadySaved = DB::table('wishlist_items')
                    ->where('user_id', $userId)
                    ->where('listing_id', $listingId)
                    ->exists();

                if ($alreadySaved) {
                    continue;
                }

                DB::table('wishlist_items')->insert([
                    'user_id' => $userId,
                    'listing_id' => $listingId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function wishlists(): array
    {
        return [
            'beatriz' => ['hotel-palwa', 'seoul-busan-explorer'],
            'tomas' => ['siquijor-island-hopping', 'tanjay-heritage-hotel'],
            'carmela' => ['japan-discovery', 'zamboanguita-dive-resort'],
            'julio' => ['south-korea-evisa'],
            'nadia' => ['japan-evisa', 'taiwan-evisa'],
            'rafael' => ['dauin-beach-villas', 'singapore-evisa'],
            'liza' => ['apo-island-dive-snorkel', 'bayawan-sunrise-hotel'],
            'miguel' => ['mabinay-caves-adventure'],
            'andrea' => ['vietnam-evisa', 'dauin-beach-villas'],
            'paolo' => ['mabinay-caves-adventure', 'seoul-busan-explorer'],
        ];
    }
}
