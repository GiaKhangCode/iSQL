<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Một lớp, một giảng viên: classes.instructor_id là nguồn duy nhất.
 *
 * Bỏ giảng viên lý thuyết: xóa bảng class_instructors (và cột assignment_type của nó) cùng cột
 * classes.practice_instructor_id. Trước khi xóa, instructor_id của mỗi lớp được gộp theo thứ tự ưu tiên:
 * giảng viên THỰC HÀNH trong class_instructors, rồi practice_instructor_id, rồi instructor_id hiện có.
 * Chỉ nhận id còn tồn tại trong users (practice_instructor_id không có khóa ngoại nên có thể mồ côi).
 *
 * Không đảo ngược được dữ liệu: dòng giảng viên lý thuyết đã mất; down() chỉ dựng lại cấu trúc và
 * điền giảng viên hiện tại làm giảng viên thực hành.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasPivot = Schema::hasTable('class_instructors')
            && Schema::hasColumn('class_instructors', 'assignment_type');
        $hasPracticeColumn = Schema::hasColumn('classes', 'practice_instructor_id');

        DB::table('classes')->orderBy('id')->chunk(500, function ($classes) use ($hasPivot, $hasPracticeColumn): void {
            foreach ($classes as $class) {
                $candidates = [
                    $hasPivot
                        ? DB::table('class_instructors')
                            ->where('class_id', $class->id)
                            ->where('assignment_type', 'practical')
                            ->value('instructor_id')
                        : null,
                    $hasPracticeColumn ? $class->practice_instructor_id : null,
                    $class->instructor_id,
                ];

                $chosen = null;
                foreach ($candidates as $candidate) {
                    if ($candidate !== null && $candidate !== ''
                        && DB::table('users')->where('id', $candidate)->exists()) {
                        $chosen = $candidate;
                        break;
                    }
                }

                if ($chosen !== $class->instructor_id) {
                    DB::table('classes')->where('id', $class->id)->update(['instructor_id' => $chosen]);
                }
            }
        });

        Schema::dropIfExists('class_instructors');

        if ($hasPracticeColumn) {
            Schema::table('classes', function (Blueprint $table): void {
                $table->dropColumn('practice_instructor_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('classes', 'practice_instructor_id')) {
            Schema::table('classes', function (Blueprint $table): void {
                $table->string('practice_instructor_id', 36)->nullable()->after('instructor_id');
            });
        }

        if (! Schema::hasTable('class_instructors')) {
            Schema::create('class_instructors', function (Blueprint $table): void {
                $table->string('class_id', 50);
                $table->string('instructor_id', 50);
                $table->string('assignment_type', 20)->default('practical');
                $table->timestamp('assigned_at')->useCurrent();
                $table->primary(['class_id', 'assignment_type']);
                $table->index('class_id', 'class_instructors_class_id_support_index');
                $table->index('instructor_id');
                $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
                $table->foreign('instructor_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        DB::table('classes')->whereNotNull('instructor_id')->orderBy('id')->chunk(500, function ($classes): void {
            foreach ($classes as $class) {
                DB::table('classes')->where('id', $class->id)->update(['practice_instructor_id' => $class->instructor_id]);
                DB::table('class_instructors')->insertOrIgnore([
                    'class_id' => $class->id,
                    'instructor_id' => $class->instructor_id,
                    'assignment_type' => 'practical',
                ]);
            }
        });
    }
};
