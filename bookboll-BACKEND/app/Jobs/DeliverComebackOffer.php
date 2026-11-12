<?php
namespace App\Jobs;

use App\Models\{User,Promocode,Venue};
use App\Services\WhatsappService;
use App\Notifications\BookingReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class DeliverComebackOffer implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $uniqueFor = 600;
    public int $tries = 1;
    public function __construct(public int $offerId) {}
    public function uniqueId(): string { return (string)$this->offerId; }
    public function handle(): void
    {
        $offer = DB::table('booking_comeback_offers')->find($this->offerId);
        if (!$offer || $offer->status !== 'pending') return;
        $user = User::with('preferences')->find($offer->user_id);
        $promo = Promocode::find($offer->promocode_id);
        $venue = Venue::find($offer->venue_id);
        if (!$user || !$promo || !$venue) return;
        foreach (['email','whatsapp'] as $channel) {
            $column = $channel.'_status';
            if ($offer->$column !== 'pending') continue;
            $allowed = $user->preferences?->notifications[$channel === 'email'?'marketing_emails':'marketing_whatsapp'] ?? false;
            $status = config('preview.enabled') ? 'preview' : (!$allowed || now()->gte($promo->ends_at) || $promo->status !== 'active' ? 'skipped' : 'sending');
            if (!DB::table('booking_comeback_offers')->where('id',$offer->id)->where($column,'pending')->update([$column=>$status,'updated_at'=>now()])) continue;
            if ($status !== 'sending') continue;
            try {
                if ($channel === 'email') {
                    $user->notifyNow(new BookingReminderNotification(0,$promo,$venue));
                } else {
                    $wa = app(WhatsappService::class); $sender = $wa->platformSender();
                    $template = config('services.whatsapp.comeback_template');
                    if (!$template || !$sender['phone_number_id'] || !$sender['token']) throw new \RuntimeException('Configure an approved comeback marketing template and the platform WhatsApp sender.');
                    $variables = [$user->name,$venue->name,(string)$promo->discount_value,$promo->code,$promo->ends_at->format('d M Y H:i T'),BookingReminderNotification::url($venue)];
                    $result = $wa->sendTemplate($sender,['to'=>$user->phone,'customerId'=>$user->id,
                        'template'=>['name'=>$template,'language'=>config('services.whatsapp.comeback_language','en_US')],
                        'variables'=>$variables,'bodyText'=>'Hi {{1}}, book again at {{2}} for up to {{3}}% off with {{4}}. Valid until {{5}}. {{6}} Discount is capped at Bookboll’s fee.']);
                    if (!($result['ok']??false)) throw new \RuntimeException($result['error']??'WhatsApp delivery could not be confirmed.');
                }
                DB::table('booking_comeback_offers')->where('id',$offer->id)->update([$column=>'sent','updated_at'=>now()]);
            } catch (\Throwable $e) {
                // Never blindly retry a marketing send whose delivery is uncertain.
                DB::table('booking_comeback_offers')->where('id',$offer->id)->update([$column=>'needs_review','last_error'=>$e->getMessage(),'updated_at'=>now()]);
                report($e);
            }
        }
        DB::table('booking_comeback_offers')->where('id',$offer->id)->update(['status'=>'processed','updated_at'=>now()]);
    }
}
