<?php
namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\ComebackOfferService;
use App\Jobs\DeliverComebackOffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBookingReminders extends Command
{
    protected $signature = 'send-booking-reminders {--chunk=200}';
    protected $description = 'Issue a same-venue comeback offer after a first completed marketplace session';

    public function handle(): int
    {
        $issued = 0;
        Booking::with(['venue.city','user.preferences','creator.roles','creationHistory'])
            ->whereIn('status',['confirmed','completed'])->whereBetween('date',[now()->subDays(2)->toDateString(),now()->addDay()->toDateString()])
            ->chunkById(max(1,(int)$this->option('chunk')), function ($rows) use (&$issued) {
                foreach ($rows as $b) if (app(ComebackOfferService::class)->consider($b)) $issued++;
            });
        DB::table('booking_comeback_offers')->where('status','pending')->orderBy('id')->chunkById(200,function ($rows) {
            foreach ($rows as $offer) {
                if (config('preview.enabled')) DeliverComebackOffer::dispatchSync($offer->id);
                else DeliverComebackOffer::dispatch($offer->id);
            }
        });
        $this->info("Comeback offers issued: {$issued}. Delivery uses each customer's marketing consent.");
        return self::SUCCESS;
    }
}
