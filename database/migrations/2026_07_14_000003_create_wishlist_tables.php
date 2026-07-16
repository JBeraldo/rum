<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('type');
            $table->string('title');
            $table->string('poster_url')->nullable();
            $table->unsignedBigInteger('estimated_bytes');
            $table->string('status')->default('pending')->index();
            $table->string('selected_root_folder')->nullable();
            $table->string('source_item_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->text('last_reason')->nullable();
            $table->json('source_payload');
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('wishlist_requesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishlist_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['wishlist_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_requesters');
        Schema::dropIfExists('wishlist_items');
    }
};
