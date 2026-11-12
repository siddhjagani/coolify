<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An application can have many slots, and each application of a slot group can get a share of the traffic.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['slot_of_id']);
            $table->index('slot_of_id');
        });
        Schema::table('application_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot_traffic_weight')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('slot_traffic_weight');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['slot_of_id']);
            $table->unique('slot_of_id');
        });
    }
};
