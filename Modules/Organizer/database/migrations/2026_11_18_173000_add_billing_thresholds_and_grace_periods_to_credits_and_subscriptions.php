<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('organizer_credit_wallets')) {
            Schema::table('organizer_credit_wallets', function (Blueprint $table) {
                if (! Schema::hasColumn('organizer_credit_wallets', 'low_balance_threshold')) {
                    $table->unsignedInteger('low_balance_threshold')->default(500)->after('reserved_balance');
                }
                if (! Schema::hasColumn('organizer_credit_wallets', 'low_balance_alert_enabled')) {
                    $table->boolean('low_balance_alert_enabled')->default(true)->after('low_balance_threshold');
                }
                if (! Schema::hasColumn('organizer_credit_wallets', 'last_low_balance_alert_at')) {
                    $table->timestamp('last_low_balance_alert_at')->nullable()->after('low_balance_alert_enabled');
                }
            });
        }

        if (Schema::hasTable('organizers')) {
            Schema::table('organizers', function (Blueprint $table) {
                if (! Schema::hasColumn('organizers', 'plan_grace_ends_at')) {
                    $table->timestamp('plan_grace_ends_at')->nullable()->after('plan_expires_at');
                }
            });
        }

        if (Schema::hasTable('organizer_subscriptions')) {
            Schema::table('organizer_subscriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('organizer_subscriptions', 'grace_ends_at')) {
                    $table->timestamp('grace_ends_at')->nullable()->after('ends_at');
                }
                if (! Schema::hasColumn('organizer_subscriptions', 'invoice_number')) {
                    $table->string('invoice_number', 50)->nullable()->index()->after('reference');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('organizer_credit_wallets')) {
            Schema::table('organizer_credit_wallets', function (Blueprint $table) {
                $table->dropColumn([
                    'low_balance_threshold',
                    'low_balance_alert_enabled',
                    'last_low_balance_alert_at',
                ]);
            });
        }

        if (Schema::hasTable('organizers')) {
            Schema::table('organizers', function (Blueprint $table) {
                $table->dropColumn('plan_grace_ends_at');
            });
        }

        if (Schema::hasTable('organizer_subscriptions')) {
            Schema::table('organizer_subscriptions', function (Blueprint $table) {
                $table->dropColumn(['grace_ends_at', 'invoice_number']);
            });
        }
    }
};
