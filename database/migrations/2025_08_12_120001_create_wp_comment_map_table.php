<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_to_laravel_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_comment_id')->unique();
            $table->unsignedBigInteger('laravel_comment_id');
            $table->timestamps();
            $table->index('laravel_comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_to_laravel_comments');
    }
};
