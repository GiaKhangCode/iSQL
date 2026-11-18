<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng lượt làm bài của sinh viên cho một hoạt động (bài tập / kỳ thi / cuộc thi).
 *
 * Trước đây bảng này chỉ tồn tại ở SQL-UIT (SQLAlchemy create_all), còn Laravel chỉ có
 * migration 2026_11_13_000003 thêm cột schema_name (và bỏ qua nếu bảng chưa có), nên
 * DB dựng mới bằng `php artisan migrate` thiếu bảng và các route /student/* bị lỗi 500.
 *
 * - schema_name có sẵn ngay khi tạo bảng; migration 2026_11_13_000003 sẽ tự bỏ qua.
 * - timestamps() cần thiết vì model ActivityAttempt không tắt $timestamps.
 * - Nếu bảng đã tồn tại (DB cũ) thì migration không làm gì để không mất dữ liệu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_attempts')) {
            return;
        }

        Schema::create('activity_attempts', function (Blueprint $table) {
            $table->string('assignment_id', 50);
            $table->string('user_id', 50);
            $table->string('status', 30)->default('active');       // active | submitted | ...
            $table->string('schema_name', 64)->nullable();         // schema sandbox riêng của lượt làm
            $table->json('drafts');                                // bản nháp theo từng bài
            $table->json('violations');                            // các vi phạm khi thi (rời tab, ...)
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('finish_reason', 50)->nullable();
            $table->timestamps();

            // Mỗi sinh viên chỉ có một lượt làm cho mỗi hoạt động.
            $table->primary(['assignment_id', 'user_id']);

            $table->foreign('assignment_id')->references('id')->on('assignments')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('schema_name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_attempts');
    }
};
