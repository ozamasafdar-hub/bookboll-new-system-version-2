<?php
namespace App\Traits;

use App\Models\User;

trait TracksCreator
{
    public static function bootTracksCreator(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && empty($model->created_by)) {
                $model->created_by = \App\Support\BusinessSupport::actorId();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
