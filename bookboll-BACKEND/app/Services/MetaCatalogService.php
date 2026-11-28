<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Http\Request;

/** Public, surface-scoped bookable listings for Meta scheduled data feeds. */
class MetaCatalogService extends MarketplaceCatalogService
{
    public function feed(Request $request)
    {
        $report = $this->inventory($request);
        $stream = fopen('php://temp', 'r+');
        $columns = ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'];
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($report['items'] as $item) fputcsv($stream, array_map(fn($key) => $item[$key], $columns), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function overview(Request $request)
    {
        $report = $this->inventory($request);
        return sendSuccess('Catalog checked', $report);
    }

    public function inventory(Request $request): array
    {
        $input = $request->validate(['brand_slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/']]);
        // Feed scope never follows cookies, caller role, visitor country or arbitrary filters.
        $public = Request::create('/catalog', 'GET', array_filter(['brand_slug' => $input['brand_slug'] ?? null]));
        $public->headers->set('from', 'frontend-web');
        $scope = $this->resolveScope($public);
        abort_if($scope['empty'], 404, 'This business website does not exist.');
        $business = $scope['businessId'] ? Business::find($scope['businessId']) : null;
        $report = ['surface' => $business ? 'site' : 'marketplace', 'items' => [], 'omitted' => [], 'currencies' => []];
        // Returning an empty replacement feed removes previously advertised hidden items.
        if ($business && (!$business->brand_enabled || $business->status === Business::STATUS_SUSPENDED || $business->brand_show_venues === false)) return $report;

        $venues = $this->venueQuery($scope)->with('country')->get()->keyBy('id');
        $suspended = Business::where('status', Business::STATUS_SUSPENDED)->pluck('id')->all();
        $venues = $venues->reject(fn($v) => $v->business_id && in_array($v->business_id, $suspended));
        // Explicit show_on_customer prevents an admin 'from' header broadening this public feed.
        $listings = $this->listingQuery($scope, true)
            ->whereIn('venue_id', $venues->keys())->with(['pricingGroups', 'assets'])->orderBy('id')->get();
        foreach ($listings as $listing) {
            $venue = $venues[$listing->venue_id];
            $listing->setRelation('venue', $venue);
            $currency = strtoupper(\App\Support\CommissionPolicy::currencyFor($listing));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $report['omitted'][] = ['id' => (string)$listing->id, 'name' => $listing->name, 'reason' => 'Set the venue or country currency'];
                continue;
            }
            $report['currencies'][] = $currency;
            if (!empty($input['currency']) && $input['currency'] !== $currency) continue;
            $price = $this->startingPrice($listing);
            $image = ($this->photos($listing)[0] ?? $this->photos($venue)[0] ?? '');
            $reason = !$price ? 'Add an advertised price' : (!$this->publicImage($image) ? 'Add a public HTTPS photo' : null);
            if ($reason) { $report['omitted'][] = ['id' => (string)$listing->id, 'name' => $listing->name, 'reason' => $reason]; continue; }
            $title = $this->text($listing->name, 150);
            if ($title === '') { $report['omitted'][] = ['id' => (string)$listing->id, 'name' => '', 'reason' => 'Add a listing name']; continue; }
            $description = $this->text($listing->description ?: $venue->description ?: $listing->name, 4500);
            $report['items'][] = [
                // These IDs deliberately match ViewContent / AddToCart / payment receipts.
                'id' => (string)$listing->id, 'title' => $title,
                'description' => $description.' — '.$this->text($venue->name, 200).'. Starting price; choose a date and session on the website for the final price and availability.',
                'availability' => 'in stock', 'condition' => 'new',
                'price' => number_format($price, \App\Support\Money::exponent($currency), '.', '').' '.$currency,
                'link' => $this->listingUrl((int)$listing->id, $business), 'image_link' => $image,
                'brand' => $this->text($business?->brand_name ?: $business?->name ?: 'Bookboll', 100),
            ];
        }
        $report['currencies'] = array_values(array_unique($report['currencies']));
        sort($report['currencies']);
        return $report;
    }

    protected function listingUrl(int $id, ?Business $business): string
    {
        $base = rtrim(config('app.frontend_url', 'https://bookboll.com'), '/');
        if (!$business) return $base.'/listings/'.$id;
        $slug = $business->brand_subdomain ?: $business->username;
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        // Staging feeds must point to staging and never to a live provider website.
        if (str_ends_with($host, '.railway.app') || in_array($host, ['localhost', '127.0.0.1'])) return $base.'/listings/'.$id.'?b='.rawurlencode($slug);
        $domain = strtolower(trim((string)$business->brand_custom_domain));
        if ($business->brand_domain_status === 'active' && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) return 'https://'.$domain.'/listings/'.$id;
        return 'https://'.$slug.'.'.preg_replace('/^www\./', '', $host).'/listings/'.$id;
    }

    protected function publicImage(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && !parse_url($url, PHP_URL_USER);
    }

    protected function text(?string $value, int $length): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, $length);
    }
}
