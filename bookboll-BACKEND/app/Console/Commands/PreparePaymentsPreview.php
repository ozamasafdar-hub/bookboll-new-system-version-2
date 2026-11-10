<?php
namespace App\Console\Commands;

use App\Models\{Booking, Business, Payment, User, Venue};
use App\Support\{BookingPurpose, LegacyReconciliation, Money, OwnedVenueAllocation, PaymentFinancialContext, PaymentRecipient, PaymentReportRules, TransferPaymentContext};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Hash, Schema};

/** This restatement is ONLY for the explicitly requested, isolated repo snapshot. */
class PreparePaymentsPreview extends Command
{
    protected $signature = 'payments:prepare-preview';
    protected $description = 'Prepare the isolated Railway snapshot using the owner-confirmed venue mapping';

    public function handle(): int
    {
        if (!config('preview.enabled') || config('database.connections.mysql.database') !== 'bookboll_preview'
            || strlen((string)config('preview.admin_password')) < 20) {
            $this->error('Refusing to restate any database except the protected bookboll_preview snapshot.'); return 1;
        }
        if (!Schema::hasTable('preview_reconciliation_audit')) {
            Schema::create('preview_reconciliation_audit', function($t) {
                $t->id(); $t->string('entity'); $t->unsignedBigInteger('entity_id');
                $t->json('before')->nullable(); $t->json('after')->nullable(); $t->string('basis'); $t->timestamps();
            });
        }
        if (DB::table('preview_reconciliation_audit')->where('entity','complete')->exists()) {
            $this->info('Snapshot already prepared; no history changed.'); return 0;
        }
        DB::transaction(function() {
            // The three exact venue records confirmed by the owner, never name matching.
            $kept = [1,2,3];
            foreach (Venue::all() as $venue) {
                $before = $venue->getAttributes();
                if (in_array($venue->id, $kept, true)) {
                    $venue->forceFill(['operator_type'=>'bookboll','business_id'=>null,'owner_user_id'=>null])->saveQuietly();
                } else {
                    $business = $venue->business_id ? Business::find($venue->business_id) : null;
                    $business ??= $venue->owner_user_id ? Business::where('owner_id',$venue->owner_user_id)->first() : null;
                    if (!$business) $business = Business::firstOrCreate(['username'=>'venue-'.$venue->id.'-operator'],[
                        'name'=>$venue->name, 'owner_id'=>null, 'country_id'=>$venue->country_id,
                        'status'=>Business::STATUS_ACTIVE, 'business_types'=>['sports_facility'], 'plan'=>'free',
                    ]);
                    $venue->forceFill(['operator_type'=>'provider','business_id'=>$business->id,'owner_user_id'=>$business->owner_id])->saveQuietly();
                }
                if ($before !== $venue->getAttributes()) $this->audit('venue',$venue->id,$before,$venue->getAttributes(),'Owner-confirmed mapping, 10 Nov 2026');
            }

            // Recover the meaning of old transfer corrections from their audit trail.
            $oldKinds = DB::table('venue_transfer_allocations')->pluck('kind','payment_id');
            Booking::with('payments')->orderBy('id')->chunkById(250, function($bookings) use($oldKinds) {
                foreach($bookings as $booking) {
                    $beforeBooking = $booking->getAttributes();
                    // These choices are staff-only in BookingService. The old importer
                    // labelled their origin bookboll_web, so retain that original in audit.
                    $manual = in_array($booking->payment_method,['paid_cash','paid_cheque','paid_credit_card','paid_wire_transfer','payment_skipped'],true);
                    $context = PaymentFinancialContext::forNewBooking($booking);
                    if ($manual) { $context['channel']='admin'; $context['fee_rate']=0; $context['channel_basis']='legacy_staff_only_payment_option'; }
                    $context['ownership_basis']='preview_owner_confirmed_2026_11_10';
                    $context['reconciliation_batch_id']='preview-2026-11-10';
                    $booking->forceFill(['financial_context'=>$context])->saveQuietly();
                    $this->audit('booking',$booking->id,$beforeBooking,$booking->getAttributes(),'Owner-confirmed historical operator; stored quote only');
                    foreach($booking->payments as $payment) {
                        $before = $payment->getAttributes();
                        $pc = $context;
                        $oldKind = $oldKinds[$payment->id] ?? null;
                        if ($oldKind) $pc['reconciliation_kind']=$oldKind;
                        // Old audited deposits were renamed as commission, not charged twice.
                        if ($oldKind === LegacyReconciliation::KIND_DEPOSIT_TO_FEE) $pc['operator']='provider';
                        $payment->financial_context = $pc;
                        if ($pc['operator'] === 'provider') {
                            $business = Business::find($pc['business_id']);
                            $historical = substr((string)$booking->date,0,10) < '2026-11-10';
                            $eligible = BookingPurpose::isSale($booking) && !in_array($booking->status,['cancelled'],true)
                                && !in_array($payment->status,['cancelled','refunded','disputed','failed'],true);
                            if ($business && $historical && $eligible) {
                                // Never turn an online payment into cash. This is the user's
                                // historical cash assertion, not a Stripe payment or a payout.
                                $kind = LegacyReconciliation::kindFor($payment,$booking);
                                if ($kind === LegacyReconciliation::KIND_PROVIDER_CASH && !$payment->collected_by_business_id) {
                                    $payment->status='paid'; $payment->method='cash';
                                    $payment->paid_at=$payment->paid_at ?: ($payment->date ?: $booking->date);
                                    $pc['collection_date_basis']='original_record_date_or_service_date';
                                    $payment->collection_method=$kind;
                                    $payment->collected_by_business_id=$business->id;
                                    $payment->reconciliation_batch_id='preview-2026-11-10';
                                }
                                if ($payment->type === 'deposit' && $payment->method === 'online' && !$manual) {
                                    $payment->recipient='@bookboll';
                                } elseif ($payment->method !== 'online' || $manual) {
                                    $payment->recipient=PaymentRecipient::forBusiness($business);
                                }
                            }
                        } elseif ($pc['operator'] === 'bookboll') {
                            $payment->recipient='@bookboll';
                            $planned = OwnedVenueAllocation::plan($payment->getAttributes(),$pc);
                            if ($planned && !$payment->ownedAllocations()->exists()) $payment->ownedAllocations()->createMany($planned);
                        }
                        $payment->financial_context=$pc;
                        $payment->saveQuietly(); // No new-payment notifications from old history.
                        $this->audit('payment',$payment->id,$before,$payment->getAttributes(),'Preview historical ownership/collection assertion; no funds moved');
                    }
                }
            });
            // Existing tokens must not grant access to this independent copy.
            DB::table('personal_access_tokens')->delete();
            $admin = User::firstOrNew(['email'=>config('preview.admin_email')]);
            $admin->forceFill(['name'=>'Preview Admin','status'=>'active','password'=>Hash::make(config('preview.admin_password'))])->saveQuietly();
            $admin->assignRole('super_admin');
            $this->audit('complete',0,[],['bookings'=>Booking::count(),'payments'=>Payment::count()],'Repository snapshot prepared once; read-only app');
        });
        $this->info('Isolated snapshot prepared. All original financial values retained in the preview audit.');
        return 0;
    }

    private function audit(string $entity,int $id,array $before,array $after,string $basis): void
    {
        DB::table('preview_reconciliation_audit')->insert(['entity'=>$entity,'entity_id'=>$id,
            'before'=>json_encode($before),'after'=>json_encode($after),'basis'=>$basis,'created_at'=>now(),'updated_at'=>now()]);
    }
}
