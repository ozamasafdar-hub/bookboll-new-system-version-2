<?php
namespace App\Console\Commands;

use App\Services\ChatAlertService;
use Illuminate\Console\Command;

class SendChatAlerts extends Command
{
    protected $signature = 'chat:send-alerts';
    protected $description = 'Deliver batched unread marketplace chat alerts (preview never sends).';
    public function handle(ChatAlertService $alerts): int
    {
        $this->info('Processed '.$alerts->deliver().' chat alert batches.');
        return self::SUCCESS;
    }
}
