<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đổi giá trị submissions.source của bài nộp ca thi: 'Contest' / 'Contests' -> 'ExamSession'.
 *
 * Cuộc thi độc lập đã bị loại bỏ; bài nộp có source này luôn là bài nộp của ca thi.
 * Code (SubmissionController, ProblemWorkspaceService...) chỉ còn nhận 'ExamSession'.
 *
 * down(): không phân biệt được 'Contest' với 'Contests' ban đầu, nên trả về 'Contests'
 * (giá trị mà code cũ ghi ra).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('submissions')) {
            return;
        }

        DB::table('submissions')
            ->whereIn('source', ['Contest', 'Contests'])
            ->update(['source' => 'ExamSession']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('submissions')) {
            return;
        }

        DB::table('submissions')
            ->where('source', 'ExamSession')
            ->update(['source' => 'Contests']);
    }
};
