<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (! Schema::hasIndex('questions', 'questions_published_views_idx')) {
                $table->index(['published', 'views'], 'questions_published_views_idx');
            }

            if (! Schema::hasIndex('questions', 'questions_published_created_idx')) {
                $table->index(['published', 'created_at'], 'questions_published_created_idx');
            }
        });

        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name');
        $schema = Schema::connection($connection);

        if (! $schema->hasIndex($tableName, 'activity_log_created_id_idx')) {
            $schema->table($tableName, function (Blueprint $table) {
                $table->index(['created_at', 'id'], 'activity_log_created_id_idx');
            });
        }

        if (! $schema->hasIndex($tableName, 'activity_log_description_created_idx')) {
            $schema->table($tableName, function (Blueprint $table) {
                $table->index(['description', 'created_at'], 'activity_log_description_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasIndex('questions', 'questions_published_views_idx')) {
                $table->dropIndex('questions_published_views_idx');
            }

            if (Schema::hasIndex('questions', 'questions_published_created_idx')) {
                $table->dropIndex('questions_published_created_idx');
            }
        });

        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name');
        $schema = Schema::connection($connection);

        if ($schema->hasIndex($tableName, 'activity_log_created_id_idx')) {
            $schema->table($tableName, function (Blueprint $table) {
                $table->dropIndex('activity_log_created_id_idx');
            });
        }

        if ($schema->hasIndex($tableName, 'activity_log_description_created_idx')) {
            $schema->table($tableName, function (Blueprint $table) {
                $table->dropIndex('activity_log_description_created_idx');
            });
        }
    }
};
