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
        // 1. In-app / Database Notifications table
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 100)->index(); // e.g. order.completed, event.updated, payout.approved
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->string('icon', 50)->nullable(); // e.g. ticket, calendar, alert, check-circle
            $table->json('data')->nullable(); // structured payload context
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        // 2. Notification Preferences table (User-level controls per channel & category)
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 60); // e.g. orders, events, payouts, marketing, announcements
            $table->string('channel', 30);  // e.g. email, in_app, push, sms, whatsapp
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category', 'channel'], 'user_cat_chan_unique');
        });

        // 3. Notification Deliveries Log (Audit trail for delivery status across channels)
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('in_app_notification_id')->nullable()->constrained('in_app_notifications')->nullOnDelete();
            $table->string('type', 100)->index();
            $table->string('channel', 30)->index(); // email, in_app, push, sms, whatsapp
            $table->string('recipient_target'); // email address, phone number, or device push token
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('in_app_notifications');
    }
};
