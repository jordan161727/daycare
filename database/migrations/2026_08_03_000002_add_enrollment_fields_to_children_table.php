<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('child_name')->nullable()->after('lan');
            $table->string('nickname')->nullable(); $table->text('address')->nullable(); $table->string('city')->nullable(); $table->string('zip')->nullable(); $table->string('telephone')->nullable(); $table->date('birth_date')->nullable();
            foreach (['mother', 'father'] as $parent) foreach (['name','address','home_phone','employer','work_phone','fax','cell','title','ssn'] as $field) $field === 'address' ? $table->text("{$parent}_{$field}")->nullable() : $table->string("{$parent}_{$field}")->nullable();
            $table->string('email_address')->nullable(); $table->string('parents_status')->nullable(); $table->string('responsible_for_payment')->nullable();
            $table->string('emergency_contact')->nullable(); $table->string('secondary_emergency_contact')->nullable(); $table->string('emergency_telephone')->nullable(); $table->string('emergency_relationship')->nullable(); $table->string('emergency_license_number')->nullable();
            for ($number = 1; $number <= 3; $number++) { $table->string("pickup_{$number}_name")->nullable(); $table->text("pickup_{$number}_address")->nullable(); $table->string("pickup_{$number}_telephone")->nullable(); $table->string("pickup_{$number}_alternate")->nullable(); $table->string("pickup_{$number}_relationship")->nullable(); $table->string("pickup_{$number}_license_number")->nullable(); }
            $table->text('other_notes')->nullable(); $table->text('important_notes')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $columns = ['child_name','nickname','address','city','zip','telephone','birth_date','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number','other_notes','important_notes'];
            foreach (['mother', 'father'] as $parent) foreach (['name','address','home_phone','employer','work_phone','fax','cell','title','ssn'] as $field) $columns[] = "{$parent}_{$field}";
            for ($number = 1; $number <= 3; $number++) foreach (['name','address','telephone','alternate','relationship','license_number'] as $field) $columns[] = "pickup_{$number}_{$field}";
            $table->dropColumn($columns);
        });
    }
};
