<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\DatabaseLibraryEntry;
use App\Models\ExamAdmission;
use App\Models\Examination;
use App\Models\ExamSessionPlan;
use App\Models\Problem;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\ExaminationService;
use App\Services\ProblemService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompleteExamDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure StudentIdentities exist for students
        $students = User::where('account_type', User::ACCOUNT_STUDENT)->get();
        foreach ($students as $st) {
            $local = strtolower(trim(explode('@', (string) $st->email)[0] ?? ''));
            if ($local !== '') {
                StudentIdentity::updateOrCreate(
                    ['user_id' => $st->id],
                    ['student_code' => $local]
                );
            }
        }

        // 2. Ensure IT004 classes exist
        (new ClassSeeder())->run();

        // 3. Find Owner (Faculty Office or Admin)
        $owner = User::where('email', 'gvk@uit.edu.vn')->first()
            ?? User::whereIn('id', app(\App\Services\Access\AccessControlService::class)->superAdminIds())->first();
        if (!$owner) {
            echo "Owner user not found!\n";
            return;
        }

        // 4. Load exported seed data
        $jsonPath = storage_path('seed_sql/seed_data_exported.json');
        if (!file_exists($jsonPath)) {
            echo "Seed json not found: {$jsonPath}\n";
            return;
        }

        $exported = json_decode(file_get_contents($jsonPath), true);
        $libraries = $exported['libraries'] ?? [];
        $problemsData = $exported['problems'] ?? [];

        // 5. Seed Database Library Entry for QLGV
        $qlgvLib = $libraries['qlgv'] ?? null;
        if ($qlgvLib) {
            $existingLib = DatabaseLibraryEntry::where('title', $qlgvLib['title'])
                ->where('instructor_id', $owner->id)
                ->first();

            $libData = [
                'instructor_id'          => $owner->id,
                'title'                  => $qlgvLib['title'],
                'create_table_file_name' => "{$qlgvLib['file']}_createtable.sql",
                'create_table_sql'       => $qlgvLib['schema'],
                'insert_into_file_name'  => "{$qlgvLib['file']}_insert.sql",
                'insert_into_sql'        => $qlgvLib['datasets'][0][1] ?? '',
                'datasets_json'          => array_map(fn ($ds) => [
                    'fileName' => $ds[0],
                    'sql'      => $ds[1],
                    'isHidden' => (bool) $ds[2],
                ], $qlgvLib['datasets']),
                'revision'               => 1,
            ];

            if ($existingLib) {
                $existingLib->update($libData);
            } else {
                $libData['id'] = (string) Str::uuid();
                DatabaseLibraryEntry::create($libData);
            }
            echo "Seeded DatabaseLibraryEntry: {$qlgvLib['title']}\n";
        }

        // 6. Find or Create QLGV Problem
        $qlgvSpec = collect($problemsData)->firstWhere('number', 'QLGV');
        if (!$qlgvSpec) {
            echo "QLGV problem spec not found in json!\n";
            return;
        }

        $libSpec = $libraries[$qlgvSpec['library']];
        $schemaSql = $libSpec['schema'];
        $datasets = $libSpec['datasets'];

        // Format relations description
        $dbDescription = [];
        foreach ($qlgvSpec['relations'] as $rel) {
            $tableName = $rel[0];
            $predicate = $rel[1];
            $cols = $rel[2];
            $attrLines = [];
            foreach ($cols as $col) {
                $cName = $col[0];
                $cMeaning = $col[1];
                $cKind = $col[2];
                $attrLines[] = "{$cName} ({$cKind}): {$cMeaning}";
            }
            $dbDescription[] = [
                'tableName'  => $tableName,
                'predicate'  => $predicate,
                'attributes' => implode("\n", $attrLines),
            ];
        }

        // Test cases payload
        $testCases = [];
        foreach ($datasets as $idx => $ds) {
            $testCases[] = [
                'schema'   => $schemaSql,
                'seedData' => $ds[1],
                'isHidden' => (bool) $ds[2],
            ];
        }

        // Strip hardcoded "Câu X. " prefix so frontend numbering (Câu 1, Câu 2...) displays cleanly
        $cleanDesc = function (array $items): array {
            return array_map(function (array $q) {
                if (isset($q['description']) && is_string($q['description'])) {
                    $cleaned = preg_replace('/^Câu\s+\d+(?:\s*\([^)]+\))?\.\s*/u', '', trim($q['description']));
                    if ($cleaned !== null && $cleaned !== '') {
                        $first = mb_strtoupper(mb_substr($cleaned, 0, 1, 'UTF-8'), 'UTF-8');
                        $q['description'] = $first . mb_substr($cleaned, 1, null, 'UTF-8');
                    }
                }
                return $q;
            }, $items);
        };

        $qlgvSpec['ddl'] = $cleanDesc($qlgvSpec['ddl']);
        $qlgvSpec['dml'] = $cleanDesc($qlgvSpec['dml']);
        $qlgvSpec['trigger'] = $cleanDesc($qlgvSpec['trigger']);
        $qlgvSpec['sql'] = $cleanDesc($qlgvSpec['sql']);

        // Problem payload
        $problemPayload = [
            'number'                     => 'QLGV01',
            'title'                      => 'Quản lý Giáo vụ và Đào tạo tín chỉ (QLGV)',
            'difficulty'                 => 'Medium',
            'topics'                     => ['Quản lý giáo vụ', 'DDL', 'DML', 'Trigger', 'DQL', 'Cơ sở dữ liệu quan hệ'],
            'statement'                  => $qlgvSpec['statement'],
            'requirements'               => "Làm các câu theo từng phần DDL / DML / Trigger / SQL. Câu trigger được chấm theo kết quả: hệ thống chạy các thao tác thử và so dữ liệu với đáp án.",
            'hints'                      => [],
            'database'                   => 'SQL Server',
            'schemaFileName'             => "{$libSpec['file']}_createtable.sql",
            'databaseDescription'        => $dbDescription,
            'databaseDescriptionVisible' => true,
            'workspaceParts'             => ['DDL', 'DML', 'SQL', 'TRIGGER'],
            'schema'                     => $schemaSql,
            'seedData'                   => $datasets[0][1] ?? '',
            'testCases'                  => $testCases,
            'referenceSolution'          => $qlgvSpec['sql'][0]['query'] ?? 'SELECT 1;',
            'ddlExercise'                => ['questions' => $qlgvSpec['ddl']],
            'dmlExercise'                => ['questions' => $qlgvSpec['dml']],
            'triggerExercise'            => ['questions' => $qlgvSpec['trigger']],
            'sqlSolutions'               => $qlgvSpec['sql'],
        ];

        $problemService = app(ProblemService::class);
        $existingProblem = Problem::where('number', 'QLGV01')->first();

        if ($existingProblem) {
            $problemService->updateProblem($existingProblem->id, $problemPayload, $owner);
            $problem = Problem::find($existingProblem->id);
            echo "Updated complete Problem: QLGV01 - {$problem->title} (ID: {$problem->id})\n";
        } else {
            $res = $problemService->createProblem($problemPayload, $owner);
            $problem = Problem::find($res['id']);
            echo "Created complete Problem: QLGV01 - {$problem->title} (ID: {$problem->id})\n";
        }

        // 7. Build 4 Question Variants for Examination
        $ddlIds = collect($qlgvSpec['ddl'])->pluck('id')->values()->all();
        $dmlIds = collect($qlgvSpec['dml'])->pluck('id')->values()->all();
        $trigIds = collect($qlgvSpec['trigger'])->pluck('id')->values()->all();
        $sqlIds = collect($qlgvSpec['sql'])->pluck('id')->values()->all();

        // 4 mã đề phong phú, mỗi mã đề gồm 2 DDL, 1 DML, 3 SQL, 1 Trigger
        $variant1 = [
            'DDL'     => array_values(array_slice($ddlIds, 0, 2)), // DDL-01, DDL-02
            'DML'     => array_values(array_slice($dmlIds, 0, 1)), // DML-01
            'SQL'     => array_values(array_slice($sqlIds, 0, 3)), // DQL-01, 02, 03
            'TRIGGER' => array_values(array_slice($trigIds, 0, 1)), // TRG-17
        ];
        $variant2 = [
            'DDL'     => array_values(array_slice($ddlIds, 2, 2)), // DDL-03, DDL-04
            'DML'     => array_values(array_slice($dmlIds, 1, 1)), // DML-02
            'SQL'     => array_values(array_slice($sqlIds, 3, 3)), // DQL-04, 05, 06
            'TRIGGER' => array_values(array_slice($trigIds, 0, 1)),
        ];
        $variant3 = [
            'DDL'     => array_values(array_slice($ddlIds, 4, 2)), // DDL-05, DDL-06
            'DML'     => array_values(array_slice($dmlIds, 2, 1)), // DML-03
            'SQL'     => array_values(array_slice($sqlIds, 6, 3)), // DQL-07, 08, 09
            'TRIGGER' => array_values(array_slice($trigIds, 0, 1)),
        ];
        $variant4 = [
            'DDL'     => array_values(array_slice($ddlIds, 6, 2)), // DDL-07, DDL-08
            'DML'     => array_values(array_slice($dmlIds, 3, 1)), // DML-04
            'SQL'     => array_values(array_slice($sqlIds, 9, 3)), // DQL-10, 11, 12
            'TRIGGER' => array_values(array_slice($trigIds, 0, 1)),
        ];

        $questionSelection = array_merge($variant1, [
            '_variantCount'  => 4,
            '_variantPrefix' => 'DE',
            '_variants'      => [
                ['code' => 'DE1', 'questions' => $variant1],
                ['code' => 'DE2', 'questions' => $variant2],
                ['code' => 'DE3', 'questions' => $variant3],
                ['code' => 'DE4', 'questions' => $variant4],
            ],
        ]);

        $examProblemPayload = [[
            'id'                         => $problem->id,
            'points'                     => 10.0,
            'workspaceParts'             => ['DDL', 'DML', 'SQL', 'TRIGGER'],
            'datasetVisibility'          => [],
            'questionSelection'          => $questionSelection,
            'databaseStructureVisible'   => true,
            'databaseDescriptionVisible' => true,
        ]];

        // 8. Create or Update Examination
        $today = now()->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d');
        $examTitle = 'Kỳ thi Thực hành Cơ sở dữ liệu Cuối kỳ HK1 (Quản lý Giáo vụ)';
        $existingExam = Examination::where('title', $examTitle)->first();
        $examService = app(ExaminationService::class);

        $instructors = User::whereIn('id', app(\App\Services\Access\AccessControlService::class)->holdersOf('class.teach'))->where('status', 'Active')->get();
        $proctorIds = $instructors->pluck('id')->take(2)->values()->all();
        $proctorNames = $instructors->pluck('name')->take(2)->values()->all();
        $proctor1 = !empty($proctorNames[0]) ? [$proctorNames[0]] : ['Giảng Viên Giám Thị 1'];

        $ca1Classes = ['IT004.R11.1', 'IT004.R11.2', 'IT004.R12.1', 'IT004.R12.2', 'IT004.R13.1'];
        $ca1ClassProctors = [];
        foreach ($ca1Classes as $cid) {
            $ca1ClassProctors[$cid] = $proctorIds;
        }

        // Candidates: students from classes + free candidates
        $candidateEmails = [
            '22520014@gm.uit.edu.vn', // Vũ Minh Đăng
            '24520902@gm.uit.edu.vn', // Lê Quốc Kiệt
            'student@gm.uit.edu.vn',  // Trần Thị B
            '22520024@gm.uit.edu.vn', // Bùi Phương Thảo
            '23520033@gm.uit.edu.vn', // Bành Xuân Phúc
        ];

        $examPayload = [
            'title'                  => $examTitle,
            'examDate'               => $today,
            'timezone'               => 'Asia/Ho_Chi_Minh',
            'scoringMode'            => 'equal_questions',
            'shortDescription'       => 'Kỳ thi thực hành SQL chính thức học kỳ 1 trên lược đồ Quản lý Giáo vụ (QLGV).',
            'description'            => 'Sinh viên hoàn thành các phần DDL, DML, DQL và Trigger trên CSDL Quản lý Giáo vụ trong thời gian quy định.',
            'rules'                  => "1. Không chuyển tab hoặc thoát toàn màn hình quá giới hạn vi phạm.\n2. Nộp bài trước khi hết giờ thi.",
            'instructions'           => 'Sử dụng tài khoản ca thi được cấp để mở khóa đề thi và làm bài.',
            'published'              => true,
            'problems'               => $examProblemPayload,
            'studentOptions'         => [
                'hints'                 => false,
                'comments'              => false,
                'leaderboard'           => false,
                'aiAllowed'             => false,
                'examMode'              => true,
                'examViolationLimit'    => 5,
                'showScoresAfterSubmit' => false,
                'maxSubmissions'        => 3,
            ],
            'sessionDurationMinutes' => 90,
            'sessionGapMinutes'      => 15,
            'dayStart'               => '00:00',
            'dayEnd'                 => '23:59',
            'sessions'               => [
                [
                    'id'            => $existingExam ? Assignment::where('examination_id', $existingExam->id)->where('session_index', 1)->value('id') : null,
                    'sessionNumber' => 1,
                    'examDate'      => $today,
                    'title'         => "{$examTitle} - Ca 1",
                    'starts'        => '00:00',
                    'ends'          => '23:59',
                    'classIds'      => $ca1Classes,
                    'studentEmails' => $candidateEmails,
                    'problems'      => $examProblemPayload,
                    'proctors'      => $proctor1,
                    'classProctors' => $ca1ClassProctors,
                    'room'          => 'Phòng máy A101',
                    'capacity'      => 60,
                    'published'     => true,
                ],
            ],
        ];

        if ($existingExam) {
            $examPayload['expectedRevision'] = $existingExam->revision;
            $result = $examService->updateExamination($existingExam->id, $examPayload, $owner);
            echo "Updated Examination: {$existingExam->title}\n";
        } else {
            $result = $examService->createExamination($examPayload, $owner);
            echo "Created Examination: {$examTitle}\n";
        }

        // Confirm session plans
        foreach ($result['sessions'] ?? [] as $s) {
            ExamSessionPlan::updateOrCreate(
                ['session_id' => $s['id']],
                [
                    'confirmed_at' => now(),
                    'confirmed_by' => $owner->id,
                ]
            );
        }

        // 9. Reset / Issue clean temporary credentials with friendly passwords
        $sessionId = $result['sessions'][0]['id'] ?? null;
        if ($sessionId) {
            $session = Assignment::find($sessionId);
            // Ensure session start/end is wide open for today
            $session->opens = now()->startOfDay();
            $session->closes = now()->endOfDay();
            $session->published = true;
            $session->save();

            // Fetch admissions for this session
            $admissions = ExamAdmission::where('session_id', $sessionId)
                ->where('status', '!=', 'revoked')
                ->orderBy('student_id')
                ->get();

            echo "\n==================== DANH SÁCH TÀI KHOẢN CA THI ====================\n";
            printf("%-10s | %-20s | %-8s | %-25s | %-12s\n", "MSSV", "Họ Tên", "Mã Đề", "Tài Khoản (Username)", "Mật Khẩu");
            echo str_repeat('-', 85) . "\n";

            foreach ($admissions as $adm) {
                $st = User::find($adm->student_id);
                $mssv = $st?->studentIdentity?->student_code ?? explode('@', (string) $st?->email)[0];
                
                // If password empty or we want a solid test password
                if (empty($adm->initial_password)) {
                    $rawPwd = 'Pass@' . substr(md5($adm->id), 0, 5);
                    $adm->initial_password = $rawPwd;
                    $adm->hashed_password = Hash::make($rawPwd);
                    $adm->save();
                }

                printf(
                    "%-10s | %-20s | %-8s | %-25s | %-12s\n",
                    $mssv,
                    mb_substr($st?->name ?? 'Sinh viên', 0, 20),
                    $adm->variant_code ?? 'DE1',
                    $adm->username,
                    $adm->initial_password
                );
            }
            echo "====================================================================\n";
        }
    }
}

