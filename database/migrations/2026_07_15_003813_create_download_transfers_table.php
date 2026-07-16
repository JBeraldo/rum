<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('download_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wishlist_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('source_item_id');
            $table->string('torrent_hash', 64);
            $table->text('name');
            $table->decimal('progress', 5, 4)->default(0);
            $table->string('state')->index();
            $table->unsignedBigInteger('download_speed')->default(0);
            $table->unsignedBigInteger('eta_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('amount_left_bytes')->default(0);
            $table->string('category')->nullable();
            $table->text('content_path')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('removed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['download_client_id', 'torrent_hash']);
            $table->index(['source', 'source_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_transfers');
    }
};
