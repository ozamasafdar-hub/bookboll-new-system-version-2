<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use App\Support\AccessScope;
use App\Support\MarketSettings;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;

class BookingFeeController extends Controller
{
    private function rates(?int $countryId = null, ?City $city = null): array
    {
        $config = app(MarketSettings::class)->config($countryId, $city);
        $keys = array_flip(['facility', 'site_free', 'site_premium']);
        return ['rates' => array_intersect_key($config['effective'], $keys),
            'sources' => array_intersect_key($config['sources'], $keys)];
    }

    // Public pricing contains published global fees only, never business data.
    public function published()
    {
        return sendSuccess('Booking fees', $this->rates());
    }

    public function provider(Request $request)
    {
        $user = $request->user();
        $business = AccessScope::business($user);
        $base = $this->rates($business?->country_id);
        $base['name'] = $business?->country_id ? 'Business country defaults' : 'Global defaults';
        $venues = [];
        if ($business) {
            $query = AccessScope::applyVenue(Venue::query(), 'venues.id', $user);
            $venues = $query->with(['country', 'city'])->orderBy('name')->get()->map(fn ($venue) => [
                'id' => $venue->id, 'name' => $venue->name,
                'location' => implode(', ', array_filter([$venue->city?->name, $venue->country?->name])),
                ...$this->rates($venue->country_id, $venue->city),
            ])->values()->all();
        }
        return sendSuccess('Your booking fees', ['base' => $base, 'venues' => $venues]);
    }
}
