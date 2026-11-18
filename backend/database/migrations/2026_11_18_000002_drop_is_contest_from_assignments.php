<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ cột assignments.is_contest.
 *
 * Cuộc thi độc lập đã bị loại bỏ; "ca thi" là assignment có examination_id khác NULL
 * (xem Assignment::getIsExamSessionAttribute). Cờ is_contest vì vậy trở nên thừa.
 *
 * Lưu ý dữ liệu: dòng nào có is_contest = 1 nhưng examination_id IS NULL (cuộc thi độc lập
 * cũ) sẽ trở thành bài tập thường sau migration này. Dev DB không có dòng như vậy.
 *
 * down() thêm lại cột và suy ra giá trị từ examination_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('assignments', 'is_contest')) {
            return;
        }

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('is_contest');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('assignments', 'is_contest')) {
            return;
        }

        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('is_contest')->default(false)->after('title');
        });

        DB::table('assignments')->whereNotNull('examination_id')->update(['is_contest' => true]);
    }
};
