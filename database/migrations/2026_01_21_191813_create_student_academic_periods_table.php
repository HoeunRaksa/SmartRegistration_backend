<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_academic_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('academic_year', 20); // e.g. "2024-2025"
            $table->unsignedTinyInteger('semester'); // 1 | 2

            $table->enum('status', ['ACTIVE', 'COMPLETED', 'DROPPED'])
                ->default('ACTIVE');

            $table->decimal('tuition_amount', 10, 2)->default(0);

            $table->enum('payment_status', ['PENDING', 'PAID', 'PARTIAL'])
                ->default('PENDING');

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // 🔒 Prevent duplicates for same student & period
            $table->unique(
                ['student_id', 'academic_year', 'semester'],
                'student_year_semester_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_periods');
    }
};
