<?php

namespace App\Http\Controllers;

use App\Models\{MarketSetting, Venue};
use App\Support\AccessScope;
use App\Support\MarketSettings;
use Illuminate\Http\Request;
use Nnjeim\World\Models\{City, Country};

class BookingFeeController extends Controller
{
    private function rates(?int $countryId = null, ?City $city = null): array
    {
        $config = app(MarketSettings::class)->config($countryId, $city);
        $keys = array_flip(['facility', 'site']);
        return ['rates' => array_intersect_key($config['effective'], $keys),
            'sources' => array_intersect_key($config['sources'], $keys)];
    }

    // Public pricing contains published global fees only, never business data.
    public function published()
    {
        return sendSuccess('Booking fees', $this->rates());
    }

    /** Public geography and booking prices only; never expose admin settings or businesses. */
    public function countries()
    {
        $customCityIds = MarketSetting::where('scope', 'city')->get()->filter(fn ($row) =>
            collect($row->values ?? [])->only(['facility', 'site'])->filter(fn ($v) => $v !== null)->isNotEmpty()
        )->pluck('scope_id');
        $customCounts = City::where('status', 1)->whereIn('id', $customCityIds)->selectRaw('country_id, COUNT(*) as total')->groupBy('country_id')->pluck('total', 'country_id');
        $countries = Country::where('status', 1)->orderBy('name')->get()->map(fn ($country) => [
            'id'=>$country->id, 'name'=>$country->name, 'code'=>$country->iso2,
            'custom_city_count'=>(int)($customCounts[$country->id] ?? 0),
            ...$this->rates($country->id),
        ]);
        return sendSuccess('Country booking fees', ['global'=>$this->rates(), 'countries'=>$countries]);
    }

    public function cities(Request $request, int $country)
    {
        abort_unless(Country::whereKey($country)->where('status', 1)->exists(), 404);
        $data = $request->validate(['search'=>'nullable|string|max:100', 'custom_only'=>'nullable|boolean', 'page'=>'nullable|integer|min:1']);
        $query = City::where('country_id', $country)->where('status', 1)->orderBy('name')->orderBy('id');
        if ($search = trim($data['search'] ?? '')) $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        if ($request->boolean('custom_only')) {
            $ids = MarketSetting::where('scope', 'city')->get()->filter(fn ($row) =>
                collect($row->values ?? [])->only(['facility', 'site'])->filter(fn ($v) => $v !== null)->isNotEmpty()
            )->pluck('scope_id');
            $query->whereIn('id', $ids);
        }
        $page = $query->paginate(50);
        return sendSuccess('City booking fees', [
            'cities'=>collect($page->items())->map(fn ($city) => ['id'=>$city->id, 'name'=>$city->name, ...$this->rates($country, $city)]),
            'pagination'=>['current_page'=>$page->currentPage(), 'last_page'=>$page->lastPage(), 'total'=>$page->total()],
        ]);
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
