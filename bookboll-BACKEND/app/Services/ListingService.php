<?php
namespace App\Services;

use App\Models\Asset;
use App\Support\AccessScope;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\Resource;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\SlotLock;
use App\Models\SpecialDay;
use App\Models\SportsCategory;
use App\Models\SportsType;
use App\Models\Venue;
use BookingStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

class ListingService
{
    public function __construct(protected Listing $listingModel)
    {}

    public function all($request)
    {
        $search           = $request->search ?? null;
        $schedule         = $request->boolean('schedule', true);
        $records_per_page = $request->records_per_page ?? 20;

        $query = $this->listingModel::with([
            'addOns',
            'assets',
            'resources',
            'pricingGroups',
            'venue'          => function ($q) {
                $q->orderBy('sort_order', 'ASC');
            },
            'sportsCategory' => function ($q) {
                $q->select('id', 'name', 'slug', 'icon', 'sports_type_id');
            },
        ])
            ->orderBy('sort_order', 'ASC')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('venue_id', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('base_price', 'LIKE', "%{$search}%")
                        ->orWhereHas('sportsCategory', function ($query) use ($search) {
                            $query->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('slug', 'LIKE', "%{$search}%");
                        });
                });
            })
            ->when($request->venue_id, function ($query, $venue_id) {
                $query->whereRelation('venue', 'id', $venue_id);
            });

        if ($schedule) {
            $query->with([
                'schedules' => function ($q) {
                    $q->where('is_open', true)->orderBy('day');
                },
            ]);
        }

        $query = applyVenueFilter($query, 'venue_id');

        $listings = $query->paginate($records_per_page);

        return sendSuccess('Listings fetched successfully', compact('listings'));
    }

    public function create($request)
    {
        $formData = $this->parseAndValidateRequest($request);

        try {
            DB::beginTransaction();

            $listing = $this->listingModel::create($this->extractListingData($formData));

            if (! empty($formData['add_ons'])) {
                $listing->addOns()->sync($formData['add_ons']);
            }

            if (! empty($formData['resources'])) {
                $syncData = [];
                foreach ($formData['resources'] as $resource) {
                    $syncData[$resource['id']] = ['quantity' => $resource['quantity']];
                }
                $listing->resources()->sync($syncData);
            }

            if ($request->hasFile('images')) {
                $this->uploadImagesAsAssets($listing, $request->file('images'));
            }

            if (! empty($formData['schedule'])) {
                $this->createSchedules($listing, $formData['schedule']);
            }

            if (! empty($formData['pricing_groups'])) {
                foreach ($formData['pricing_groups'] as $groupData) {
                    $listing->pricingGroups()->create($groupData);
                }
            }

            $listing->validateScheduleConflicts($formData['schedule'] ?? []);
            $listing->validatePricingGroups($formData['pricing_groups'] ?? []);

            DB::commit();

            $listing->load([
                'venue',
                'addOns',
                'assets',
                'resources',
                'pricingGroups',
                'schedules' => function ($q) {$q->where('is_open', true);},
            ]);

            return sendSuccess('Listing created successfully', compact('listing'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create listing: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to create listing: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $listing = $this->listingModel->findOrFail($id);

        $formData = $this->parseAndValidateRequest($request, true);

        try {

            DB::beginTransaction();

            $listingUpdateData = $this->extractListingData($formData);

            if ($request->hasFile('images')) {
                $this->deleteListingAssets($listing);
                $this->uploadImagesAsAssets($listing, $request->file('images'));
            }

            unset($listingUpdateData['images_urls']);

            $listing->update($listingUpdateData);

            if (isset($formData['add_ons'])) {
                $listing->addOns()->sync($formData['add_ons']);
            } elseif (empty($formData['add_ons'])) {
                $listing->addOns()->delete();
            }

            if (isset($formData['resources'])) {
                $syncData = [];
                foreach ($formData['resources'] as $resource) {
                    $syncData[$resource['id']] = ['quantity' => $resource['quantity']];
                }
                $listing->resources()->sync($syncData);
            }

            if (isset($formData['schedule'])) {
                $this->syncSchedules($listing, $formData['schedule']);
            }

            if (isset($formData['pricing_groups'])) {
                $listing->pricingGroups()->delete();
                foreach ($formData['pricing_groups'] as $groupData) {
                    $listing->pricingGroups()->create($groupData);
                }
            }

            $listing->validateScheduleConflicts($formData['schedule'] ?? $listing->schedules->toArray());
            $listing->validatePricingGroups($formData['pricing_groups'] ?? $listing->pricingGroups->toArray());

            DB::commit();

            $listing->load([
                'venue',
                'schedules' => function ($q) {$q->where('is_open', true);},
                'pricingGroups',
                'addOns',
                'resources',
            ]);

            return sendSuccess('Listing updated successfully', compact('listing'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update listing: ' . $e->getMessage(), ['listing_id' => $id, 'Exception' => $e]);
            return sendError('Failed to update listing: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $listing = $this->listingModel->with(['schedules', 'addOns', 'resources', 'pricingGroups', 'assets'])->findOrFail($id);
        return sendSuccess('Listing fetched successfully', compact('listing'));
    }

    public function delete($id)
    {
        $listing = $this->listingModel->findOrFail($id);

        $this->deleteOldImages($listing->images_urls);
        $listing->schedules()->delete();
        $listing->pricingGroups()->delete();
        $listing->delete();

        return sendSuccess('Listing deleted successfully');
    }

    public function isAvailable(Listing $listing, $day, $time)
    {
        $schedule = $listing->getScheduleForDay($day);

        if (! $schedule) {
            return false;
        }

        return $schedule->isTimeSlotAvailable($time);
    }

    protected function parseAndValidateRequest($request, bool $isUpdate = false): array
    {
        $rules = [
            'name'                               => 'required|string|max:255',
            'venue_id'                           => 'required|exists:venues,id',
            'sports_category_id'                 => 'required|exists:sports_categories,id',
            'show_on_admin'                      => 'required|boolean',
            'show_on_customer'                   => 'required|boolean',
            'description'                        => 'nullable|string',
            'base_price'                         => 'required|numeric|min:0',
            'max_bookings_per_slot'              => 'required|integer|min:1',
            'allow_multiple_bookings'            => 'nullable|boolean',
            'max_multiple_bookings'              => 'nullable|integer|min:1|required_if:allow_multiple_bookings,true|lte:max_bookings_per_slot',
            'capacity'                           => 'required|integer|min:1',
            'slot_duration'                      => 'required|numeric|min:0',
            'min_advance_minutes'                => 'nullable|integer|min:0|max:525600',
            'loyalty_points'                     => 'nullable|integer|min:0',
            'calendar_color'                     => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'online_payment'                     => 'required|boolean',
            'payment_options'                    => 'required|array',
            'payment_options.cash_on_arrival'    => 'nullable|boolean',
            'payment_options.deposit.amount'     => 'nullable|numeric|min:0',
            'payment_options.deposit.percentage' => 'nullable|numeric|between:0,100',
            'schedule'                           => $isUpdate ? 'sometimes|required|array|min:1' : 'required|array|min:7',
            'schedule.*.day'                     => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedule.*.is_open'                 => 'required|boolean',
            'schedule.*.start_time'              => 'required_if:schedule.*.is_open,true|date_format:H:i',
            'schedule.*.end_time'                => 'required_if:schedule.*.is_open,true|date_format:H:i',
            'pricing_groups'                     => 'nullable|array',
            'pricing_groups.*.name'              => 'nullable|string|max:255',
            'pricing_groups.*.start_time'        => ['required', 'regex:/^([01]?[0-9]|2[0-4]):[0-5][0-9]$/'],
            'pricing_groups.*.end_time'          => ['required', 'regex:/^([01]?[0-9]|2[0-4]):[0-5][0-9]$/'],
            'pricing_groups.*.price_per_hour'    => 'required|numeric|min:0',
            'pricing_groups.*.days'              => 'required|array',
            'pricing_groups.*.days.*'            => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'images.*'                           => [
                'nullable',
                File::types([
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/bmp',
                    'image/webp',
                    'image/avif',
                ])->max(5120 * 1024),
            ],
            'add_ons.*'                          => 'nullable|exists:add_ons,id',
            'tags'                               => 'nullable|array',
            'tags.left_tag'                      => 'nullable|array',
            'tags.left_tag.text'                 => 'nullable|string|max:30',
            'tags.left_tag.color'                => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'tags.right_tag'                     => 'nullable|array',
            'tags.right_tag.text'                => 'nullable|string|max:30',
            'tags.right_tag.color'               => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'resources'                          => 'nullable|array',
            'resources.*.id'                     => 'required|exists:resources,id',
            'resources.*.quantity'               => 'nullable|integer|min:1',
            'amenities'                          => 'array',
        ];

        if ($isUpdate) {
            $rules = $this->makeRulesOptional($rules);
        }

        $request->validate($rules, [
            'tags.left_tag.text.max'  => 'The left tag text must be less than 30 characters.',
            'tags.right_tag.text.max' => 'The right tag text must be less than 30 characters.',
        ]);

        $parsedData = $request->all();

        $parsedData = $this->parseJsonFields($parsedData);

        $this->validateComplexData($parsedData, $isUpdate);

        if (isset($parsedData['schedule'])) {
            $this->validateScheduleTimes($parsedData['schedule']);
        }

        return $parsedData;
    }

    protected function parseJsonFields(array $data): array
    {
        if (isset($data['payment_options']) && is_string($data['payment_options'])) {
            $decoded = json_decode($data['payment_options'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['payment_options'] = $decoded;
            } else {
                throw ValidationException::withMessages([
                    'payment_options' => ['Invalid JSON format for payment options'],
                ]);
            }
        }

        if (isset($data['schedule']) && is_string($data['schedule'])) {
            $decoded = json_decode($data['schedule'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['schedule'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'schedule' => ['Invalid JSON format for schedule'],
                ]);
            }
        }

        if (isset($data['pricing_groups']) && is_string($data['pricing_groups'])) {
            $decoded = json_decode($data['pricing_groups'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['pricing_groups'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'pricing_groups' => ['Invalid JSON format for pricing groups'],
                ]);
            }
        }

        if (isset($data['tags']) && is_string($data['tags'])) {
            $decoded = json_decode($data['tags'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['tags'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'tags' => ['Invalid JSON format for tags'],
                ]);
            }
        }

        if (isset($data['add_ons']) && ! is_array($data['add_ons'])) {
            $data['add_ons'] = $data['add_ons'] ? [$data['add_ons']] : [];
        }

        if (isset($data['resources']) && is_array($data['resources'])) {
            $resources = [];
            foreach ($data['resources'] as $index => $resource) {
                if (is_array($resource)) {
                    $resources[] = $resource;
                } elseif (is_numeric($resource)) {
                    $resources[] = ['id' => $resource, 'quantity' => 1];
                }
            }
            $data['resources'] = $resources;
        }

        return $data;
    }

    protected function validateComplexData(array $data, bool $isUpdate = false): void
    {
        $rules = [
            'payment_options'                    => 'required|array',
            'payment_options.cash_on_arrival'    => 'nullable|boolean',
            'payment_options.deposit.amount'     => 'nullable|numeric|min:0',
            'payment_options.deposit.percentage' => 'nullable|numeric|between:0,100',

            'schedule'                           => $isUpdate ? 'sometimes|required|array|min:1' : 'required|array|min:7',
            'schedule.*.day'                     => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedule.*.is_open'                 => 'required|boolean',
            'schedule.*.start_time'              => 'required_if:schedule.*.is_open,true|date_format:H:i',
            'schedule.*.end_time'                => 'required_if:schedule.*.is_open,true|date_format:H:i',

            'pricing_groups'                     => 'nullable|array',
            'pricing_groups.*.name'              => 'nullable|string|max:255',
            'pricing_groups.*.start_time'        => ['required', 'regex:/^([01]?[0-9]|2[0-4]):[0-5][0-9]$/'],
            'pricing_groups.*.end_time'          => ['required', 'regex:/^([01]?[0-9]|2[0-4]):[0-5][0-9]$/'],
            'pricing_groups.*.price_per_hour'    => 'required|numeric|min:0',
            'pricing_groups.*.days'              => 'required|array',
            'pricing_groups.*.days.*'            => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',

            'add_ons'                            => 'nullable|array',
            'resources'                          => 'nullable|array',
            'resources.*.id'                     => 'required|exists:resources,id',
            'resources.*.quantity'               => 'nullable|integer|min:1',
            'tags'                               => 'nullable|array',
            'tags.left_tag'                      => 'nullable|array',
            'tags.left_tag.text'                 => 'nullable|string|max:255',
            'tags.left_tag.color'                => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'tags.right_tag'                     => 'nullable|array',
            'tags.right_tag.text'                => 'nullable|string|max:255',
            'tags.right_tag.color'               => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
        ];

        $validator = \Validator::make($data, $rules);

        $validator->after(function ($validator) use ($data) {
            if (! empty($data['resources']) && is_array($data['resources'])) {
                foreach ($data['resources'] as $index => $resourceData) {
                    if (! isset($resourceData['id']) || ! isset($resourceData['quantity'])) {
                        continue;
                    }

                    $resource = Resource::find($resourceData['id']);

                    if ($resource && $resourceData['quantity'] > $resource->total_quantity) {
                        $validator->errors()->add(
                            "resources.$index.quantity",
                            "The selected resource requirements quantity ({$resourceData['quantity']}) exceeds the resource total stock ({$resource->total_quantity})."
                        );

                        continue;
                    }

                    // A ceiling the shared pool can never reach is a contradiction:
                    // with 2 units needed from a 2-unit pool, only 1 booking ever fits,
                    // so max_bookings_per_slot of 2 would promise a court that cannot exist.
                    $maxPerSlot  = (int) ($data['max_bookings_per_slot'] ?? 1);
                    $requiredQty = (int) $resourceData['quantity'];

                    if ($resource && $requiredQty > 0 && $maxPerSlot > 0) {
                        $achievable = intdiv((int) $resource->total_quantity, $requiredQty);

                        if ($maxPerSlot > $achievable) {
                            $validator->errors()->add(
                                'max_bookings_per_slot',
                                "Max bookings per slot ({$maxPerSlot}) exceeds what {$resource->name} allows. "
                                . "Each booking uses {$requiredQty} of {$resource->total_quantity}, so at most {$achievable} can run at once."
                            );
                        }
                    }
                }
            }

            if (! empty($data['pricing_groups']) && is_array($data['pricing_groups'])) {
                foreach ($data['pricing_groups'] as $index => $group) {
                    if (! isset($group['start_time']) || ! isset($group['end_time'])) {
                        continue;
                    }

                    $startTime = $group['start_time'];
                    $endTime   = $group['end_time'];

                    if ($endTime === '24:00') {
                        continue;
                    }

                    $normalizedStart = $this->normalizeTimeForComparison($startTime);
                    $normalizedEnd   = $this->normalizeTimeForComparison($endTime);

                    if ($endTime === '00:00') {
                        $normalizedEnd = 24 * 60;
                    }

                    if ($endTime === '00:00') {
                        continue;
                    }

                    if ($normalizedEnd <= $normalizedStart) {
                        $validator->errors()->add(
                            "pricing_groups.$index.end_time",
                            "The end time must be after the start time."
                        );
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->messages());
        }
    }

    protected function makeRulesOptional(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (is_array($rule)) {
                $hasRequired  = false;
                $hasSometimes = false;

                foreach ($rule as $ruleItem) {
                    if (is_string($ruleItem)) {
                        if ($ruleItem === 'required' || $ruleItem === 'sometimes') {
                            if ($ruleItem === 'required') {
                                $hasRequired = true;
                            }
                            if ($ruleItem === 'sometimes') {
                                $hasSometimes = true;
                            }
                        }
                    }
                }

                if ($hasRequired && ! $hasSometimes) {
                    array_unshift($rules[$field], 'sometimes');
                }
            } elseif (is_string($rule) && str_contains($rule, 'required') && ! str_contains($rule, 'sometimes')) {
                $rules[$field] = str_replace('required', 'sometimes|required', $rule);
            }
        }
        return $rules;
    }

    protected function extractListingData(array $data): array
    {
        $tags = [
            'left_tag'  => null,
            'right_tag' => null,
        ];

        if (isset($data['tags']) && is_array($data['tags'])) {
            if (isset($data['tags']['left_tag']) && is_array($data['tags']['left_tag'])) {
                $tags['left_tag'] = [
                    'text'  => isset($data['tags']['left_tag']['text']) ? trim($data['tags']['left_tag']['text']) : null,
                    'color' => isset($data['tags']['left_tag']['color']) ? trim($data['tags']['left_tag']['color']) : null,
                ];
                if (empty($tags['left_tag']['text']) && empty($tags['left_tag']['color'])) {
                    $tags['left_tag'] = null;
                }
            }

            if (isset($data['tags']['right_tag']) && is_array($data['tags']['right_tag'])) {
                $tags['right_tag'] = [
                    'text'  => isset($data['tags']['right_tag']['text']) ? trim($data['tags']['right_tag']['text']) : null,
                    'color' => isset($data['tags']['right_tag']['color']) ? trim($data['tags']['right_tag']['color']) : null,
                ];
                if (empty($tags['right_tag']['text']) && empty($tags['right_tag']['color'])) {
                    $tags['right_tag'] = null;
                }
            }

            if ($tags['left_tag'] === null && $tags['right_tag'] === null) {
                $tags = null;
            }
        }

        $listingData = [
            'name'                    => $data['name'],
            'venue_id'                => $data['venue_id'],
            'capacity'                => $data['capacity'],
            'base_price'              => $data['base_price'],
            'slot_duration'           => $data['slot_duration'],
            'online_payment'          => $data['online_payment'],
            'amenities'               => $data['amenities'] ?? [],
            'tags'                    => $tags,
            'images_urls'             => $data['images_urls'] ?? [],
            'sports_category_id'      => $data['sports_category_id'],
            'loyalty_points'          => $data['loyalty_points'] ?? 0,
            'description'             => $data['description'] ?? null,
            'payment_options'         => $data['payment_options'] ?? [],
            'max_bookings_per_slot'   => $data['max_bookings_per_slot'] ?? 1,
            'min_advance_minutes'     => (int) ($data['min_advance_minutes'] ?? 0),
            'calendar_color'          => $data['calendar_color'] ?? '#0000FF',
            'max_multiple_bookings'   => $data['max_multiple_bookings'] ?? null,
            'allow_multiple_bookings' => $data['allow_multiple_bookings'] ? 1 : 0,
        ];

        // Only touch visibility when the caller actually sent it, so a partial
        // update can never silently re-expose a hidden listing.
        foreach (['show_on_admin', 'show_on_customer'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $listingData[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $listingData;
    }

    protected function createSchedules(Listing $listing, array $scheduleData): void
    {
        $listing->schedules()->delete();

        foreach ($scheduleData as $schedule) {
            if ($schedule['is_open']) {
                Schedule::create([
                    'is_open'    => true,
                    'listing_id' => $listing->id,
                    'day'        => $schedule['day'],
                    'venue_id'   => $listing->venue_id,
                    'end_time'   => $schedule['end_time'],
                    'start_time' => $schedule['start_time'],
                ]);
            }
        }
    }

    protected function syncSchedules(Listing $listing, array $scheduleData): void
    {
        $existingSchedules = $listing->schedules()->get();

        $normalizedNewSchedules = collect($scheduleData)
            ->filter(function ($schedule) {
                return $schedule['is_open'] ?? false;
            })
            ->map(function ($schedule) {
                return [
                    'day'        => $schedule['day'],
                    'is_open'    => true,
                    'start_time' => $schedule['start_time'] ?? null,
                    'end_time'   => $schedule['end_time'] ?? null,
                ];
            });

        $normalizedExistingSchedules = $existingSchedules->map(function ($schedule) {
            $startTime = $schedule->start_time ?
            (is_string($schedule->start_time) ? $schedule->start_time : $schedule->start_time->format('H:i'))
                : null;
            $endTime = $schedule->end_time ?
            (is_string($schedule->end_time) ? $schedule->end_time : $schedule->end_time->format('H:i'))
                : null;

            return [
                'id'         => $schedule->id,
                'day'        => $schedule->day,
                'is_open'    => $schedule->is_open,
                'start_time' => $startTime,
                'end_time'   => $endTime,
            ];
        });

        $schedulesToDelete = $normalizedExistingSchedules->filter(function ($existing) use ($normalizedNewSchedules) {
            return ! $normalizedNewSchedules->contains(function ($new) use ($existing) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        $schedulesToCreate = $normalizedNewSchedules->filter(function ($new) use ($normalizedExistingSchedules) {
            return ! $normalizedExistingSchedules->contains(function ($existing) use ($new) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        if ($schedulesToDelete->isNotEmpty()) {
            $idsToDelete = $schedulesToDelete->pluck('id')->toArray();
            $listing->schedules()->whereIn('id', $idsToDelete)->delete();
        }

        foreach ($schedulesToCreate as $schedule) {
            Schedule::create([
                'is_open'    => true,
                'listing_id' => $listing->id,
                'day'        => $schedule['day'],
                'venue_id'   => $listing->venue_id,
                'end_time'   => $schedule['end_time'],
                'start_time' => $schedule['start_time'],
            ]);
        }
    }

    protected function schedulesMatch($schedule1, $schedule2)
    {
        return $schedule1['day'] === $schedule2['day'] &&
            $schedule1['is_open'] === $schedule2['is_open'] &&
            $schedule1['start_time'] === $schedule2['start_time'] &&
            $schedule1['end_time'] === $schedule2['end_time'];
    }

    protected function uploadImages(array $images): array
    {
        $uploadedUrls = [];

        foreach ($images as $image) {
            if ($image->isValid()) {
                $uploadedUrls[] = $image->store('listings', 'public');
            }
        }

        return $uploadedUrls;
    }

    protected function deleteOldImages(array $imageUrls): void
    {
        foreach ($imageUrls as $imageUrl) {
            $path = str_replace('/storage/', '', $imageUrl);
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    protected function uploadImagesAsAssets(Listing $listing, array $images): void
    {
        $sortOrder = $listing->assets()->max('sort_order') ?? 0;

        foreach ($images as $image) {
            if ($image->isValid()) {
                $uploadedPath = $image->store('listings', 'public');

                $listing->assets()->create([
                    'path'       => $uploadedPath,
                    'type'       => Asset::TYPE_IMAGE,
                    'sort_order' => ++$sortOrder,
                ]);
            }
        }
    }

    protected function deleteListingAssets(Listing $listing): void
    {
        $assets = $listing->assets()->get();

        foreach ($assets as $asset) {
            if (! filter_var($asset->path, FILTER_VALIDATE_URL) && Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->delete($asset->path);
            }
            $asset->delete();
        }
    }

    public function uploadImage($request, $listingId)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        try {
            $listing = $this->listingModel->findOrFail($listingId);

            if ($request->hasFile('image')) {
                $uploadedPath = $request->file('image')->store('listings', 'public');

                $maxSortOrder = $listing->assets()->max('sort_order') ?? 0;

                $asset = $listing->assets()->create([
                    'path'       => $uploadedPath,
                    'type'       => Asset::TYPE_IMAGE,
                    'sort_order' => $maxSortOrder + 1,
                ]);

                $uploadedUrl = $asset->url;

                $totalImages = $listing->assets()->count();

                return sendSuccess('Image uploaded successfully', [
                    'listing_id'   => $listing->id,
                    'image_url'    => $uploadedUrl,
                    'image_path'   => $uploadedPath,
                    'asset_id'     => $asset->id,
                    'total_images' => $totalImages,
                ]);
            }

            return sendError('No image file provided', [], 400);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (\Exception $e) {
            Log::error('Failed to upload listing image: ' . $e->getMessage(), ['listing_id' => $listingId, 'Exception' => $e]);
            return sendError('Failed to upload image: ' . $e->getMessage(), [], 500);
        }
    }

    public function deleteImage($request, $listingId)
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        try {
            $listing = $this->listingModel->findOrFail($listingId);

            $imageUrl = $request->query('url');

            if (strpos($imageUrl, '/storage/') !== false) {
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $path    = str_replace('/storage/', '', $urlPath);
            } elseif (strpos($imageUrl, 'storage/') === 0) {
                $path = str_replace('storage/', '', $imageUrl);
            } else {
                $path = $imageUrl;
            }

            $path = ltrim($path, '/');

            $asset = $listing->assets()->where('path', $path)->first();

            if (! $asset) {
                $filename = basename($path);
                $asset    = $listing->assets()
                    ->whereRaw("SUBSTRING_INDEX(path, '/', -1) = ?", [$filename])
                    ->first();
            }

            if (! $asset && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $asset = $listing->assets()->where('path', $imageUrl)->first();
            }

            if (! $asset) {
                return sendError('Image not found in listing assets', [], 404);
            }

            if (! filter_var($asset->path, FILTER_VALIDATE_URL) && Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->delete($asset->path);
            }

            $asset->delete();

            $remainingImages = $listing->assets()->count();

            return sendSuccess('Image deleted successfully', [
                'listing_id'       => $listing->id,
                'remaining_images' => $remainingImages,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (Exception $e) {
            Log::error('Failed to delete listing image: ' . $e->getMessage(), ['listing_id' => $listingId, 'Exception' => $e]);
            return sendError('Failed to delete image: ' . $e->getMessage(), [], 500);
        }
    }

    public function fetchListings($request)
    {
        $date             = $request->date ?? null;
        $time             = $request->time ?? null;
        $search           = $request->search ?? null;
        $venue_id         = $request->venue_id ?? null;
        $sports_type      = $request->category ?? null;
        $categories       = $request->categories ?? [];
        $exclude_id       = $request->exclude_id ?? null;
        $records_per_page = $request->records_per_page ?? null;

        // Eager-loads stay as New-System narrowed them (country/state/city are
        // selected, not whole rows); the visibility scope comes from development
        // and replaces the old `active()` — a listing can now be bookable by staff
        // but not customers, and this endpoint serves whichever surface asked.
        $query = $this->listingModel::with([
            'venue.country:id,name,iso2',
            'venue.state:id,name',
            'venue.city:id,name',
            'schedules',
            'sportsCategory.sportsType',
            'addOns',
            'assets',
        ])
            ->visibleToRequestSurface()
            ->whereRelation('venue', 'status', 'active')
            ->whereHas('schedules', function ($q) {
                $q->where('is_open', 1);
            })
            ->orderBy('sort_order', 'ASC')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('base_price', 'LIKE', "%{$search}%");
                });
            })
            ->when($venue_id, function ($query, $venue_id) {
                $query->whereRelation('venue', 'id', $venue_id);
            })
            ->when($exclude_id, function ($query, $exclude_id) {
                $query->where('id', '!=', $exclude_id);
            })
            ->when($sports_type, function ($query, $sports_type) {
                $query->whereHas('sportsCategory.sportsType', function ($q) use ($sports_type) {
                    $q->where('slug', $sports_type);
                });
            })
            ->when($categories, function ($query, $categories) {
                $query->whereHas('sportsCategory', function ($q) use ($categories) {
                    $q->whereIn('name', $categories);
                });
            })
            ->when($date, function ($query, $date) {
                $dayName = Carbon::parse($date)->format('l');
                $query->whereHas('schedules', function ($q) use ($dayName) {
                    $q->where('day', $dayName)->where('is_open', true);
                });
            })
            ->when($time, function ($query, $time) use ($date) {
                $dayName = Carbon::parse($date)->format('l');
                $query->whereHas('schedules', function ($q) use ($dayName, $time) {
                    $q->where('day', $dayName)
                        ->where('is_open', true)
                        ->whereTime('start_time', '<=', $time)
                        ->whereTime('end_time', '>=', $time);
                });
            });

        if (! $records_per_page || $records_per_page === 'all' || (int) $records_per_page === 0) {
            $data     = $query->get();
            $listings = new LengthAwarePaginator(
                $data,
                $data->count(),
                $data->count() ?: 1,
                1,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        } else {
            $listings = $query->paginate($records_per_page);
        }

        $listings->getCollection()->transform(function ($listing) {
            return [
                'id'                      => $listing->id,
                'name'                    => $listing->name,
                'add_ons'                 => $listing->addOns,
                'price'                   => $listing->base_price,
                'tags'                    => $listing->tags ?? [],
                'description'             => $listing->description,
                'venue'                   => $listing->venue?->name,
                'venue_location'          => $this->formatVenueLocationPayload($listing->venue),
                'slot_duration'           => $listing->slot_duration,
                'loyalty_points'          => $listing->loyalty_points,
                'payment_options'         => $listing->payment_options,
                'map_address'             => $listing->venue?->maps_url,
                'images'                  => $listing->images_urls ?? [],
                'sport'                   => $listing->sportsCategory?->name,
                'max_multiple_bookings'   => $listing->max_multiple_bookings,
                'allow_multiple_bookings' => $listing->allow_multiple_bookings,
                'rating'                  => round($listing->average_rating, 1),
                'duration'                => getFormattedDuration($listing->slotDurationMinutes()),
            ];
        });

        return sendSuccess("Listings fetched successfully", compact('listings'));
    }

    public function fetchSuggestions($request)
    {
        $categoryType = $request->category ?? null;
        $limit        = $request->limit ?? 3;

        $sportsType = SportsType::withCount('listings')->where("slug", $categoryType)->first();

        $categoryIds = SportsCategory::where("sports_type_id", $sportsType->id ?? null)->pluck('id')->toArray();

        $listings = $this->listingModel::with([
            'venue.country:id,name,iso2',
            'venue.state:id,name',
            'venue.city:id,name',
            'sportsCategory',
            'assets',
        ])
            ->when($categoryIds, function ($query, $categoryIds) {
                $query->whereNotIn('sports_category_id', $categoryIds);
            })
            ->visibleToRequestSurface()
            ->whereRelation('venue', 'status', 'active')
            ->take($limit)
            ->get()
            ->map(function ($listing) {
                return [
                    'id'                      => $listing->id,
                    'name'                    => $listing->name,
                    'price'                   => $listing->base_price,
                    'tags'                    => $listing->tags ?? [],
                    'description'             => $listing->description,
                    'venue'                   => $listing->venue?->name,
                    'venue_location'          => $this->formatVenueLocationPayload($listing->venue),
                    'loyaltyPoints'           => $listing->loyalty_points,
                    'payment_options'         => $listing->payment_options,
                    'map_address'             => $listing->venue?->maps_url,
                    'images'                  => $listing->images_urls ?? [],
                    'sport'                   => $listing->sportsCategory?->name,
                    'category'                => $listing->sportsCategory?->name,
                    'max_multiple_bookings'   => $listing->max_multiple_bookings,
                    'allow_multiple_bookings' => $listing->allow_multiple_bookings,
                    'icon'                    => $listing->sportsCategory?->sportsType?->icon,
                    'rating'                  => round($listing->average_rating, 1),
                    'duration'                => getFormattedDuration($listing->slotDurationMinutes()),
                ];
            });

        return sendSuccess("Listings fetched successfully", compact('listings', 'sportsType'));
    }

    public function fetchAddons($listing_id)
    {
        $listing = $this->listingModel->with([
            'addons' => function ($query) {
                $query->orderBy('sort_order', 'ASC');
            },
            'assets',
        ])->findOrFail($listing_id);

        $addons = $listing->addons->map(function ($addon) {
            return [
                'recommended'     => true,
                'id'              => $addon->id,
                'name'            => $addon->name,
                'image'           => $addon->image,
                'price'           => $addon->price ?? 0,
                'description'     => $addon->description,
                'hours_extension' => $addon->hours_extension,
            ];
        });

        return sendSuccess('Addons fetched successfully', compact('addons'));
    }

    public function mobileListingsList($request)
    {
        $data = $request->validate([
            'search'             => 'nullable|string|max:255',
            'page'               => 'nullable|integer|min:1',
            'records_per_page'   => 'nullable|integer|min:1|max:100',
            'venue_id'           => 'nullable|exists:venues,id',
            'sports_category_id' => 'nullable|exists:sports_categories,id',
        ]);

        $search           = $data['search'] ?? null;
        $venueId          = $data['venue_id'] ?? null;
        $recordsPerPage   = (int) ($data['records_per_page'] ?? 10);
        $sportsCategoryId = $data['sports_category_id'] ?? null;

        // Temporary compatibility bump for already-released admin mobile app builds
        // that request 30 records per page on initial fetch.
        $requestFrom = $request->header('from');
        if ($requestFrom === \MobileApps::ADMIN && $recordsPerPage === 30) {
            $recordsPerPage += 10;
        }

        $query = $this->listingModel::with([
            'venue' => function ($query) {
                $query->select('id', 'name', 'address', 'maps_url', 'description', 'country_id', 'state_id', 'city_id')
                    ->with([
                        'country:id,name,iso2',
                        'state:id,name',
                        'city:id,name',
                    ]);
            },
            'addOns' => function ($query) {
                $query->active()->orderBy('sort_order', 'ASC');
            },
            'sportsCategory:id,name',
            'assets',
        ])
            ->visibleToAdmins()
            ->whereRelation('venue', 'status', 'active')
            ->whereHas('schedules', function ($q) {
                $q->where('is_open', 1);
            })
            ->orderBy('sort_order', 'ASC')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhereHas('venue', function ($v) use ($search) {
                            $v->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('sportsCategory', function ($sc) use ($search) {
                            $sc->where('name', 'LIKE', "%{$search}%");
                        });
                });
            });

        $query = applyVenueFilter($query, 'venue_id');

        if ($venueId) {
            $user = auth()->user();
            if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
                // AccessScope: ownership + assignment + jurisdiction, one authority.
                $venueIds = AccessScope::allowedVenueIds($user) ?? [];
                if (! in_array($venueId, $venueIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where('venue_id', $venueId);
                }
            } else {
                $query->where('venue_id', $venueId);
            }
        }

        $query->when($sportsCategoryId, function ($query, $sportsCategoryId) {
            $query->where('sports_category_id', $sportsCategoryId);
        });

        $listings = $query->paginate($recordsPerPage);

        // A price without a currency is just a number; the app refuses to guess
        // one (see utils/money.js). Resolved once per venue, not per listing.
        $currencyByVenue = [];
        $currencyFor = function ($venueId) use (&$currencyByVenue) {
            $key = (string) $venueId;
            if (! array_key_exists($key, $currencyByVenue)) {
                $currencyByVenue[$key] = Booking::currencyForVenue($venueId);
            }
            return $currencyByVenue[$key];
        };

        $listings->getCollection()->transform(function ($listing) use ($currencyFor) {
            $currency = $currencyFor($listing->venue_id);

            return [
                'id'                      => $listing->id,
                'name'                    => $listing->name,
                'description'             => $listing->description,
                'base_price'              => $listing->base_price,
                'currency'                => $currency,
                'slot_duration'           => $listing->slot_duration,
                'duration'                => getFormattedDuration($listing->slotDurationMinutes()),
                'capacity'                => $listing->capacity,
                'loyalty_points'          => $listing->loyalty_points,
                'payment_options'         => $listing->payment_options,
                'images'                  => $listing->images_urls ?? [],
                'amenities'               => $listing->amenities ?? [],
                'tags'                    => $listing->tags ?? [],
                'max_multiple_bookings'   => $listing->max_multiple_bookings,
                'allow_multiple_bookings' => $listing->allow_multiple_bookings,
                'rating'                  => round($listing->average_rating, 1),
                'total_reviews'           => $listing->total_reviews,
                'venue'                   => [
                    'id'          => $listing->venue?->id,
                    'name'        => $listing->venue?->name,
                    'address'     => $listing->venue?->address,
                    'currency'    => $currency,
                    'maps_url'    => $listing->venue?->maps_url,
                    'description' => $listing->venue?->description,
                    'location'    => $this->formatVenueLocationPayload($listing->venue),
                ],
                'addons'                  => $listing->addOns->map(function ($addon) {
                    return [
                        'id'              => $addon->id,
                        'name'            => $addon->name,
                        'image'           => $addon->image,
                        'price'           => $addon->price ?? 0,
                        'description'     => $addon->description,
                        'hours_extension' => $addon->hours_extension,
                    ];
                }),
                'sports_category'         => [
                    'id'   => $listing->sportsCategory?->id,
                    'name' => $listing->sportsCategory?->name,
                ],
            ];
        });

        return sendSuccess('Listings fetched successfully', [
            'data'       => $listings->items(),
            'pagination' => [
                'current_page' => $listings->currentPage(),
                'last_page'    => $listings->lastPage(),
                'per_page'     => $listings->perPage(),
                'total'        => $listings->total(),
                'from'         => $listings->firstItem(),
                'to'           => $listings->lastItem(),
            ],
        ]);
    }

    public function listing($id)
    {
        $date = request('date', now()->toDateString());

        $listing = $this->listingModel::with([
            'venue' => function ($query) {
                $query->select('id', 'name', 'address', 'maps_url', 'description', 'country_id', 'state_id', 'city_id')
                    ->with([
                        'country:id,name,iso2',
                        'state:id,name',
                        'city:id,name',
                    ]);
            },
            'sportsCategory:id,name',
            'assets',
        ])
            ->visibleToRequestSurface()
            ->findOrFail($id);

        $specialDay = SpecialDay::with('timeBlocks')
            ->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->whereDate('date', $date)->active()->first();

        $isHoliday = $specialDay?->type === 'holiday';

        $depositPaymentTimeoutMinutes = (int) (Setting::where('key', 'deposit_payment_timeout_minutes')->value('value') ?: 10);

        $listing = [
            'is_holiday'                      => $isHoliday,
            'deposit_payment_timeout_minutes' => $depositPaymentTimeoutMinutes,
            'id'                              => $listing->id,
            'name'                    => $listing->name,
            'venue_id'                => $listing->venue_id,
            'capacity'                => $listing->capacity,
            'price'                   => $listing->base_price,
            'description'             => $listing->description,
            'venue'                   => $listing->venue?->name,
            'venue_location'          => $this->formatVenueLocationPayload($listing->venue),
            'addons'                  => $listing->addons ?? [],
            'duration'                => $listing->slot_duration,
            'loyalty_points'          => $listing->loyalty_points,
            'calendar_color'          => $listing->calendar_color,
            'online_payment'          => $listing->online_payment,
            'payment_options'         => $listing->payment_options,
            'amenities'               => $listing->amenities ?? [],
            'special_day'             => $specialDay?->description,
            'map_address'             => $listing->venue?->maps_url,
            'images'                  => $listing->images_urls ?? [],
            'sport'                   => $listing->sportsCategory?->name,
            'max_multiple_bookings'   => $listing->max_multiple_bookings,
            'allow_multiple_bookings' => $listing->allow_multiple_bookings,
            'rating'                  => round($listing->average_rating, 1),
            'tags'                    => $listing->tags ?? [],
        ];

        return sendSuccess('Listing fetched successfully', compact('listing'));
    }

    public function fetchTimeSlots($request)
    {
        $request->validate([
            "listing_id" => "required|numeric|exists:listings,id",
            "date"       => "required|date|after_or_equal:today",
        ], [
            "date.after_or_equal" => "You cannot view time slots for a past date.",
        ]);

        $user      = auth()->user();
        $ipAddress = $request->ip();
        $listingId = $request->listing_id;
        // $request, not the global helper: this method is also called internally with
        // a request built by the caller, and reading the global one silently served
        // TODAY's slots for whatever date was asked for.
        $date      = Carbon::parse($request->input('date', now()->toDateString()));
        $sessionId = getSessionId();

        $requestFrom          = $request->header('from');
        $user                 = auth()->user();
        $isAdminFromMobileApp = $requestFrom === \MobileApps::ADMIN;

        // Skip date availability check for admins from admin_mobile_app
        if ($requestFrom === 'frontend-web' && ! $isAdminFromMobileApp && ! isDateAvailableForBooking($date)) {
            // Not an outage and not a closed day — the calendar simply has not
            // been released yet. The structured fields let a client say so
            // without parsing the sentence.
            return sendError(slotsNotOpenYetMessage($date), [
                'reason'   => 'not_open_yet',
                'opens_on' => nextMonthBookingsOpenOn()->toDateString(),
            ]);
        }

        $listing = $this->listingModel::with([
            'venue',
            'schedules',
            'pricingGroups',
            'venue.schedules',
            'assets',
        ])->findOrFail($listingId);

        /*
         * Every price below is a bare number, and a bare number is not a price —
         * 250 QAR and 250 PKR are not remotely the same thing to the customer
         * reading the slot. Callers were left to guess the currency from
         * somewhere else, and a storefront guessing wrong puts the wrong symbol
         * on a real checkout. Resolve it the same way a booking does when it is
         * actually charged (venue override -> its country -> platform default),
         * so the slot list and the charge can never disagree.
         */
        $currency = Booking::currencyForVenue($listing->venue_id);

        if (! $listing->isVisibleToRequestSurface() || ! $listing->venue || $listing->venue->status !== 'active') {
            return sendSuccess('No available schedule for this day', ['timeSlots' => [], 'currency' => $currency]);
        }

        $weekday     = strtolower($date->format('l'));
        $slotMinutes = $listing->slotDurationMinutes();

        $venueSchedules = $listing->venue?->schedules
            ->where('day', $weekday)
            ->where('is_open', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->sortBy('start_time')
            ->values();

        $listingSchedules = $listing->schedules
            ->where('day', $weekday)
            ->where('is_open', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->sortBy('start_time')
            ->values();

        // A custom-slot listing is open exactly when its slots are: its weekly
        // rows no longer gate anything. The venue's hours still do — a court
        // cannot sell while the venue itself is shut.
        $customSlots = $listing->startTimeMode() === Listing::START_TIME_CUSTOM;

        if ($venueSchedules->isEmpty() || (! $customSlots && $listingSchedules->isEmpty())) {
            return sendSuccess('No available schedule for this day', ['timeSlots' => [], 'currency' => $currency]);
        }

        $specialDay = SpecialDay::with('timeBlocks')
            ->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->whereDate('date', $date)
            ->active()
            ->first();

        $isHoliday = $specialDay?->type === 'holiday';

        if ($isHoliday) {
            return sendSuccess('No available schedule for this day (Holiday)', ['timeSlots' => [], 'currency' => $currency]);
        }

        if ($specialDay && $specialDay->timeBlocks->isNotEmpty()) {
            $availableBlocks = $specialDay->timeBlocks
                ->sortBy('start_time')
                ->map(function ($block) {
                    return [
                        'start_time' => $block->start_time,
                        'end_time'   => $block->end_time,
                    ];
                })
                ->values();
        } else {
            $availableBlocks = $this->findOverlappingScheduleBlocks(
                $customSlots ? $venueSchedules : $listingSchedules,
                $venueSchedules
            );
        }

        if ($availableBlocks->isEmpty()) {
            return sendSuccess('No available schedule for this day', ['timeSlots' => [], 'currency' => $currency]);
        }

        // If the previous day's schedule generated any slot that crosses
        // midnight into today (e.g. yesterday's 23:00 -> 01:00 slot spilling
        // into today 00:00-01:00), advance today's first block past that
        // spillover so today's slots never physically overlap yesterday's tail.
        $prevSpilloverMinutes = $this->computePrevDaySpilloverMinutes($listing, $date, $slotMinutes);
        if ($prevSpilloverMinutes > 0) {
            $availableBlocks = $this->trimBlocksByLeadingMinutes($availableBlocks, $prevSpilloverMinutes);
            if ($availableBlocks->isEmpty()) {
                return sendSuccess('No available schedule for this day', ['timeSlots' => [], 'currency' => $currency]);
            }
        }

        $prevDate = $date->copy()->subDay()->toDateString();

        $venueBookings = Booking::where('venue_id', $listing->venue_id)
            ->where('date', $date)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
            ->with(['resourcesAllocated', 'listing' => function ($q) {
                $q->with('resources');
            }])
            ->get(['id', 'listing_id', 'start_time', 'end_time', 'status']);

        // Bookings from the previous day that cross midnight spill into today's
        // early-morning slots. Load them and virtualize their start to 00:00 so
        // the same slotsOverlap logic can detect them against today's slots.
        $prevDayCrossMidnightBookings = Booking::where('venue_id', $listing->venue_id)
            ->where('date', $prevDate)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
            ->whereRaw('TIME(end_time) < TIME(start_time)')
            ->whereRaw("TIME(end_time) > '00:00:00'")
            ->with(['resourcesAllocated', 'listing' => function ($q) {
                $q->with('resources');
            }])
            ->get(['id', 'listing_id', 'start_time', 'end_time', 'status']);

        $prevDayCrossMidnightBookings->each(function ($b) {
            $b->start_time = '00:00';
        });

        $venueBookings = $venueBookings->concat($prevDayCrossMidnightBookings);

        $venueLocks = SlotLock::whereHas('listing', function ($query) use ($listing) {
            $query->where('venue_id', $listing->venue_id);
        })
            ->where('date', $date->toDateString())
            ->active()
            ->with(['listing' => function ($q) {
                $q->with('resources');
            }])
            ->get();

        $prevDayCrossMidnightLocks = SlotLock::whereHas('listing', function ($query) use ($listing) {
            $query->where('venue_id', $listing->venue_id);
        })
            ->where('date', $prevDate)
            ->active()
            ->whereRaw('TIME(end_time) < TIME(start_time)')
            ->whereRaw("TIME(end_time) > '00:00:00'")
            ->with(['listing' => function ($q) {
                $q->with('resources');
            }])
            ->get();

        $prevDayCrossMidnightLocks->each(function ($lock) {
            $lock->start_time = '00:00';
        });

        $venueLocks = $venueLocks->concat($prevDayCrossMidnightLocks);

        $timeSlots = collect();
        foreach ($availableBlocks as $block) {
            $slots = $this->generateTimeSlots(
                $block['start_time'],
                $block['end_time'],
                $slotMinutes,
                $listing,
                $weekday,
                $specialDay,
                $venueBookings,
                $isHoliday,
                $venueLocks,
                $sessionId,
                $date
            );
            $timeSlots = $timeSlots->merge($slots);
        }

        $timeSlots = $timeSlots->sortBy('start_time')->values();

        return sendSuccess('Listing slots fetched successfully', ['timeSlots' => $timeSlots, 'currency' => $currency]);
    }

    /**
     * Turn a booking/lock row into an absolute [start, end) datetime interval,
     * adding a day to the end when end_time <= start_time (cross-midnight or
     * ends exactly at 00:00). Returns null on unparseable input.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    protected function rowAbsoluteInterval($date, $startTime, $endTime): ?array
    {
        try {
            $dateStr = $date instanceof Carbon
                ? $date->format('Y-m-d')
                : Carbon::parse($date)->format('Y-m-d');

            $startStr = substr((string) $startTime, 0, 5);
            $endStr   = substr((string) $endTime, 0, 5);

            $start = Carbon::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $startStr);
            $end   = Carbon::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $endStr);

            if ($end->lte($start)) {
                $end->addDay();
            }

            return ['start' => $start, 'end' => $end];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * How many minutes past midnight of $date are occupied by a slot generated
     * from the previous day's schedule (a "cross-midnight tail"). Returns 0
     * when no prev-day slot spills into $date.
     */
    protected function computePrevDaySpilloverMinutes(Listing $listing, Carbon $date, int $slotMinutes): int
    {
        if ($slotMinutes <= 0) {
            return 0;
        }

        $prevDate    = $date->copy()->subDay();
        $prevWeekday = strtolower($prevDate->format('l'));

        $prevVenueSchedules = $listing->venue?->schedules
            ?->where('day', $prevWeekday)
            ->where('is_open', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->sortBy('start_time')
            ->values();

        $prevListingSchedules = $listing->schedules
            ->where('day', $prevWeekday)
            ->where('is_open', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->sortBy('start_time')
            ->values();

        $customSlots = $listing->startTimeMode() === Listing::START_TIME_CUSTOM;

        if (! $prevVenueSchedules || $prevVenueSchedules->isEmpty() || (! $customSlots && $prevListingSchedules->isEmpty())) {
            return 0;
        }

        $prevSpecialDay = SpecialDay::with('timeBlocks')
            ->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->whereDate('date', $prevDate)
            ->active()
            ->first();

        if ($prevSpecialDay && $prevSpecialDay->type === 'holiday') {
            return 0;
        }

        if ($prevSpecialDay && $prevSpecialDay->timeBlocks->isNotEmpty()) {
            $prevBlocks = $prevSpecialDay->timeBlocks
                ->sortBy('start_time')
                ->map(function ($b) {
                    return ['start_time' => $b->start_time, 'end_time' => $b->end_time];
                })
                ->values();
        } else {
            $prevBlocks = $this->findOverlappingScheduleBlocks($customSlots ? $prevVenueSchedules : $prevListingSchedules, $prevVenueSchedules);
        }

        if ($listing->startTimeMode() === Listing::START_TIME_CUSTOM) {
            return $this->computeCustomSpilloverMinutes($listing, $prevWeekday, $prevBlocks);
        }

        $max = 0;
        foreach ($prevBlocks as $block) {
            $spill = $this->computeBlockSpilloverMinutes($block, $slotMinutes);
            if ($spill > $max) {
                $max = $spill;
            }
        }

        return $max;
    }

    /**
     * Custom slots have no uniform step to extrapolate from: the previous day
     * spills exactly as far as its latest midnight-crossing slot runs, and only
     * when that slot starts inside one of the day's open blocks.
     */
    protected function computeCustomSpilloverMinutes(Listing $listing, string $prevWeekday, $prevBlocks): int
    {
        $max = 0;
        foreach ($listing->customSlotsForWeekday($prevWeekday) as $slot) {
            if ($slot['end_time'] === '00:00') {
                continue;   // ends exactly at midnight: nothing spills
            }
            $s = $this->timeToMinutes($slot['start_time']);
            $e = $this->timeToMinutes($slot['end_time']);
            if ($e > $s) {
                continue;   // same-day slot
            }
            foreach ($prevBlocks as $block) {
                $bs = $this->timeToMinutes($block['start_time']);
                $be = $block['end_time'] === '00:00' ? 24 * 60 : $this->timeToMinutes($block['end_time']);
                if ($be <= $bs) {
                    $be += 24 * 60;
                }
                if ($s >= $bs && $s < $be) {
                    $max = max($max, $e);
                    break;
                }
            }
        }

        return $max;
    }

    /**
     * Mirrors generateTimeSlots() enough to figure out how far past midnight
     * the block's last generated slot would extend into the following day.
     */
    protected function computeBlockSpilloverMinutes(array $block, int $slotMinutes): int
    {
        $startMin = $this->timeToMinutes($block['start_time']);
        $endTime  = $block['end_time'];

        if ($endTime === '00:00') {
            // Midnight-ending block: generateTimeSlots emits while start < end
            // (early break is disabled for this case), so a slot may spill.
            $effectiveEnd  = 24 * 60;
            $endIsMidnight = true;
        } else {
            $endMin = $this->timeToMinutes($endTime);
            if ($endMin <= $startMin) {
                // Block explicitly crosses midnight (special-day style).
                $effectiveEnd  = $endMin + 24 * 60;
                $endIsMidnight = false;
            } else {
                return 0;
            }
        }

        if ($effectiveEnd <= $startMin) {
            return 0;
        }

        if ($endIsMidnight) {
            $numSlots = intdiv($effectiveEnd - $startMin - 1, $slotMinutes) + 1;
        } else {
            $numSlots = intdiv($effectiveEnd - $startMin, $slotMinutes);
        }

        if ($numSlots <= 0) {
            return 0;
        }

        $lastSlotEnd = $startMin + $numSlots * $slotMinutes;
        return $lastSlotEnd > 24 * 60 ? $lastSlotEnd - 24 * 60 : 0;
    }

    /**
     * Advance the start of every block past $leadingMinutes and drop blocks
     * that are entirely before the cutoff. $leadingMinutes is measured from
     * midnight of the block's own day.
     */
    protected function trimBlocksByLeadingMinutes($blocks, int $leadingMinutes)
    {
        if ($leadingMinutes <= 0 || $blocks->isEmpty()) {
            return $blocks;
        }

        return $blocks->map(function ($block) use ($leadingMinutes) {
            $startMin = $this->timeToMinutes($block['start_time']);
            $endTime  = $block['end_time'];

            if ($endTime === '00:00') {
                $effectiveEnd = 24 * 60;
            } else {
                $endMin       = $this->timeToMinutes($endTime);
                $effectiveEnd = $endMin <= $startMin ? $endMin + 24 * 60 : $endMin;
            }

            if ($effectiveEnd <= $leadingMinutes) {
                return null;
            }

            if ($startMin < $leadingMinutes) {
                $hours              = intdiv($leadingMinutes, 60);
                $mins               = $leadingMinutes % 60;
                $block['start_time'] = sprintf('%02d:%02d', $hours, $mins);
            }

            return $block;
        })->filter()->values();
    }

    protected function findOverlappingScheduleBlocks($listingSchedules, $venueSchedules)
    {
        $overlappingBlocks = collect();

        foreach ($listingSchedules as $listingSchedule) {
            $listingStart = Carbon::parse($listingSchedule->start_time);
            $listingEnd   = Carbon::parse($listingSchedule->end_time);

            if ($listingEnd->format('H:i') === '00:00') {
                $listingEnd->addDay();
            }

            foreach ($venueSchedules as $venueSchedule) {
                $venueStart = Carbon::parse($venueSchedule->start_time);
                $venueEnd   = Carbon::parse($venueSchedule->end_time);

                if ($venueEnd->format('H:i') === '00:00') {
                    $venueEnd->addDay();
                }

                if ($listingStart->lt($venueEnd) && $listingEnd->gt($venueStart)) {
                    $overlapStart = $listingStart->gt($venueStart) ? $listingStart : $venueStart;
                    $overlapEnd   = $listingEnd->lt($venueEnd) ? $listingEnd : $venueEnd;

                    if ($overlapStart->lt($overlapEnd)) {
                        $overlapEndFormatted = $overlapEnd->format('H:i');

                        if (($listingSchedule->end_time === '00:00' || $venueSchedule->end_time === '00:00') && $overlapEnd->day > $overlapStart->day) {
                            $overlapEndFormatted = '00:00';
                        }

                        $overlappingBlocks->push([
                            'start_time' => $overlapStart->format('H:i'),
                            'end_time'   => $overlapEndFormatted,
                        ]);
                    }
                }
            }
        }

        return $this->mergeAdjacentBlocks($overlappingBlocks->sortBy('start_time')->values());
    }

    protected function mergeAdjacentBlocks($blocks)
    {
        if ($blocks->isEmpty()) {
            return $blocks;
        }

        $merged  = collect();
        $current = $blocks->first();

        foreach ($blocks->skip(1) as $block) {
            $currentEndTime = $current['end_time'];
            $blockStartTime = $block['start_time'];

            $shouldMerge = false;

            if ($currentEndTime === '00:00' && $blockStartTime === '00:00') {
                $shouldMerge = true;
            } elseif ($blockStartTime === '00:00') {
                $currentEnd  = Carbon::parse($currentEndTime);
                $shouldMerge = ($currentEnd->format('H') === '23');
            } elseif ($currentEndTime === '00:00') {
                $shouldMerge = false;
            } else {
                $currentEnd  = Carbon::parse($currentEndTime);
                $blockStart  = Carbon::parse($blockStartTime);
                $shouldMerge = $blockStart->lte($currentEnd);
            }

            if ($shouldMerge) {
                $currentEnd = Carbon::parse($currentEndTime);
                $blockEnd   = Carbon::parse($block['end_time']);

                if ($currentEndTime === '00:00') {
                    $currentEnd->addDay();
                }
                if ($block['end_time'] === '00:00') {
                    $blockEnd->addDay();
                }

                if ($currentEnd->gte($blockEnd)) {
                    $current['end_time'] = $currentEndTime;
                } else {
                    $current['end_time'] = $block['end_time'];
                }
            } else {
                $merged->push($current);
                $current = $block;
            }
        }

        $merged->push($current);
        return $merged;
    }

    protected function generateTimeSlots($startTime, $endTime, $slotMinutes, $listing, $weekday, $specialDay, $venueBookings, $isHoliday, $venueLocks = null, $sessionId = null, $date = null)
    {
        $start = Carbon::parse($startTime);
        $end   = Carbon::parse($endTime);

        if ($endTime === '00:00' || $end->lte($start)) {
            $end->addDay();
        }

        $slots = collect();

        $listingResources = $this->listingResourceRequirements($listing);

        // Null for staff surfaces and for listings without a lead time. The
        // loop's Carbon carries an implicit "today", so `$loopOrigin` lets a
        // slot that has rolled past midnight be dated on the right day.
        $minAdvanceCutoff = $listing->minAdvanceCutoff();
        $loopOrigin       = $start->copy()->startOfDay();

        // Which windows this block sells depends on the listing's slot mode —
        // equal pieces, a subset of them, or the provider's own list. Everything
        // after this point treats a candidate the same way whatever produced it.
        foreach ($this->slotCandidates($start, $end, $endTime, $slotMinutes, $listing, $weekday) as [$slotStartCarbon, $slotEndCarbon, $slotLength]) {
            $start     = $slotStartCarbon;
            $slotStart = $start->format('H:i');
            $slotEnd   = $slotEndCarbon->format('H:i');

            $activeBookings = $venueBookings->filter(fn($b) => $b->status !== BookingStatus::CANCELLED && $b->status !== BookingStatus::COMPLETED);

            // Determine which bookings overlap this slot, correctly handling midnight (00:00)
            $slotBookings = $activeBookings->filter(function ($b) use ($slotStart, $slotEnd) {
                $bookingStart = $b->start_time ? Carbon::parse($b->start_time)->format('H:i') : null;
                $bookingEnd   = $b->end_time ? Carbon::parse($b->end_time)->format('H:i') : null;

                return $this->slotsOverlap(
                    $slotStart,
                    $slotEnd,
                    $bookingStart,
                    $bookingEnd
                );
            });

            $bookedCount = $slotBookings->where('listing_id', $listing->id)->count();

            $lockedCount = 0;
            $slotLocks   = collect();
            if ($venueLocks && $sessionId) {
                $slotLocks = $venueLocks->filter(function ($lock) use ($slotStart, $slotEnd, $sessionId) {
                    if ($lock->session_id === $sessionId) {
                        return false;
                    }
                    return $this->slotsOverlap(
                        $slotStart,
                        $slotEnd,
                        $lock->start_time ? Carbon::parse($lock->start_time)->format('H:i') : null,
                        $lock->end_time ? Carbon::parse($lock->end_time)->format('H:i') : null
                    );
                });
                $lockedCount = $slotLocks->where('listing_id', $listing->id)->count();
            }

            $occupiedSpots = $bookedCount + $lockedCount;

            // What the shared pool still has left after every listing that draws on it.
            $resourceAvailability = $this->calculateResourceAvailability($listingResources, $slotBookings, $slotLocks);
            $resourceCapacity     = $resourceAvailability['capacity'];
            $availableQty         = $resourceAvailability['available_units'];

            $availableCount = max((int) $listing->max_bookings_per_slot - $occupiedSpots, 0);

            // The shared pool can be tighter than this listing's own ceiling: with
            // 3 of 6 courts taken by another sport, a listing allowing 6 can only
            // offer 3 more.
            if ($resourceCapacity !== null) {
                $availableCount = min($availableCount, $resourceCapacity);
            }

            $isLocked = ((int) $listing->max_bookings_per_slot === 1 && $lockedCount > 0);

            $isPastSlot = false;
            if ($date) {
                $dateCarbon = $date instanceof Carbon ? $date : Carbon::parse($date);

                // A slot that has rolled past midnight belongs to the next day.
                $dayOffset     = (int) $loopOrigin->diffInDays($start->copy()->startOfDay());
                $slotStartTime = $dateCarbon->copy()
                    ->startOfDay()
                    ->addDays($dayOffset)
                    ->setTimeFromTimeString($slotStart);

                if ($dateCarbon->isToday() && $slotStartTime->lt(Carbon::now())) {
                    $isPastSlot = true;
                }

                // The lead time can reach into later days too: a 48-hour rule
                // hides tomorrow's slots, not just the rest of today.
                if (! $isPastSlot && $minAdvanceCutoff && $slotStartTime->lt($minAdvanceCutoff)) {
                    $isPastSlot = true;
                }
            }

            if ($isPastSlot) {
                continue;
            }

            // An exhausted pool now shows up as availableCount 0, so no separate resource flag is needed.
            $isAvailable = ! $isHoliday && $availableCount > 0 && ! $isLocked;

            if ($isAvailable || $isLocked) {

                $pricing = resolveSlotPricing($listing, $weekday, $slotStart, $slotEnd, $specialDay);
                $price   = $pricing['price'];

                $duration = getFormattedDuration($slotLength);

                $slots->push([
                    'start_time'          => $slotStart,
                    'end_time'            => $slotEnd,
                    'duration'            => $duration,
                    'is_holiday'          => $isHoliday,
                    'available'           => $isAvailable,
                    'is_locked'           => $isLocked,
                    'booked'              => $bookedCount,
                    'locked'              => $lockedCount,
                    'occupied_spots'      => $occupiedSpots,
                    'available_resources' => $availableQty,
                    'available_count'     => $availableCount,
                    'capacity'            => $listing->capacity,
                    'time'                => "{$slotStart} - {$slotEnd}",
                    'max_bookings_per_slot' => $listing->max_bookings_per_slot,
                    'price'                 => round($price, 2),
                    // Why this slot costs what it costs: which pricing group,
                    // grid cell or special day won, and the base it moved from.
                    // Compact on purpose — the full breakdown repeats the matched
                    // group's definition on every slot of every day.
                    'pricing'               => compactSlotPricing($pricing['breakdown']),
                ]);
            }
        }

        return $slots->values();
    }

    /**
     * The [start, end, minutes] windows one open block offers, by slot mode.
     *
     *   auto   — step through the block in slot_duration pieces.
     *   fixed  — the same pieces, keeping only the starts the provider ticked.
     *   custom — the provider's own slots that begin inside the block. Each has
     *            its own length, and like an automatic slot it may run past a
     *            block that ends at midnight.
     *
     * $start/$end carry the block's day, so a custom slot that crosses midnight
     * (23:00–01:00) ends on the following calendar day, as a booking would.
     */
    protected function slotCandidates(Carbon $start, Carbon $end, string $endTime, int $slotMinutes, Listing $listing, string $weekday): array
    {
        $mode = $listing->startTimeMode();
        $out  = [];

        if ($mode === Listing::START_TIME_CUSTOM) {
            foreach ($listing->customSlotsForWeekday($weekday) as $slot) {
                $s = $start->copy()->setTimeFromTimeString($slot['start_time']);
                $e = $start->copy()->setTimeFromTimeString($slot['end_time']);
                if ($e->lte($s)) {
                    $e->addDay();   // 00:00 means midnight; an earlier end crosses it
                }
                if ($s->lt($start)) {
                    // A block that itself crosses midnight (special-day style)
                    // reaches the early hours of the next calendar day.
                    $s->addDay();
                    $e->addDay();
                }
                if ($s->lt($start) || $s->gte($end)) {
                    continue;
                }
                if ($endTime !== '00:00' && $e->gt($end)) {
                    continue;
                }
                $out[] = [$s, $e, (int) $s->diffInMinutes($e)];
            }

            return $out;
        }

        $fixed  = $mode === Listing::START_TIME_FIXED ? $listing->fixedStartTimes() : null;
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $slotEnd = $cursor->copy()->addMinutes($slotMinutes);
            if ($endTime !== '00:00' && $slotEnd->gt($end)) {
                break;
            }
            if ($fixed === null || in_array($cursor->format('H:i'), $fixed, true)) {
                $out[] = [$cursor->copy(), $slotEnd, $slotMinutes];
            }
            $cursor->addMinutes($slotMinutes);
        }

        return $out;
    }

    public function fetchRecurringBookedSlots($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'start_date' => 'required|date',
            'end_date'   => 'required|date',
            'date'       => 'required|date',
        ]);

        try {
            $listingId = $request->listing_id;
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $startDate = Carbon::parse($request->start_date);
            $endDate   = Carbon::parse($request->end_date);
            $date      = Carbon::parse($request->date);

            $listing = $this->listingModel->with([
                'venue',
                'schedules',
                'pricingGroups',
                'venue.schedules',
            ])->findOrFail($listingId);

            if (! $listing->isVisibleToRequestSurface() || ! $listing->venue || $listing->venue->status !== 'active') {
                return sendSuccess('Recurring booked slots fetched successfully', [
                    'listing_id'            => $listingId,
                    'start_time'            => $startTime,
                    'end_time'              => $endTime,
                    'date_range'            => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date'   => $endDate->format('Y-m-d'),
                    ],
                    'max_bookings_per_slot' => 0,
                    'dates'                 => [],
                ]);
            }

            $maxBookingsPerSlot = $listing->max_bookings_per_slot ?? 1;

            $startDate = $startDate->lt($date) ? $date : $startDate;
            $endDate   = $endDate->lt($date) ? $date : $endDate;

            // Widen the fetch by one day on each side so cross-midnight rows
            // from the day before startDate can be attributed to startDate, and
            // rows on endDate that spill past midnight (e.g. cross-midnight
            // request windows) are also considered against endDate.
            $fetchFrom = $startDate->copy()->subDay()->toDateString();
            $fetchTo   = $endDate->copy()->addDay()->toDateString();

            $bookings = Booking::where('listing_id', $listingId)
                ->whereBetween('date', [$fetchFrom, $fetchTo])
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->get(['id', 'date', 'start_time', 'end_time']);

            $locks = SlotLock::where('listing_id', $listingId)
                ->whereBetween('date', [$fetchFrom, $fetchTo])
                ->active()
                ->get(['id', 'date', 'start_time', 'end_time']);

            // Precompute absolute [start, end) datetimes for every row so we
            // can do fast overlap checks per recurrence date below.
            $bookingIntervals = $bookings->map(function ($b) {
                return $this->rowAbsoluteInterval($b->date, $b->start_time, $b->end_time);
            })->filter()->values();

            $lockIntervals = $locks->map(function ($l) {
                return $this->rowAbsoluteInterval($l->date, $l->start_time, $l->end_time);
            })->filter()->values();

            $countOverlaps = function ($intervals, $windowStart, $windowEnd) {
                $count = 0;
                foreach ($intervals as $iv) {
                    if ($iv['start']->lt($windowEnd) && $iv['end']->gt($windowStart)) {
                        $count++;
                    }
                }
                return $count;
            };

            $specialDays = SpecialDay::with('timeBlocks')
                ->where('venue_id', $listing->venue_id)
                ->where(function ($q) use ($listing) {
                    $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                })
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->active()
                ->get()
                ->keyBy(function ($day) {
                    return Carbon::parse($day->date)->format('Y-m-d');
                });

            $datesInfo   = [];
            $currentDate = $startDate->copy();
            $requestFrom = $request->header('from');

            while ($currentDate->lte($endDate)) {
                if ($currentDate->lt($date)) {
                    $currentDate->addDay();
                    continue;
                }

                if ($requestFrom === 'frontend-web' && ! isDateAvailableForBooking($currentDate)) {
                    $currentDate->addDay();
                    continue;
                }

                // Inside the listing's lead time, so it is not offered at all
                // rather than offered and then refused at checkout.
                if (minAdvanceBookingError($listing, $currentDate, $startTime)) {
                    $currentDate->addDay();
                    continue;
                }

                $dateStr = $currentDate->format('Y-m-d');

                // Absolute window for the recurring slot on this date, honouring
                // cross-midnight (endTime <= startTime).
                $windowStart = Carbon::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $startTime);
                $windowEnd   = Carbon::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $endTime);
                if ($windowEnd->lte($windowStart)) {
                    $windowEnd->addDay();
                }

                $bookedCount   = $countOverlaps($bookingIntervals, $windowStart, $windowEnd);
                $lockedCount   = $countOverlaps($lockIntervals, $windowStart, $windowEnd);
                $occupiedSpots = $bookedCount + $lockedCount;
                $weekday       = strtolower($currentDate->format('l'));

                $venueSchedules = $listing->venue?->schedules
                    ->where('day', $weekday)
                    ->where('is_open', true)
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->values();

                $listingSchedules = $listing->schedules
                    ->where('day', $weekday)
                    ->where('is_open', true)
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->values();

                $isOpenOnDay = ! $venueSchedules->isEmpty() && ! $listingSchedules->isEmpty();

                $specialDay = $specialDays->get($dateStr);
                $isHoliday  = $specialDay && $specialDay->type === 'holiday';

                $isTimeSlotAvailable = false;
                if ($isOpenOnDay && ! $isHoliday) {
                    if ($specialDay && $specialDay->timeBlocks->isNotEmpty()) {
                        $isTimeSlotAvailable = $specialDay->timeBlocks->contains(function ($block) use ($startTime, $endTime) {
                            $blockStart = $block->start_time;
                            $blockEnd   = $block->end_time;
                            return $this->slotsOverlap($startTime, $endTime, $blockStart, $blockEnd);
                        });
                    } else {
                        $overlappingBlocks   = $this->findOverlappingScheduleBlocks($listingSchedules, $venueSchedules);
                        $isTimeSlotAvailable = $overlappingBlocks->contains(function ($block) use ($startTime, $endTime) {
                            return $this->slotsOverlap($startTime, $endTime, $block['start_time'], $block['end_time']);
                        });
                    }
                }

                $isAvailable = $isOpenOnDay && ! $isHoliday && $isTimeSlotAvailable;

                $dayPrice = determineSlotPrice($listing, $weekday, $startTime, $endTime, $specialDay);

                if (! $isAvailable) {
                    $occupiedSpots = $maxBookingsPerSlot;
                }

                $datesInfo[] = [
                    'date'            => $dateStr,
                    'booked_count'    => $bookedCount,
                    'locked_count'    => $lockedCount,
                    'occupied_spots'  => $occupiedSpots,
                    'max_bookings'    => $maxBookingsPerSlot,
                    'price'           => round($dayPrice, 2),
                    'is_available'    => $isAvailable && ($occupiedSpots < $maxBookingsPerSlot),
                    'available_slots' => max(0, $maxBookingsPerSlot - $occupiedSpots),
                ];

                $currentDate->addDay();
            }

            return sendSuccess('Recurring booked slots fetched successfully', [
                'listing_id'            => $listingId,
                'start_time'            => $startTime,
                'end_time'              => $endTime,
                'date_range'            => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date'   => $endDate->format('Y-m-d'),
                ],
                'max_bookings_per_slot' => $maxBookingsPerSlot,
                'dates'                 => $datesInfo,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found');
        } catch (Exception $e) {
            Log::error('Booked recurring slots error: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to fetch recurring booked slots: ' . $e->getMessage(), [], 500);
        }
    }

    public function updateSortOrder($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:listings,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates    = $request->input('updates');
        $listingIds = collect($updates)->pluck('id')->toArray();

        $listings = $this->listingModel::whereIn('id', $listingIds)->get(['id']);

        if ($listings->isEmpty()) {
            return sendError('No listings found.');
        }

        if ($listings->count() !== count($listingIds)) {
            return sendError('Some listings not found.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->sort()->values()->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders) || $sortOrders[0] !== 1 || end($sortOrders) !== count($sortOrders)) {
            return sendError('Sort orders must be unique, sequential integers starting from 1.');
        }

        DB::beginTransaction();

        try {
            foreach ($updates as $update) {
                $this->listingModel::where('id', $update['id'])
                    ->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', [
                'data' => ['updated_listings' => $listingIds],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sort order: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to update sort order.');
        }
    }

    protected function validateScheduleTimes(array $schedules): void
    {
        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    continue;
                }

                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    continue;
                }

                if ($endMinutes === 0 && $startMinutes > 0) {
                    continue;
                }

                if ($endMinutes <= $startMinutes && $endMinutes !== 0) {
                    throw ValidationException::withMessages([
                        "schedule.{$index}.end_time" => ["The schedule.{$index}.end_time field must be a date after schedule.{$index}.start_time."],
                    ]);
                }
            }
        }
    }

    protected function timeToMinutes($time)
    {
        if ($time === '00:00' || $time === '24:00') {
            return 0;
        }

        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }

    public function lockSlot($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ], [
            'date.after_or_equal' => 'You cannot lock a slot for a past date.',
            'end_time.after'      => 'End time must be after start time.',
        ]);

        try {

            $user      = auth()->user();
            $ipAddress = $request->ip();
            $sessionId = getSessionId();
            $listingId = $request->listing_id;
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $date      = Carbon::parse($request->date);

            $requestFrom = $request->header('from');
            if ($requestFrom === 'frontend-web' && ! isDateAvailableForBooking($date)) {
                return sendError(slotsNotOpenYetMessage($date), [
                    'reason'   => 'not_open_yet',
                    'opens_on' => nextMonthBookingsOpenOn()->toDateString(),
                ]);
            }

            $listing = $this->listingModel->with('venue')->findOrFail($listingId);

            if (! $listing->isVisibleToRequestSurface() || ! $listing->venue || $listing->venue->status !== 'active') {
                return sendError('Listing is not available for booking');
            }

            if ($minAdvanceError = minAdvanceBookingError($listing, $date, $startTime)) {
                return sendError($minAdvanceError);
            }

            $maxBookingsPerSlot = (int) $listing->max_bookings_per_slot;

            $existingBookingCount = Booking::where('listing_id', $listingId)
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->where(function ($q) use ($date, $startTime, $endTime) {
                    applyBookingOverlapWhere($q, $date->toDateString(), $startTime, $endTime);
                })
                ->count();

            $existingLockCount = SlotLock::where('listing_id', $listingId)
                ->where('session_id', '!=', $sessionId)
                ->active()
                ->where(function ($q) use ($date, $startTime, $endTime) {
                    applyBookingOverlapWhere($q, $date->toDateString(), $startTime, $endTime);
                })
                ->count();

            $occupiedSpots = $existingBookingCount + $existingLockCount;

            if ($maxBookingsPerSlot === 1) {
                if ($existingLockCount > 0) {
                    return sendError('This slot is currently locked by another user. Please try again in a moment.');
                }
                if ($existingBookingCount >= $maxBookingsPerSlot) {
                    return sendError('This slot is already fully booked.');
                }
            } else {
                if ($occupiedSpots >= $maxBookingsPerSlot) {
                    return sendError('This slot is already fully booked. All spots are taken.');
                }
            }

            // The listing has room, but the pool it shares with other listings may not.
            $resourceCapacity = $this->remainingResourceCapacityForSlot(
                $listing,
                $date->toDateString(),
                $startTime,
                $endTime,
                $sessionId
            );

            if ($resourceCapacity !== null && $resourceCapacity < 1) {
                return sendError('This slot is no longer available. All courts are taken for this time.');
            }

            // ?: not ?? - intval() returns 0 rather than null for a missing or
            // non-numeric setting, which would create locks that expire instantly.
            $lockDuration = (int) Setting::where('key', 'default_bookings_slot_lock_time')->value('value') ?: 5;

            $lock = SlotLock::updateOrCreate(
                [
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                    'session_id' => $sessionId,
                    'listing_id' => $listingId,
                    'date'       => $date->toDateString(),
                ],
                [
                    'user_id'      => $user?->id,
                    'ip_address'   => $ipAddress,
                    'locked_until' => now()->addMinutes($lockDuration),
                ]
            );

            return sendSuccess('Slot locked successfully', [
                'lock_id'      => $lock->id,
                'listing_id'   => $listingId,
                'start_time'   => $startTime,
                'end_time'     => $endTime,
                'date'         => $date->toDateString(),
                'locked_until' => $lock->locked_until->toIso8601String(),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (Exception $e) {
            Log::error('Failed to lock slot: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to lock slot: ' . $e->getMessage());
        }
    }

    public function lockSlots($request)
    {
        $request->merge([
            'slots' => collect($request->input('slots', []))->map(fn ($slot) => [
                'start_time' => data_get($slot, 'start_time'),
                'end_time'   => data_get($slot, 'end_time'),
            ])->all(),
        ]);

        $request->validate([
            'listing_id'           => 'required|numeric|exists:listings,id',
            'date'                 => 'required|date|after_or_equal:today',
            'slots'                => 'required|array|min:1',
            'slots.*.start_time'   => 'required|date_format:H:i',
            'slots.*.end_time'     => 'required|date_format:H:i',
        ], [
            'date.after_or_equal' => 'You cannot lock a slot for a past date.',
        ]);

        try {
            $earliestLock = null;
            $locked       = [];

            foreach ($request->slots as $slot) {
                $slot = (array) $slot;
                $singleRequest = Request::create(
                    '/',
                    'POST',
                    [
                        'listing_id' => $request->listing_id,
                        'date'       => $request->date,
                        'start_time' => $slot['start_time'],
                        'end_time'   => $slot['end_time'],
                    ]
                );
                $singleRequest->headers->replace($request->headers->all());

                $result = $this->lockSlot($singleRequest);
                $data   = $result->getData();

                if (! ($data->success ?? false)) {
                    return $result;
                }

                $locked[] = $data->data ?? null;

                $until = $data->data->locked_until ?? null;
                if ($until) {
                    $ts = Carbon::parse($until)->getTimestamp();
                    $earliestLock = $earliestLock ? min($earliestLock, $ts) : $ts;
                }
            }

            return sendSuccess('Slots locked successfully', [
                'locks'        => $locked,
                'locked_until' => $earliestLock ? Carbon::createFromTimestamp($earliestLock)->toIso8601String() : null,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to lock slots: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to lock slots: ' . $e->getMessage());
        }
    }

    public function unlockSlot($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ]);

        try {

            $sessionId = getSessionId();
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $listingId = $request->listing_id;
            $date      = Carbon::parse($request->date);

            $deleted = SlotLock::where('listing_id', $listingId)
                ->where('date', $date->toDateString())
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->where('session_id', $sessionId)
                ->delete();

            if ($deleted) {
                return sendSuccess('Slot unlocked successfully', [
                    'listing_id' => $listingId,
                    'date'       => $date->toDateString(),
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                ]);
            }

            Log::error('No slot lock found to unlock', [
                'listing_id' => $listingId,
                'date'       => $date->toDateString(),
                'start_time' => $startTime,
                'end_time'   => $endTime,
                'session_id' => $sessionId,
            ]);

            return sendSuccess('Slot unlocked successfully', [
                'listing_id' => $listingId,
                'date'       => $date->toDateString(),
                'start_time' => $startTime,
                'end_time'   => $endTime,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to unlock slot: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to unlock slot: ' . $e->getMessage());
        }
    }

    public function unlockAllSlots($request)
    {
        $request->validate([
            'listing_id' => 'nullable|numeric|exists:listings,id',
            'date'       => 'nullable|date',
        ]);

        try {

            $sessionId = getSessionId();

            $query = SlotLock::where('session_id', $sessionId);

            if ($request->has('listing_id')) {
                $query->where('listing_id', $request->listing_id);
            }

            if ($request->has('date')) {
                $query->where('date', Carbon::parse($request->date)->toDateString());
            }

            $deletedCount = $query->delete();

            return sendSuccess('All slots unlocked successfully', [
                'unlocked_count' => $deletedCount,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to unlock all slots: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to unlock all slots: ' . $e->getMessage());
        }
    }

    public function extendSlotLock($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ]);

        try {

            $sessionId = getSessionId();
            $listingId = $request->listing_id;
            $date      = Carbon::parse($request->date);
            $startTime = $request->start_time;
            $endTime   = $request->end_time;

            $lock = SlotLock::where('listing_id', $listingId)
                ->where('date', $date->toDateString())
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->where('session_id', $sessionId)
                ->first();

            if (! $lock) {
                return sendError('Slot lock not found');
            }

            // ?: not ?? - intval() returns 0 rather than null for a missing or
            // non-numeric setting, which would create locks that expire instantly.
            $lockDuration = (int) Setting::where('key', 'default_bookings_slot_lock_time')->value('value') ?: 5;

            $lock->locked_until = now()->addMinutes($lockDuration);
            $lock->save();

            return sendSuccess('Slot lock extended successfully', [
                'lock_id'      => $lock->id,
                'start_time'   => $startTime,
                'end_time'     => $endTime,
                'listing_id'   => $listingId,
                'date'         => $date->toDateString(),
                'locked_until' => $lock->locked_until->toIso8601String(),
            ]);

        } catch (Exception $e) {
            Log::error('Failed to extend slot lock: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to extend slot lock: ' . $e->getMessage(), [], 500);
        }
    }

    public function checkSlotAvailability($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ]);

        try {

            $listingId = $request->listing_id;
            $date      = Carbon::parse($request->date);
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $sessionId = getSessionId();

            if ($startTime === $endTime) {
                return sendError('Start time and end time cannot be the same');
            }

            $requestFrom = $request->header('from');
            if ($requestFrom === 'frontend-web' && ! isDateAvailableForBooking($date)) {
                return sendSuccess('Slot availability checked', [
                    'available' => false,
                    'reason'    => slotsNotOpenYetMessage($date),
                    'opens_on'  => nextMonthBookingsOpenOn()->toDateString(),
                ]);
            }

            $listing = $this->listingModel->with('venue')->findOrFail($listingId);

            if (! $listing->isVisibleToRequestSurface() || ! $listing->venue || $listing->venue->status !== 'active') {
                return sendSuccess('Slot availability checked', [
                    'available' => false,
                    'reason'    => 'Listing is not available',
                ]);
            }

            if ($minAdvanceError = minAdvanceBookingError($listing, $date, $startTime)) {
                return sendSuccess('Slot availability checked', [
                    'available' => false,
                    'reason'    => $minAdvanceError,
                ]);
            }

            $maxBookingsPerSlot = (int) $listing->max_bookings_per_slot;

            $existingBookingCount = Booking::where('listing_id', $listingId)
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->where(function ($q) use ($date, $startTime, $endTime) {
                    applyBookingOverlapWhere($q, $date->toDateString(), $startTime, $endTime);
                })
                ->count();

            $existingLockCount = SlotLock::where('listing_id', $listingId)
                ->where('session_id', '!=', $sessionId)
                ->active()
                ->where(function ($q) use ($date, $startTime, $endTime) {
                    applyBookingOverlapWhere($q, $date->toDateString(), $startTime, $endTime);
                })
                ->count();

            $occupiedSpots  = $existingBookingCount + $existingLockCount;
            $availableSpots = max(0, $maxBookingsPerSlot - $occupiedSpots);

            // Keep this in step with fetch-time-slots: the shared pool can be the tighter limit.
            $resourceCapacity = $this->remainingResourceCapacityForSlot(
                $listing,
                $date->toDateString(),
                $startTime,
                $endTime,
                $sessionId
            );

            if ($resourceCapacity !== null) {
                $availableSpots = min($availableSpots, $resourceCapacity);
            }

            if ($availableSpots <= 0) {
                if ($resourceCapacity !== null && $resourceCapacity < 1) {
                    $reason = 'All courts are taken for this time slot';
                } elseif ($existingBookingCount >= $maxBookingsPerSlot) {
                    $reason = 'Slot is already fully booked';
                } else {
                    $reason = 'All spots in this slot are currently locked';
                }

                return sendSuccess('Slot availability checked', [
                    'available'      => false,
                    'reason'         => $reason,
                    'occupied_spots' => $occupiedSpots,
                    'max_bookings'   => $maxBookingsPerSlot,
                ]);
            }

            return sendSuccess('Slot availability checked', [
                'available'       => true,
                'available_spots' => $availableSpots,
                'occupied_spots'  => $occupiedSpots,
                'max_bookings'    => $maxBookingsPerSlot,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (Exception $e) {
            Log::error('Failed to check slot availability: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to check slot availability: ' . $e->getMessage());
        }
    }

    /**
     * The resources a listing consumes for every booking it takes.
     *
     * Each row carries the shared pool size (total_quantity) and how many
     * units of that pool one booking of this listing uses (required_qty).
     */
    protected function listingResourceRequirements($listing)
    {
        return $listing->resources()->get([
            'resources.id',
            'resources.total_quantity',
            'listing_resource.quantity as required_qty',
        ]);
    }

    /**
     * How much of the shared resource pool is left for one slot.
     *
     * Listings can share a pool - e.g. badminton and pickleball both drawing
     * from the same 6 courts - so a listing's own max_bookings_per_slot is not
     * the whole story: what other listings already took has to be subtracted.
     *
     * @param  $listingResources  what this listing needs (id, total_quantity, required_qty)
     * @param  $slotBookings      bookings overlapping the slot, with resourcesAllocated loaded
     * @param  $slotLocks         other sessions' active locks overlapping the slot, with listing.resources loaded
     * @return array{capacity: int|null, available_units: int|null}
     *         capacity        - how many MORE bookings of this listing the pool allows
     *         available_units - free units of the tightest resource
     *         Both are null when the listing uses no resources, meaning the pool never limits it.
     */
    protected function calculateResourceAvailability($listingResources, $slotBookings, $slotLocks): array
    {
        if ($listingResources->isEmpty()) {
            return ['capacity' => null, 'available_units' => null];
        }

        $capacity       = null;
        $availableUnits = null;

        foreach ($listingResources as $res) {

            // Units held by bookings of any listing sharing this resource.
            $usedByBookings = $slotBookings->sum(function ($booking) use ($res) {
                return $booking->resourcesAllocated
                    ->where('id', $res->id)
                    ->sum(fn($resource) => (int) ($resource->pivot->quantity ?? 0));
            });

            // Units held by other sessions' locks on any listing sharing this resource.
            $usedByLocks = $slotLocks->sum(function ($lock) use ($res) {
                if (! $lock->listing) {
                    return 0;
                }
                return $lock->listing->resources
                    ->where('id', $res->id)
                    ->sum(fn($resource) => (int) ($resource->pivot->quantity ?? 0));
            });

            $freeUnits   = max((int) $res->total_quantity - ($usedByBookings + $usedByLocks), 0);
            $requiredQty = (int) $res->required_qty;

            // A resource that costs nothing per booking can never be the limit.
            $resourceCapacity = $requiredQty > 0 ? intdiv($freeUnits, $requiredQty) : PHP_INT_MAX;

            // The tightest resource decides - so every one of them is visited.
            $capacity       = $capacity === null ? $resourceCapacity : min($capacity, $resourceCapacity);
            $availableUnits = $availableUnits === null ? $freeUnits : min($availableUnits, $freeUnits);
        }

        return ['capacity' => $capacity, 'available_units' => $availableUnits];
    }

    /**
     * Remaining shared-pool capacity for a single slot, read straight from the
     * database. Used by the lock/check endpoints, which handle one slot at a
     * time and so have no pre-loaded collections to work from.
     *
     * Returns null when the listing uses no resources (nothing to limit it).
     */
    protected function remainingResourceCapacityForSlot($listing, string $date, string $startTime, string $endTime, ?string $sessionId): ?int
    {
        $listingResources = $this->listingResourceRequirements($listing);

        if ($listingResources->isEmpty()) {
            return null;
        }

        $bookings = Booking::where('venue_id', $listing->venue_id)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
            ->where(function ($q) use ($date, $startTime, $endTime) {
                applyBookingOverlapWhere($q, $date, $startTime, $endTime);
            })
            ->with('resourcesAllocated')
            ->get();

        // A session never blocks itself, so re-locking the same slot still works.
        $locks = SlotLock::whereHas('listing', function ($q) use ($listing) {
            $q->where('venue_id', $listing->venue_id);
        })
            ->where('session_id', '!=', $sessionId)
            ->active()
            ->where(function ($q) use ($date, $startTime, $endTime) {
                applyBookingOverlapWhere($q, $date, $startTime, $endTime);
            })
            ->with(['listing' => function ($q) {
                $q->with('resources');
            }])
            ->get();

        return $this->calculateResourceAvailability($listingResources, $bookings, $locks)['capacity'];
    }

    protected function slotsOverlap($start1, $end1, $start2, $end2)
    {
        if (! $start2 || ! $end2) {
            return false;
        }

        $start1Minutes = $this->timeToMinutes($start1);
        $end1Minutes   = $this->timeToMinutes($end1);
        $start2Minutes = $this->timeToMinutes($start2);
        $end2Minutes   = $this->timeToMinutes($end2);

        // Any interval whose end lands at 00:00, or clock-wraps back to a
        // smaller time than its start (e.g. 23:00 -> 01:00), is spanning
        // midnight and its "real" end is 24h later.
        if ($end1 === '00:00') {
            $end1Minutes = 24 * 60;
        } elseif ($end1Minutes < $start1Minutes) {
            $end1Minutes += 24 * 60;
        }
        if ($end2 === '00:00') {
            $end2Minutes = 24 * 60;
        } elseif ($end2Minutes < $start2Minutes) {
            $end2Minutes += 24 * 60;
        }

        return $start1Minutes < $end2Minutes && $start2Minutes < $end1Minutes;
    }

    protected function normalizeTimeForComparison($time)
    {
        if ($time === '24:00') {
            return 24 * 60;
        }
        return $this->timeToMinutes($time);
    }

    protected function slotFallsInPricingGroup($slotStart, $slotEnd, $groupStart, $groupEnd)
    {
        $slotStartMinutes  = $this->timeToMinutes($slotStart);
        $slotEndMinutes    = $this->timeToMinutes($slotEnd);
        $groupStartMinutes = $this->timeToMinutes($groupStart);
        $groupEndMinutes   = $this->normalizeTimeForComparison($groupEnd);

        $slotSpansMidnight = ($slotEnd === '00:00' || ($slotEndMinutes < $slotStartMinutes && $slotEnd !== '24:00'));
        if ($slotSpansMidnight) {
            $slotEndMinutes = 24 * 60;
        }

        if ($groupEnd === '00:00') {
            $groupEndMinutes = 24 * 60;
        }

        if ($groupStart === '00:00' && $groupEnd === '24:00') {
            return true;
        }

        return $slotStartMinutes < $groupEndMinutes && $slotEndMinutes > $groupStartMinutes;
    }

    protected function formatVenueLocationPayload(?Venue $venue): ?array
    {
        if (! $venue) {
            return null;
        }

        return [
            'country_id'     => $venue->country_id,
            'state_id'       => $venue->state_id,
            'city_id'        => $venue->city_id,
            'country'        => $venue->country?->name,
            'state'          => $venue->state?->name,
            'city'           => $venue->city?->name,
            'location_label' => $venue->location_label,
        ];
    }
}
