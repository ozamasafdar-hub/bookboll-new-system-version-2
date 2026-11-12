<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassProgram extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'class_programs';

    protected $fillable = [
        'uuid',
        'provider_id',
        'listing_id',
        'listing_ids',
        'location_id',
        'venue_id',
        'owner_user_id',
        'mapped_owner_user_id',
        'contact_phone',
        'schedule_description',
        'listing_details',
        'name',
        'description',
        'sport_category',
        'coach_name',
        'monthly_price',
        'currency',
        'sessions_per_week',
        'session_duration_minutes',
        'day_of_week_mask',
        'session_time',
        'capacity',
        'commission_percentage',
        'status',
        'photo_url',
        'images',
        'color',
        'sort_order',
        'billing_interval',
        'program_type',
        'duration_weeks',
        'start_date',
        'end_date',
        'bonus_months',
        'free_trial_days',
        'free_trial_enabled',
        'free_trial_sessions',
        'discount_months',
        'joining_fee',
        'is_draft',
        'show_on_marketplace',
        'show_on_provider_site',
        'category',
        'audience',
        'gender_policy',
        'signup_form',
        'approval_status',
        'eligibility_status',
        'admin_note',
        'previous_snapshot',
        'pending_content',
        'trial_terms_accepted_at',
        'trial_terms_version',
        'submitted_at',
        'submitted_by_name',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'listing_details' => 'array',
        'listing_ids'           => 'array',
        'day_of_week_mask'      => 'array',
        'images'                => 'array',
        'signup_form'           => 'array',
        'previous_snapshot'     => 'array',
        'pending_content'       => 'array',
        'monthly_price'         => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'joining_fee'           => 'decimal:2',
        'free_trial_enabled'    => 'boolean',
        'is_draft'              => 'boolean',
        'show_on_marketplace'   => 'boolean',
        'show_on_provider_site' => 'boolean',
        'start_date'            => 'date',
        'end_date'              => 'date',
        'trial_terms_accepted_at' => 'datetime',
        'submitted_at'          => 'datetime',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    public function mappedOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_owner_user_id');
    }

    public function listingSubscriptions(): HasMany
    {
        return $this->hasMany(MembershipListingSubscription::class, 'program_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClassSubscription::class, 'program_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'program_id');
    }

    protected function activityLogName(): string
    {
        return 'class_program';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'provider_id',
            'listing_id',
            'listing_ids',
            'location_id',
            'venue_id',
            'owner_user_id',
            'mapped_owner_user_id',
            'contact_phone',
        'schedule_description',
        'name',
            'description',
            'sport_category',
            'coach_name',
            'monthly_price',
            'currency',
            'sessions_per_week',
            'session_duration_minutes',
            'day_of_week_mask',
            'session_time',
            'capacity',
            'commission_percentage',
            'status',
            'photo_url',
            'images',
            'color',
            'sort_order',
            'billing_interval',
            'program_type',
            'duration_weeks',
            'start_date',
            'end_date',
            'bonus_months',
            'free_trial_days',
            'free_trial_enabled',
            'free_trial_sessions',
            'discount_months',
            'joining_fee',
            'is_draft',
            'show_on_marketplace',
            'show_on_provider_site',
            'category',
            'audience',
            'gender_policy',
            'signup_form',
            'approval_status',
            'eligibility_status',
            'admin_note',
            'previous_snapshot',
            'pending_content',
            'trial_terms_accepted_at',
            'trial_terms_version',
            'submitted_at',
            'submitted_by_name',
            'created_at',
            'updated_at',
        ];
    }
}
