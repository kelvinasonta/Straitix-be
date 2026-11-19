<?php

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
        Schema::table('organizer_bank_accounts', function (Blueprint $table) {
            $table->text('bank_name')->change();
            $table->text('account_name')->change();
            $table->text('account_number')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizer_bank_accounts', function (Blueprint $table) {
            $table->string('bank_name', 255)->change();
            $table->string('account_name', 255)->change();
            $table->string('account_number', 20)->change();
        });
    }
};
