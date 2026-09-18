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
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->onDelete('restrict');
            $table->string('type', 32);
            $table->string('slug', 128);
            $table->string('name', 255);
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->json('metadata')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Unique: site_id + type + slug
            $table->unique(['site_id', 'type', 'slug'], 'entities_site_type_slug_unique');

            // Indexes
            $table->index(['site_id', 'type'], 'entities_site_type_index');
            $table->index('status', 'entities_status_index');
            $table->index('slug', 'entities_slug_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
