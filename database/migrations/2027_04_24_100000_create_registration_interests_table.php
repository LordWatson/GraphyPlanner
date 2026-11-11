<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public "register interest" form (`/register`) while the first clients are onboarded by hand.
 * Not org-scoped — there is no organization yet; super admins work the list from the console.
 * The request metadata (IP, country, timezone, referrer, UTM) is kept for the console's analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_interests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('company_name', 160);
            $table->string('location', 160)->nullable();
            $table->string('status', 32)->default('new');
            $table->timestamp('demo_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 35)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('referral_code', 64)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_interests');
    }
};
