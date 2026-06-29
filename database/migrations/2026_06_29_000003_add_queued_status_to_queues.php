<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue now distinguishes "pending" (waiting in line) from "queued"
 * (handed to Liquidsoap and buffered, but not yet audible). An enum is awkward
 * to extend across drivers, so the status column becomes a plain string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->enum('status', ['pending', 'playing', 'played'])
                ->default('pending')
                ->change();
        });
    }
};
