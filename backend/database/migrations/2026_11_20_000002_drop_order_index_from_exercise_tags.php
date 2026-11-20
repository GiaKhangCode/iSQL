<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bỏ cột thứ tự hiển thị của thẻ bài tập (exercise_tags.order_index).
 *
 * Thứ tự hiển thị từ nay là thứ tự tạo: độ khó trước dạng bài, rồi created_at. Để không đổi thứ tự đang thấy,
 * trước khi bỏ cột ghi lại created_at của mọi thẻ cách nhau từng giây theo đúng thứ tự cũ
 * (độ khó trước, phần DDL/DML/DQL/TRIGGER, order_index, created_at, id). Mốc đầu là created_at sớm nhất hoặc "bây giờ trừ số thẻ giây",
 * lấy mốc nào sớm hơn, để thẻ tạo sau luôn mới hơn mọi thẻ cũ.
 *
 * down() thêm lại cột và điền order_index theo thứ tự created_at, đánh số lại từ 1 trong từng nhóm (kind, part).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exercise_tags') || ! Schema::hasColumn('exercise_tags', 'order_index')) {
            return;
        }

        $tags = DB::table('exercise_tags')
            ->orderByRaw("CASE WHEN kind = 'difficulty' THEN 1 ELSE 2 END")
            ->orderBy('part') // ALL, DDL, DML, DQL, TRIGGER: gom theo phần như danh sách mặc định
            ->orderBy('order_index')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at']);

        if ($tags->isNotEmpty()) {
            $earliest = $tags->pluck('created_at')->filter()->map(fn ($v) => \Carbon\Carbon::parse($v))->min();
            $base = $earliest ?? now();
            $latestBase = now()->subSeconds($tags->count());
            if ($base->greaterThan($latestBase)) {
                $base = $latestBase;
            }

            foreach ($tags->values() as $i => $tag) {
                DB::table('exercise_tags')->where('id', $tag->id)->update(['created_at' => $base->copy()->addSeconds($i)]);
            }
        }

        Schema::table('exercise_tags', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'order_index']);
        });
        Schema::table('exercise_tags', function (Blueprint $table): void {
            $table->dropColumn('order_index');
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exercise_tags') || Schema::hasColumn('exercise_tags', 'order_index')) {
            return;
        }

        Schema::table('exercise_tags', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'created_at']);
        });
        Schema::table('exercise_tags', function (Blueprint $table): void {
            $table->integer('order_index')->default(0)->after('part');
            $table->index(['kind', 'order_index']);
        });

        $counters = [];
        $tags = DB::table('exercise_tags')
            ->orderByRaw("CASE WHEN kind = 'difficulty' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'kind', 'part']);

        foreach ($tags as $tag) {
            $key = $tag->kind . '|' . $tag->part;
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            DB::table('exercise_tags')->where('id', $tag->id)->update(['order_index' => $counters[$key]]);
        }
    }
};
