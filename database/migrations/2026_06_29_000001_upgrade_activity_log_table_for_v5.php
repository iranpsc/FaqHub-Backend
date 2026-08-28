<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name');

        if (! Schema::connection($connection)->hasColumn($tableName, 'attribute_changes')) {
            Schema::connection($connection)->table($tableName, function (Blueprint $table) {
                $table->json('attribute_changes')->nullable()->after('causer_id');
            });
        }

        if (Schema::connection($connection)->hasColumn($tableName, 'batch_uuid')) {
            Schema::connection($connection)->table($tableName, function (Blueprint $table) {
                $table->dropColumn('batch_uuid');
            });
        }

        DB::connection($connection)->table($tableName)
            ->where(function ($query) {
                $query->whereNotNull('properties->attributes')
                    ->orWhereNotNull('properties->old');
            })
            ->orderBy('id')
            ->each(function ($row) use ($connection, $tableName) {
                $properties = json_decode($row->properties ?? '[]', true) ?: [];
                $changes = array_intersect_key($properties, array_flip(['attributes', 'old']));
                $remaining = array_diff_key($properties, array_flip(['attributes', 'old']));

                DB::connection($connection)->table($tableName)->where('id', $row->id)->update([
                    'attribute_changes' => empty($changes) ? null : json_encode($changes),
                    'properties' => empty($remaining) ? null : json_encode($remaining),
                ]);
            });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name');

        if (! Schema::connection($connection)->hasColumn($tableName, 'attribute_changes')) {
            return;
        }

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
            $table->dropColumn('attribute_changes');
        });
    }
};
