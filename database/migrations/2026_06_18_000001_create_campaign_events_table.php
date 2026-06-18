<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 128)->unique();
            $table->string('campaign_id', 128)->index();
            $table->string('type', 24);
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['campaign_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_events');
    }
};
