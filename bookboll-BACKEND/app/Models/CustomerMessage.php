<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerMessage extends Model
{
    use LogsModelActivity;
    use GeneratesUuid;
    // Every read of this table goes through this model, so soft deletes take a
    // removed message out of the thread, the unread counts and the inbox
    // preview at once.
    use SoftDeletes;

    protected $table = 'customer_messages';

    protected $fillable = [
        'uuid',
        'conversation_key',
        'sender_id',
        'recipient_id',
        'body',
        'mentions',
        'read_at',
    ];

    protected $casts = [
        'mentions' => 'array',
        'read_at'    => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function activityLogName(): string
    {
        return 'customer_message';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'uuid',
            'conversation_key',
            'sender_id',
            'recipient_id',
            'read_at',
        ];
    }
}
