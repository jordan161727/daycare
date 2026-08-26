<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photograph of the child.
 *
 * What it is for is recognition — the relief teacher who has never met the room
 * matching a face to a name, and the office knowing who is at the door. That is
 * also why it is worth being careful with: this is a photograph of a minor, so
 * the file lives on the private disk and is served through a route that checks
 * who is asking, unlike a staff member's own avatar, which is theirs to publish
 * and sits on the public one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
