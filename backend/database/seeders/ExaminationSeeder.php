<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Examination;
use App\Models\ExamSessionPlan;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\ExaminationService;
use Illuminate\Database\Seeder;

class ExaminationSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure StudentIdentity rows exist for all students
        $students = User::where('role', User::ROLE_STUDENT)->get();
        foreach ($students as $st) {
            $local = strtolower(trim(explode('@', (string) $st->email)[0] ?? ''));
            if ($local !== '') {
                if (!StudentIdentity::where('student_code', $local)->where('user_id', '!=', $st->id)->exists()) {
                    StudentIdentity::updateOrCreate(
                        ['user_id' => $st->id],
                        ['student_code' => $local]
                    );
                }
            }
        }

        // 2. Seed chuẩn danh sách lớp Cơ sở dữ liệu IT004
        (new ClassSeeder())->run();
        $classes = ClassModel::orderBy('id')->get();

        // 3. Find owner (Faculty Office or Admin) & Problem QLGV01
        $owner = User::where('email', 'gvk@uit.edu.vn')->first()
            ?? User::where('role', User::ROLE_ADMIN)->first();
        if (!$owner) {
            return;
        }

        $problem = Problem::where('number', 'QLGV01')->first()
            ?? Problem::orderByDesc('created_at')->first();
        if (!$problem) {
            return;
        }

        // Gather question IDs from QLGV01 to build 2 variants (Mã 1, Mã 2)
        $ddlRow = ProblemStageExercise::where('problem_id', $problem->id)->where('part', 'DDL')->first();
        $dmlRow = ProblemStageExercise::where('problem_id', $problem->id)->where('part', 'DML')->first();
        $trigRow = ProblemStageExercise::where('problem_id', $problem->id)->where('part', 'TRIGGER')->first();
        $sqlRows = ProblemSqlSolution::where('problem_id', $problem->id)->orderBy('order_index')->get();

        $ddlIds = collect($ddlRow?->config['questions'] ?? [])->pluck('id')->values()->all();
        $dmlIds = collect($dmlRow?->config['questions'] ?? [])->pluck('id')->values()->all();
        $trigIds = collect($trigRow?->config['questions'] ?? [])->pluck('id')->values()->all();
        $sqlIds = $sqlRows->pluck('id')->values()->all();

        $v1Questions = [
            'DDL'     => array_values(array_slice($ddlIds, 0, 2)),
            'DML'     => array_values(array_slice($dmlIds, 0, 1)),
            'SQL'     => array_values(array_slice($sqlIds, 0, 2)),
            'TRIGGER' => array_values(array_slice($trigIds, 0, 1)),
        ];

        $v2Questions = [
            'DDL'     => array_values(array_slice($ddlIds, -2)),
            'DML'     => array_values(array_slice($dmlIds, -1)),
            'SQL'     => array_values(array_slice($sqlIds, -2)),
            'TRIGGER' => array_values(array_slice($trigIds, -1)),
        ];

        $questionSelection = array_merge($v1Questions, [
            '_variantCount'  => 2,
            '_variantPrefix' => 'Mã ',
            '_variants'      => [
                [
                    'code'      => '1',
                    'questions' => $v1Questions,
                ],
                [
                    'code'      => '2',
                    'questions' => $v2Questions,
                ],
            ],
        ]);

        $problemsPayload = [[
            'id'                         => $problem->id,
            'points'                     => 10.0,
            'workspaceParts'             => ['DDL', 'DML', 'SQL', 'TRIGGER'],
            'datasetVisibility'          => [],
            'questionSelection'          => $questionSelection,
            'databaseStructureVisible'   => true,
            'databaseDescriptionVisible' => true,
        ]];

        $instructors = User::whereIn('role', User::INSTRUCTOR_ROLES)->where('status', 'Active')->get();
        $proctorIds = $instructors->pluck('id')->take(2)->values()->all();
        $proctorNames = $instructors->pluck('name')->take(2)->values()->all();
        $proctor1 = !empty($proctorNames[0]) ? [$proctorNames[0]] : ['Giảng Viên Ca 1'];
        $proctor2 = !empty($proctorNames[1]) ? [$proctorNames[1]] : ['Giảng Viên Ca 2'];

        $ca1Classes = ['IT004.R11.1', 'IT004.R11.2', 'IT004.R12.1', 'IT004.R12.2', 'IT004.R13.1'];
        $ca2Classes = ['IT004.R13.2', 'IT004.N11.1', 'IT004.N11.2', 'IT004.N12.1', 'IT004.N12.2'];

        $ca1ClassProctors = [];
        foreach ($ca1Classes as $cid) {
            $ca1ClassProctors[$cid] = $proctorIds;
        }
        $ca2ClassProctors = [];
        foreach ($ca2Classes as $cid) {
            $ca2ClassProctors[$cid] = $proctorIds;
        }

        $today = now()->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d');
        $examTitle = 'Kỳ thi Thực hành Cơ sở dữ liệu Cuối kỳ HK1 (Quản lý Giáo vụ)';

        $existingExam = Examination::where('title', $examTitle)->first();
        $examService = app(ExaminationService::class);

        $payload = [
            'title'                  => $examTitle,
            'examDate'               => $today,
            'timezone'               => 'Asia/Ho_Chi_Minh',
            'scoringMode'            => 'equal_questions',
            'shortDescription'       => 'Kỳ thi thực hành SQL chính thức học kỳ 1 trên lược đồ Quản lý Giáo vụ (QLGV).',
            'description'            => 'Sinh viên hoàn thành các phần DDL, DML, DQL và Trigger trên CSDL Quản lý Giáo vụ trong thời gian quy định.',
            'rules'                  => "1. Không chuyển tab hoặc thoát toàn màn hình quá giới hạn vi phạm.\n2. Nộp bài trước khi hết giờ thi.",
            'instructions'           => 'Sử dụng tài khoản ca thi được cấp để mở khóa đề thi và làm bài.',
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
            'sessionDurationMinutes' => 90,
            'sessionGapMinutes'      => 15,
            'dayStart'               => '07:30',
            'dayEnd'                 => '23:55',
            'sessions'               => [
                [
                    'id'            => $existingExam ? Assignment::where('examination_id', $existingExam->id)->where('session_index', 1)->value('id') : null,
                    'sessionNumber' => 1,
                    'examDate'      => $today,
                    'title'         => "{$examTitle} - Ca 1",
                    'starts'        => '00:05',
                    'ends'          => '23:55',
                    'classIds'      => $ca1Classes,
                    'studentEmails' => ['24520902@gm.uit.edu.vn', 'student@gm.uit.edu.vn'],
                    'problems'      => $problemsPayload,
                    'proctors'      => $proctor1,
                    'classProctors' => $ca1ClassProctors,
                    'room'          => 'Phòng máy A101',
                    'capacity'      => 60,
                    'published'     => true,
                ],
                [
                    'id'            => $existingExam ? Assignment::where('examination_id', $existingExam->id)->where('session_index', 2)->value('id') : null,
                    'sessionNumber' => 2,
                    'examDate'      => $today,
                    'title'         => "{$examTitle} - Ca 2",
                    'starts'        => '00:05',
                    'ends'          => '23:55',
                    'classIds'      => $ca2Classes,
                    'studentEmails' => [],
                    'problems'      => $problemsPayload,
                    'proctors'      => $proctor2,
                    'classProctors' => $ca2ClassProctors,
                    'room'          => 'Phòng máy A102',
                    'capacity'      => 60,
                    'published'     => true,
                ],
            ],
        ];

        if ($existingExam) {
            $payload['expectedRevision'] = $existingExam->revision;
            $result = $examService->updateExamination($existingExam->id, $payload, $owner);
        } else {
            $result = $examService->createExamination($payload, $owner);
        }

        // Confirm session plans so credentials export is immediately ready
        foreach ($result['sessions'] ?? [] as $s) {
            ExamSessionPlan::updateOrCreate(
                ['session_id' => $s['id']],
                [
                    'confirmed_at' => now(),
                    'confirmed_by' => $owner->id,
                ]
            );
        }
    }
}
