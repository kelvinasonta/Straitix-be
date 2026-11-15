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
        Schema::table('organizers', function (Blueprint $table) {
            if (! Schema::hasColumn('organizers', 'plan_tier')) {
                $table->string('plan_tier', 32)->default('free')->after('verification_status')->index();
            }
            if (! Schema::hasColumn('organizers', 'plan_expires_at')) {
                $table->timestamp('plan_expires_at')->nullable()->after('plan_tier');
            }
            if (! Schema::hasColumn('organizers', 'custom_fee_percentage')) {
                $table->decimal('custom_fee_percentage', 5, 2)->nullable()->after('plan_expires_at');
            }
            if (! Schema::hasColumn('organizers', 'custom_fee_fixed')) {
                $table->decimal('custom_fee_fixed', 10, 2)->nullable()->after('custom_fee_percentage');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizers', function (Blueprint $table) {
            $columns = array_filter(
                ['plan_tier', 'plan_expires_at', 'custom_fee_percentage', 'custom_fee_fixed'],
                fn (string $col): bool => Schema::hasColumn('organizers', $col)
            );
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
