<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Examination;
use App\Models\ExamSessionPlan;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\User;
use App\Services\ExaminationService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Kỳ thi cuối kỳ "Thực hành Cơ sở dữ liệu" trên CSDL QLPT, 4 mã đề, 2 ca (mỗi ca một lớp IT004.O21.x).
 *
 * Mỗi mã đề có đủ các dạng câu như đề thi HK1 2024-2025: Câu 1 tạo bảng (3đ), 2 câu CHECK (0.5đ), 1 trigger (1đ),
 * 5 câu truy vấn (1đ) = 10 điểm. Câu hỏi lấy từ bài QLPT (UitProblemSeeder) và trộn chéo giữa 4 đề gốc 101, 102, 201, 202:
 * mã đề k lấy CHECK 2.1 từ đề gốc k+1, CHECK 2.2 từ k+2, trigger từ k+3, và câu truy vấn 3.s từ đề gốc k+s (mod 4),
 * nên không mã đề nào trùng một đề gốc và mỗi dạng câu xuất hiện đủ ở mọi mã. Điểm từng câu nằm ở
 * `_questionPoints` của cấu hình chọn câu (chấm theo QuestionScoring).
 *
 * Chạy riêng (không nằm trong DatabaseSeeder), sau UitProblemSeeder và UitPracticeClassSeeder:
 *   php artisan db:seed --class=Database\\Seeders\\UitExaminationSeeder
 *
 * Idempotent: kỳ thi khớp theo tên; có rồi thì cập nhật (giữ nguyên các ca). Không xóa gì.
 * Chủ kỳ thi là gvk@uit.edu.vn (Giáo vụ khoa); chưa có tài khoản thì tạo bằng UserSeeder.
 */
class UitExaminationSeeder extends Seeder
{
    public const OWNER_EMAIL = 'gvk@uit.edu.vn';
    public const TITLE = 'Kỳ thi Thực hành Cơ sở dữ liệu Cuối kỳ (Quản lý cho thuê phòng trọ)';
    public const PROBLEM_NUMBER = 'QLPT';

    /** Đề gốc theo thứ tự xoay vòng. */
    private const SOURCE_PAPERS = ['101', '102', '201', '202'];

    /** Hai ca: [lớp, giờ mở, giờ đóng, phòng]. Hai khung giờ không chồng nhau nên không trùng giám thị. */
    private const SESSIONS = [
        ['class' => 'IT004.O21.1', 'starts' => '00:05', 'ends' => '12:00', 'room' => 'Phòng máy A101'],
        ['class' => 'IT004.O21.2', 'starts' => '12:05', 'ends' => '23:55', 'room' => 'Phòng máy A102'],
    ];

    public const POINTS_CREATE = 3.0;
    public const POINTS_CHECK = 0.5;
    public const POINTS_TRIGGER = 1.0;
    public const POINTS_QUERY = 1.0;

    /**
     * 4 mã đề dựng từ định nghĩa bài QLPT (không ghi DB); dùng cho test.
     *
     * @return array<int, array{code: string, questions: array<string, array>}>
     */
    public static function variants(): array
    {
        $qlpt = UitProblemSeeder::definitions()['qlpt'];
        $createId = $qlpt['ddl'][0]['id'];
        $n = count(self::SOURCE_PAPERS);
        // Mã câu của bài QLPT: qlpt-{đề gốc}-{loại}-{số câu}, ví dụ qlpt-101-dql-3-2.
        $pick = fn (int $offset, string $kind): string => 'qlpt-' . self::SOURCE_PAPERS[$offset % $n] . '-' . $kind;

        $variants = [];
        for ($k = 0; $k < $n; $k++) {
            $check1 = $pick($k + 1, 'ddl-2-1');
            $check2 = $pick($k + 2, 'ddl-2-2');
            $trigger = $pick($k + 3, 'trg-2-3');
            $queries = [];
            foreach (range(1, 5) as $s) {
                $queries[] = $pick($k + $s - 1, 'dql-3-' . $s);
            }

            $variants[] = [
                'code'      => (string) ($k + 1),
                // Mảng mã câu theo từng phần: đúng dạng giao diện soạn kỳ thi đọc và lưu.
                'questions' => [
                    'DDL'     => [$createId, $check1, $check2],
                    'TRIGGER' => [$trigger],
                    'SQL'     => $queries,
                ],
            ];
        }

        return $variants;
    }

    /**
     * Điểm từng câu theo phần: [PHẦN => [mã câu => điểm]], lưu ở `_questionPoints` của cấu hình chọn câu.
     * Điểm phụ thuộc vị trí câu trong đề (Câu 1 = 3, CHECK = 0.5, trigger = 1, truy vấn = 1) nên một mã câu
     * luôn cùng điểm ở mọi mã đề.
     *
     * @return array<string, array<string, float>>
     */
    public static function questionPoints(): array
    {
        $byPosition = [
            'DDL'     => [self::POINTS_CREATE, self::POINTS_CHECK, self::POINTS_CHECK],
            'TRIGGER' => [self::POINTS_TRIGGER],
            'SQL'     => array_fill(0, 5, self::POINTS_QUERY),
        ];

        $points = [];
        foreach (self::variants() as $v) {
            foreach ($v['questions'] as $part => $ids) {
                foreach ($ids as $i => $id) {
                    if (isset($points[$part][$id]) && $points[$part][$id] !== $byPosition[$part][$i]) {
                        throw new RuntimeException("Câu {$id} có hai mức điểm khác nhau giữa các mã đề.");
                    }
                    $points[$part][$id] = $byPosition[$part][$i];
                }
            }
        }

        return $points;
    }

    public function run(): void
    {
        if (!User::where('email', self::OWNER_EMAIL)->exists()) {
            $this->call(UserSeeder::class);
        }
        $owner = User::where('email', self::OWNER_EMAIL)->where('status', 'Active')->first();
        if ($owner === null) {
            throw new RuntimeException('Không có tài khoản ' . self::OWNER_EMAIL . ' đang hoạt động.');
        }

        $problem = Problem::where('number', self::PROBLEM_NUMBER)->first();
        if ($problem === null) {
            throw new RuntimeException('Chưa có bài QLPT; chạy UitProblemSeeder trước.');
        }
        $variants = self::variants();
        $this->assertQuestionsExist($problem, $variants);

        $classes = ClassModel::whereIn('id', array_column(self::SESSIONS, 'class'))->get()->keyBy('id');
        foreach (self::SESSIONS as $s) {
            if (!$classes->has($s['class'])) {
                throw new RuntimeException("Chưa có lớp {$s['class']}; chạy UitPracticeClassSeeder trước.");
            }
        }

        $first = $variants[0]['questions'];
        $problemsPayload = [[
            'id'                         => $problem->id,
            'points'                     => 10.0,
            'workspaceParts'             => ['DDL', 'SQL', 'TRIGGER'],
            'datasetVisibility'          => [],
            'questionSelection'          => array_merge($first, [
                '_variantCount'   => count($variants),
                '_variantPrefix'  => 'Mã ',
                '_variants'       => $variants,
                '_questionPoints' => self::questionPoints(),
            ]),
            'databaseStructureVisible'   => true,
            'databaseDescriptionVisible' => true,
        ]];

        $today = now()->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d');
        $service = app(ExaminationService::class);
        $existing = Examination::where('title', self::TITLE)->first();

        $sessions = [];
        foreach (self::SESSIONS as $i => $s) {
            $instructorId = $classes[$s['class']]->instructor_id;
            $sessions[] = [
                'id'            => $existing ? Assignment::where('examination_id', $existing->id)->where('session_index', $i + 1)->value('id') : null,
                'sessionNumber' => $i + 1,
                'examDate'      => $today,
                'title'         => self::TITLE . ' - Ca ' . ($i + 1),
                'starts'        => $s['starts'],
                'ends'          => $s['ends'],
                'classIds'      => [$s['class']],
                'studentEmails' => [],
                'problems'      => $problemsPayload,
                'proctors'      => $instructorId ? array_values(array_filter([User::find($instructorId)?->name])) : [],
                'classProctors' => $instructorId ? [$s['class'] => [$instructorId]] : [],
                'room'          => $s['room'],
                'capacity'      => 60,
                'published'     => true,
            ];
        }

        $payload = [
            'title'                  => self::TITLE,
            'examDate'               => $today,
            'timezone'               => 'Asia/Ho_Chi_Minh',
            'scoringMode'            => 'custom_questions',
            'shortDescription'       => 'Kỳ thi thực hành SQL cuối kỳ trên lược đồ Quản lý cho thuê phòng trọ (QLPT), 4 mã đề.',
            'description'            => 'Sinh viên hoàn thành Câu 1 (tạo bảng), Câu 2 (ràng buộc CHECK và trigger) và Câu 3 (truy vấn) trên CSDL QLPT.',
            'rules'                  => "1. Sinh viên không được sử dụng tài liệu.\n2. Không chuyển tab hoặc thoát toàn màn hình quá giới hạn vi phạm.\n3. Nộp bài trước khi hết giờ thi.",
            'instructions'           => 'Sử dụng tài khoản ca thi được cấp để mở khóa đề thi và làm bài. Thang điểm: Câu 1 = 3, mỗi câu CHECK = 0.5, trigger = 1, mỗi câu truy vấn = 1.',
            'published'              => true,
            'problems'               => $problemsPayload,
            'studentOptions'         => [
                'hints'                 => false,
                'comments'              => false,
                'leaderboard'           => false,
                'aiAllowed'             => false,
                'examMode'              => true,
                'examViolationLimit'    => 3,
                'showScoresAfterSubmit' => false,
                'maxSubmissions'        => 1,
            ],
            'sessionDurationMinutes' => 75,
            'sessionGapMinutes'      => 5,
            'dayStart'               => '00:05',
            'dayEnd'                 => '23:55',
            'sessions'               => $sessions,
        ];

        if ($existing) {
            $payload['expectedRevision'] = $existing->revision;
            $result = $service->updateExamination($existing->id, $payload, $owner);
            $this->command?->info("  cập nhật kỳ thi {$existing->id}");
        } else {
            $result = $service->createExamination($payload, $owner);
            $this->command?->info("  tạo kỳ thi {$result['id']}");
        }

        // Xác nhận kế hoạch ca thi để xuất tài khoản thi ngay được.
        foreach ($result['sessions'] ?? [] as $s) {
            ExamSessionPlan::updateOrCreate(['session_id' => $s['id']], ['confirmed_at' => now(), 'confirmed_by' => $owner->id]);
        }
    }

    /** Mọi mã câu của các mã đề phải có trong bài QLPT đã seed. */
    private function assertQuestionsExist(Problem $problem, array $variants): void
    {
        $have = [
            'SQL' => ProblemSqlSolution::where('problem_id', $problem->id)->pluck('id')->all(),
        ];
        foreach (['DDL', 'TRIGGER'] as $part) {
            $row = ProblemStageExercise::where('problem_id', $problem->id)->where('part', $part)->first();
            $have[$part] = array_column($row?->config['questions'] ?? [], 'id');
        }

        foreach ($variants as $v) {
            foreach ($v['questions'] as $part => $ids) {
                $missing = array_diff($ids, $have[$part]);
                if ($missing !== []) {
                    throw new RuntimeException("Mã đề {$v['code']}, phần {$part}: bài QLPT thiếu câu " . implode(', ', $missing) . '; chạy lại UitProblemSeeder.');
                }
            }
        }
    }
}
