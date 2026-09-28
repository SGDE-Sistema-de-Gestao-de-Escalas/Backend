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
        Schema::create('assistants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->nullable();
            $table->foreignId('school_id')->constrained('schools');

            $table->string('internal_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->string('nif', 9)->unique()->nullable();
            $table->string('social_security_number', 11)->unique()->nullable();
            $table->date('birth_date')->nullable();
            $table->date('admission_date')->nullable();

            $table->string('criminal_record_path')->nullable();
            $table->date('criminal_record_expiry')->nullable();

            $table->string('address_street')->nullable();
            $table->string('address_zip_code')->nullable();

            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_kinship')->nullable();
            
            $table->boolean('available_for_transfer')->default(false);
            $table->timestamps();
            $table->softdeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistants');
    }
};
