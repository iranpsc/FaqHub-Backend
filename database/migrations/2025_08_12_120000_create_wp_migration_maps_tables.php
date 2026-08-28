<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_to_laravel_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_user_id')->unique();
            $table->unsignedBigInteger('laravel_user_id');
            $table->timestamps();

            $table->index('laravel_user_id');
        });

        Schema::create('wp_to_laravel_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_term_id')->unique();
            $table->unsignedBigInteger('laravel_id');
            $table->enum('kind', ['category', 'tag']);
            $table->timestamps();

            $table->index(['kind', 'laravel_id']);
        });

        Schema::create('wp_to_laravel_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_post_id')->unique();
            $table->unsignedBigInteger('laravel_id');
            $table->enum('kind', ['question', 'answer']);
            $table->timestamps();

            $table->index(['kind', 'laravel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_to_laravel_posts');
        Schema::dropIfExists('wp_to_laravel_terms');
        Schema::dropIfExists('wp_to_laravel_users');
    }
};
