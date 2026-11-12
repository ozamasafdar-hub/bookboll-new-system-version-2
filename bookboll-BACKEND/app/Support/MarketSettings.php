<?php

namespace App\Support;

use App\Models\CommissionRate;
use App\Models\MarketSetting;
use Nnjeim\World\Models\City;

/** One resolver for quotes, creation guards, reference data and the settings editor. */
class MarketSettings
{
    public const DEFAULTS = [
        'comeback_enabled' => true, 'comeback_discount_pct' => 10.0,
        'membership_listing_monthly' => 20,'facility' => 15.0, 'program' => 15.0, 'event' => 10.0,
        // Null preserves the existing standard / half-standard own-site policy.
        'site_free' => null, 'site_premium' => null,
        'module_listings' => true, 'module_classes' => true, 'module_matchmaking' => true];
    public const SITE_KEYS = ['site_free', 'site_premium'];
    private ?array $rows = null;
    private ?array $rates = null;

    public function clear(): void { $this->rows = $this->rates = null; }

    private function stored(string $scope, int $id): array
    {
        if ($this->rows === null) {
            $this->rows = MarketSetting::all()->mapWithKeys(fn ($r) => [$r->scope.':'.$r->scope_id => $r->values])->all();
            $this->rates = [];
            foreach (CommissionRate::all() as $r) $this->rates[$r->country_id][$r->listing_type] = (float) $r->rate_percent;
        }
        return $this->rows[$scope.':'.$id] ?? [];
    }

    public function config(?int $countryId = null, ?City $city = null): array
    {
        $global = array_replace(self::DEFAULTS, $this->stored('global', 0));
        $countryId = $city ? (int) $city->country_id : $countryId;
        $overrides = $countryId ? array_replace($this->stored('country', $countryId), $this->rates[$countryId] ?? []) : $global;
        $parent = $global;
        $sources = array_fill_keys(array_keys($global), 'global');
        $effective = array_replace($parent, $overrides);
        if ($countryId) foreach ($overrides as $key => $v) $sources[$key] = 'country';
        if ($city) {
            $parent = $effective;
            $overrides = $this->stored('city', $city->id);
            foreach (['module_listings', 'module_classes', 'module_matchmaking'] as $key) {
                if ($city->$key !== null) $overrides[$key] = (bool) $city->$key;
            }
            $effective = array_replace($parent, $overrides);
            foreach ($overrides as $key => $v) $sources[$key] = 'city';
        }
        return compact('effective', 'overrides', 'parent', 'sources');
    }

    public function rate(string $type, ?int $countryId = null, ?int $cityId = null): float
    {
        $city = $cityId ? City::find($cityId) : null;
        return (float) $this->config($countryId, $city)['effective'][$type];
    }

    public function module(string $key, ?int $countryId = null, ?int $cityId = null): bool
    {
        $city = $cityId ? City::find($cityId) : null;
        return (bool) $this->config($countryId, $city)['effective'][$key];
    }

    public function websiteRate(float $standard, bool $premium, ?int $countryId = null, ?int $cityId = null): float
    {
        $city = $cityId ? City::find($cityId) : null;
        $rate = $this->config($countryId, $city)['effective'][$premium ? 'site_premium' : 'site_free'];
        return $rate !== null ? (float) $rate : round($standard * ($premium ? CommissionPolicy::PAID_PLAN_MULTIPLIER : 1), 4);
    }
}
