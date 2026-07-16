<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique();
            $table->string('base_url');
            $table->text('api_key');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('type')->index();
            $table->string('title');
            $table->string('sort_title')->index();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('overview')->nullable();
            $table->string('poster_url')->nullable();
            $table->boolean('is_monitored')->default(false)->index();
            $table->boolean('is_available')->default(false)->index();
            $table->json('source_metadata')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('integrations');
    }
};
