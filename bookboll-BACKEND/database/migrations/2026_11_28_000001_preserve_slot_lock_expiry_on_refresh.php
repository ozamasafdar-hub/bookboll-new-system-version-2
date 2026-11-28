<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Old MySQL installations implicitly attach ON UPDATE CURRENT_TIMESTAMP
        // to the first TIMESTAMP column. A same-second re-lock can otherwise
        // expire the hold while Eloquent updates its audit timestamp.
        Schema::table('slot_locks', function (Blueprint $table) {
            $table->timestamp('locked_until')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Do not restore implicit expiration-on-update behavior.
    }
};
