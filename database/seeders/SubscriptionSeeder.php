<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get existing users and plans
        $users = User::all();
        $plans = Plan::with('billingCycles.prices')->get();

        if ($plans->isEmpty() || $users->isEmpty()) {
            $this->command->warn('Please run PlanSeeder and UserSeeder first!');
            return;
        }

        $providers = ['stripe', 'paymob', 'fawry', 'paypal'];

        // Create subscriptions for each user
        foreach ($users as $user) {
            // Each user gets 1-2 subscriptions
            $randomPlans = $plans->random(rand(1, 2))->unique();

            foreach ($randomPlans as $plan) {
                // Pick a random billing cycle from the plan
                $billingCycle = $plan->billingCycles->random();

                // Pick a random price/currency for that cycle
                $planPrice = $billingCycle->prices->random();

                // Pick a random status
                $statuses = ['trialing', 'active', 'past_due', 'canceled'];
                $randomStatus = $statuses[array_rand($statuses)];

                $startDate = Carbon::now('UTC')->subDays(rand(10, 150));
                $trialEnds = $startDate->copy()->addDays(14);
                
                $provider = $providers[array_rand($providers)];

                $subscription = $user->subscriptions()->create([
                    'plan_id' => $plan->id,
                    'plan_price_id' => $planPrice->id,
                    'provider' => $provider,
                    'provider_customer_id' => 'cus_' . bin2hex(random_bytes(8)),
                    'provider_subscription_id' => 'sub_' . bin2hex(random_bytes(8)),
                    'status' => $randomStatus,
                    'trial_ends_at' => $randomStatus === 'trialing' ? $trialEnds : null,
                    'started_at' => $startDate,
                    'current_period_start' => $startDate,
                    'current_period_end' => $startDate->copy()->addDays($billingCycle->duration_in_days),
                    'grace_period_ends_at' => $randomStatus === 'past_due' 
                        ? Carbon::now('UTC')->addDays(rand(1, 3)) 
                        : null,
                    'canceled_at' => $randomStatus === 'canceled' 
                        ? Carbon::now('UTC')->subDays(rand(1, 30)) 
                        : null,
                    'cancellation_reason' => $randomStatus === 'canceled' 
                        ? ['Found better alternative', 'Too expensive', 'No longer needed'][array_rand(['Found better alternative', 'Too expensive', 'No longer needed'])]
                        : null,
                ]);

                // Record history
                \App\Models\SubscriptionHistory::create([
                    'subscription_id' => $subscription->id,
                    'previous_status' => null,
                    'new_status' => $randomStatus,
                    'reason' => 'Seeding initial data',
                ]);

                // Create a few payments for this subscription
                $paymentCount = rand(2, 5);
                for ($i = 0; $i < $paymentCount; $i++) {
                    $isSuccess = rand(1, 10) > 2; // 80% success rate
                    
                    Payment::create([
                        'subscription_id' => $subscription->id,
                        'amount' => $planPrice->price,
                        'currency' => $planPrice->currency,
                        'status' => $isSuccess ? 'succeeded' : 'failed',
                        'type' => 'charge',
                        'provider' => $provider,
                        'provider_transaction_id' => 'txn_' . bin2hex(random_bytes(10)),
                        'raw_payload' => ['seeded' => true, 'attempt' => $i + 1],
                        'occurred_at' => $startDate->copy()->addDays($i * $billingCycle->duration_in_days),
                    ]);
                }

                $this->command->line("Created {$randomStatus} subscription + payments for {$user->email}");
            }
        }
    }
}

