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
        Schema::create('entity_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->onDelete('restrict');
            $table->foreignId('from_entity_id')->constrained('entities')->onDelete('cascade');
            $table->foreignId('to_entity_id')->constrained('entities')->onDelete('cascade');
            $table->string('relation_type', 32);
            $table->json('metadata')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Unique: site_id + from + to + relation_type
            $table->unique(
                ['site_id', 'from_entity_id', 'to_entity_id', 'relation_type'],
                'entity_relations_unique'
            );

            // Indexes
            $table->index('from_entity_id', 'entity_relations_from_index');
            $table->index('to_entity_id', 'entity_relations_to_index');
            $table->index('relation_type', 'entity_relations_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_relations');
    }
};
