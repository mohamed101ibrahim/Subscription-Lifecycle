<?php

namespace App\Console\Commands;

use App\Models\FailedPayment;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BackfillPaymentsData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill legacy failed_payments data into the unified payments table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill of failed payments...');

        $failedPayments = FailedPayment::all();
        $count = 0;

        if ($failedPayments->isEmpty()) {
            $this->info('No failed payments found to backfill.');
            return;
        }

        $this->withProgressBar($failedPayments, function ($failed) use (&$count) {
            // Check if already exists to avoid duplicates if run multiple times
            $exists = Payment::where('subscription_id', $failed->subscription_id)
                ->where('status', 'failed')
                ->where('occurred_at', $failed->failed_at)
                ->exists();

            if (!$exists) {
                Payment::create([
                    'subscription_id' => $failed->subscription_id,
                    'amount' => $failed->amount,
                    'currency' => $failed->currency,
                    'status' => 'failed',
                    'type' => 'charge',
                    'provider' => null, // Legacy data didn't track provider
                    'provider_transaction_id' => null,
                    'raw_payload' => [
                        'legacy_failure_reason' => $failed->failure_reason,
                        'legacy_error_code' => $failed->provider_error_code,
                        'legacy_error_message' => $failed->provider_error_message,
                        'recovered' => $failed->recovered,
                        'recovered_at' => $failed->recovered_at,
                    ],
                    'occurred_at' => $failed->failed_at,
                ]);
                $count++;
            }
        });

        $this->newLine();
        $this->info("Backfill completed. {$count} record(s) migrated.");
    }
}
