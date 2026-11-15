<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('slug', 50)->unique();
                $table->string('name', 100);
                $table->string('description', 500)->nullable();
                $table->decimal('monthly_price_ngn', 12, 2)->default(0.00);
                $table->decimal('annual_price_ngn', 12, 2)->default(0.00);
                $table->unsignedInteger('monthly_cost_credits')->default(0);
                $table->unsignedInteger('annual_cost_credits')->default(0);
                $table->decimal('fee_percentage', 5, 2)->nullable();
                $table->decimal('fee_fixed', 10, 2)->nullable();
                $table->unsignedInteger('max_staff_members')->default(3);
                $table->unsignedInteger('max_active_events')->default(999);
                $table->json('features')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });

            $now = now();
            DB::table('subscription_plans')->insert([
                [
                    'uuid' => (string) Str::uuid(),
                    'slug' => 'free',
                    'name' => 'Free Starter',
                    'description' => 'Essential event ticketing with standard platform commission.',
                    'monthly_price_ngn' => 0.00,
                    'annual_price_ngn' => 0.00,
                    'monthly_cost_credits' => 0,
                    'annual_cost_credits' => 0,
                    'fee_percentage' => null,
                    'fee_fixed' => null,
                    'max_staff_members' => 3,
                    'max_active_events' => 10,
                    'features' => json_encode([
                        'Standard platform ticket fee',
                        'Up to 3 team & gate staff members',
                        'Up to 10 concurrent active events',
                        'Standard T+3 payout settlement',
                    ]),
                    'is_active' => true,
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'slug' => 'pro',
                    'name' => 'Pro Growth',
                    'description' => 'Reduced ticket commission and expanded team capacity for growing organizers.',
                    'monthly_price_ngn' => 15000.00,
                    'annual_price_ngn' => 150000.00,
                    'monthly_cost_credits' => 1500,
                    'annual_cost_credits' => 15000,
                    'fee_percentage' => 2.50,
                    'fee_fixed' => 50.00,
                    'max_staff_members' => 15,
                    'max_active_events' => 100,
                    'features' => json_encode([
                        'Discounted 2.5% + ₦50 ticket commission',
                        'Up to 15 team & gate staff members',
                        'Up to 100 concurrent active events',
                        'Priority next-day payout settlement',
                    ]),
                    'is_active' => true,
                    'sort_order' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'slug' => 'enterprise',
                    'name' => 'Enterprise (Custom)',
                    'description' => 'Tailored commission rates, dedicated gate hardware, SLA support, and custom settlement terms.',
                    'monthly_price_ngn' => 0.00,
                    'annual_price_ngn' => 0.00,
                    'monthly_cost_credits' => 0,
                    'annual_cost_credits' => 0,
                    'fee_percentage' => null,
                    'fee_fixed' => null,
                    'max_staff_members' => 999,
                    'max_active_events' => 9999,
                    'features' => json_encode([
                        'Custom negotiated platform commission',
                        'Unlimited staff & gate scanner passes',
                        'Unlimited concurrent active events',
                        'Dedicated Account Manager & 24/7 Gate SLA',
                        'Custom direct-to-bank instant settlement schedules',
                    ]),
                    'is_active' => true,
                    'sort_order' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        if (! Schema::hasTable('organizer_subscriptions')) {
            Schema::create('organizer_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organizer_id')
                    ->constrained('organizers')
                    ->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')
                    ->nullable()
                    ->constrained('subscription_plans')
                    ->nullOnDelete();
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('plan_slug', 50)->index();
                $table->string('billing_cycle', 20)->default('monthly'); // monthly, annual, custom
                $table->string('payment_method', 30)->default('credits'); // credits, paystack, admin_grant
                $table->decimal('amount_paid', 12, 2)->default(0.00);
                $table->unsignedInteger('credits_paid')->default(0);
                $table->string('currency', 10)->default('NGN');
                $table->string('status', 20)->default('active')->index(); // pending, active, cancelled, expired
                $table->boolean('auto_renew')->default(true);
                $table->string('reference', 100)->unique();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable()->index();
                $table->timestamp('cancelled_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizer_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};

