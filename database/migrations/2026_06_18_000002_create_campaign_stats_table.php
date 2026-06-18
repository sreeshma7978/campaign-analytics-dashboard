<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_stats', function (Blueprint $table): void {
            $table->string('campaign_id', 128)->primary();
            $table->unsignedBigInteger('sent')->default(0);
            $table->unsignedBigInteger('opened')->default(0);
            $table->unsignedBigInteger('clicked')->default(0);
            $table->unsignedBigInteger('bounced')->default(0);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_stats');
    }
};
