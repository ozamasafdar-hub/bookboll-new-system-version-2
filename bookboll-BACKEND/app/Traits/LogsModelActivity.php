<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

trait LogsModelActivity
{
    use LogsActivity;

    abstract protected function activityLogName(): string;

    abstract protected function activityLogAttributes(): array;

    public function tapActivity(\Spatie\Activitylog\Contracts\Activity $activity, string $eventName): void
    {
        if ($session = \App\Support\BusinessSupport::session()) {
            $activity->causer()->associate(\App\Models\User::find($session->actor_id));
            $activity->properties = $activity->properties->merge(['business_support'=>$session->details()]);
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogName())
            ->logOnly($this->activityLogAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
