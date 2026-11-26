<?php

namespace App\Services;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\AssignmentClass;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Examination;
use App\Models\Submission;
use App\Models\ExamRegrade;
use App\Models\User;
use App\Services\Access\StaffAccess;
use App\Models\WorkspaceSubmissionResult;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StatisticsService
{
    public function getTeacherStatistics(User $user, array $filters): array
    {
        return $this->compute($user, false, $filters);
    }

    public function getOfficeStatistics(User $user, array $filters): array
    {
        return $this->compute($user, true, $filters);
    }

    protected function compute(User $user, bool $isOffice, array $filters): array
    {
        $kind = (string) ($filters['kind'] ?? ($isOffice ? 'all' : 'assignments'));
        if (!in_array($kind, ['assignments', 'exams', 'all'], true)) {
            $kind = $isOffice ? 'all' : 'assignments';
        }
        $term = trim((string) ($filters['term'] ?? ''));
        $course = trim((string) ($filters['course'] ?? ''));
        $examinationId = trim((string) ($filters['examinationId'] ?? ''));
        $classIds = array_values(array_filter(array_map('strval', (array) ($filters['classIds'] ?? []))));
        $activityIds = array_values(array_filter(array_map('strval', (array) ($filters['activityIds'] ?? []))));
        $dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
        $dateTo = trim((string) ($filters['dateTo'] ?? ''));

        // 1. Resolve classes in role scope
        $baseClassesQuery = ClassModel::query();
        if (!$isOffice && !app(StaffAccess::class)->seesEverything($user)) {
            $baseClassesQuery->where('instructor_id', $user->id);
        }
        $allScopedClasses = $baseClassesQuery->orderBy('course')->orderBy('id')->get();

        $terms = $allScopedClasses->pluck('term')->filter()->unique()->sort()->values()->all();
        $courses = $allScopedClasses->pluck('course')->filter()->unique()->sort()->values()->all();

        $filteredClasses = $allScopedClasses
            ->when($term !== '', fn (Collection $c) => $c->where('term', $term))
            ->when($course !== '', fn (Collection $c) => $c->where('course', $course))
            ->values();

        $scopedClassIds = !empty($classIds)
            ? $filteredClasses->whereIn('id', $classIds)->pluck('id')->all()
            : $filteredClasses->pluck('id')->all();

        $enrollments = !empty($scopedClassIds)
            ? ClassEnrollment::whereIn('class_id', $scopedClassIds)->get()
            : collect();
        $classStudentMap = [];
        foreach ($enrollments as $enr) {
            $classStudentMap[$enr->class_id][] = (string) $enr->student_id;
        }
        $allStudentIds = $enrollments->pluck('student_id')->map(fn ($id) => (string) $id)->unique()->values()->all();

        // 2. Resolve activities in role scope
        $activitiesQuery = Assignment::with(['classes:id', 'assignmentProblems']);
        if ($kind === 'assignments') {
            $activitiesQuery->whereNull('examination_id')->where('exam_mode', false);
        } elseif ($kind === 'exams') {
            $activitiesQuery->where(function ($q) {
                $q->whereNotNull('examination_id')->orWhere('exam_mode', true);
            });
        }
        if ($examinationId !== '') {
            $activitiesQuery->where('examination_id', $examinationId);
        }

        $candidateActivities = $activitiesQuery->orderByDesc('created_at')->get();
        if (!$isOffice && !app(StaffAccess::class)->seesEverything($user)) {
            $ownedClassSet = array_flip($allScopedClasses->pluck('id')->all());
            $candidateActivities = $candidateActivities->filter(function (Assignment $a) use ($user, $ownedClassSet) {
                if ((string) $a->instructor_id === (string) $user->id) {
                    return true;
                }
                foreach ($a->classes as $c) {
                    if (isset($ownedClassSet[(string) $c->id])) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        if ($term !== '' || $course !== '' || !empty($classIds)) {
            $selectedClassSet = array_flip($scopedClassIds);
            $candidateActivities = $candidateActivities->filter(function (Assignment $a) use ($selectedClassSet) {
                if ($a->classes->isEmpty()) {
                    return false;
                }
                foreach ($a->classes as $c) {
                    if (isset($selectedClassSet[(string) $c->id])) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        $selectedActivities = !empty($activityIds)
            ? $candidateActivities->whereIn('id', $activityIds)->values()
            : $candidateActivities;

        $selectedActivityIds = $selectedActivities->pluck('id')->all();

        // 3. Official submissions for selected activities
        $subs = collect();
        if (!empty($selectedActivityIds)) {
            $subsQuery = Submission::whereIn('context', $selectedActivityIds)
                ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
                ->whereDoesntHave('workspaceResult', function ($q) {
                    $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
                });
            // Giảng viên chỉ thống kê sinh viên thuộc lớp mình dạy; bộ lọc học kỳ/môn/lớp thu hẹp thêm.
            $limitToScopeStudents = (!$isOffice && !app(StaffAccess::class)->seesEverything($user))
                || !empty($classIds) || $term !== '' || $course !== '';
            if ($limitToScopeStudents) {
                if (!empty($allStudentIds)) {
                    $subsQuery->whereIn('user_id', $allStudentIds);
                } else {
                    $subsQuery->whereRaw('1 = 0');
                }
            }
            if ($dateFrom !== '') {
                $subsQuery->where('submitted_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo !== '') {
                $subsQuery->where('submitted_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }
            $subs = $subsQuery->orderByDesc('submitted_at')->get();
        }

        // Map assignment problem points
        $apPointsMap = [];
        $actProblemPoints = [];
        $examActivityIds = [];
        foreach ($selectedActivities as $act) {
            if ($act->examination_id || $act->exam_mode) {
                $examActivityIds[(string) $act->id] = true;
            }
            foreach ($act->assignmentProblems as $ap) {
                $pts = max(1.0, (float) $ap->points);
                $apPointsMap["{$act->id}:{$ap->problem_id}"] = $pts;
                $actProblemPoints[(string) $act->id][(string) $ap->problem_id] = $pts;
            }
        }

        // Lượt đại diện cho mỗi (hoạt động, sinh viên, bài toán): điểm tự động cao nhất, bằng điểm thì lượt nộp sau
        // cùng (cùng quy tắc với trang chấm bài). $subs đã sắp theo thời gian nộp mới nhất trước.
        $latestSubs = [];
        foreach ($subs as $sub) {
            $key = "{$sub->context}:{$sub->user_id}:{$sub->problem_id}";
            if (!isset($latestSubs[$key]) || (float) ($sub->score ?? 0) > (float) ($latestSubs[$key]->score ?? 0)) {
                $latestSubs[$key] = $sub;
            }
        }
        $latestList = array_values($latestSubs);

        // Compute normalized score (out of 10) per latest submission
        $studentScores = [];
        $pendingGrading = 0;
        $verdictCounts = [
            'Accepted'     => 0,
            'Partial'      => 0,
            'Wrong Answer' => 0,
            'Needs review' => 0,
        ];
        $bins = [
            ['label' => '0–2', 'min' => 0.0, 'max' => 2.0, 'count' => 0],
            ['label' => '2–4', 'min' => 2.0, 'max' => 4.0, 'count' => 0],
            ['label' => '4–5', 'min' => 4.0, 'max' => 5.0, 'count' => 0],
            ['label' => '5–6.5', 'min' => 5.0, 'max' => 6.5, 'count' => 0],
            ['label' => '6.5–8', 'min' => 6.5, 'max' => 8.0, 'count' => 0],
            ['label' => '8–9', 'min' => 8.0, 'max' => 9.0, 'count' => 0],
            ['label' => '9–10', 'min' => 9.0, 'max' => 10.01, 'count' => 0],
        ];

        $activityDoneUsers = [];
        $activityPartialUsers = [];
        $activityScores = [];
        $perActivityStudent = [];
        foreach ($latestList as $sub) {
            $act = (string) $sub->context;
            $uid = (string) $sub->user_id;
            $pid = (string) $sub->problem_id;
            $maxPts = $apPointsMap["{$act}:{$pid}"] ?? 10.0;
            $rawScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : (float) ($sub->score ?? 0);
            $norm10 = round(min(10.0, max(0.0, ($rawScore / $maxPts) * 10.0)), 2);

            if (isset($actProblemPoints[$act][$pid])) {
                $perActivityStudent[$act][$uid]['raw'] = ($perActivityStudent[$act][$uid]['raw'] ?? 0.0) + min($maxPts, max(0.0, $rawScore));
                $perActivityStudent[$act][$uid]['problems'][$pid] = true;
            }

            // Chờ chấm tay chỉ áp dụng cho bài thi; bài tập thường đã có điểm tự động.
            if ($sub->evaluated_score === null && isset($examActivityIds[$act])) {
                $pendingGrading++;
            }

            if ($sub->evaluated_score === null && $sub->result === null) {
                $verdictCounts['Needs review']++;
            } elseif ($sub->result === 'Accepted' || $norm10 >= 9.99) {
                $verdictCounts['Accepted']++;
            } elseif ($sub->result === 'Partial' || ($norm10 > 0 && $norm10 < 9.99)) {
                $verdictCounts['Partial']++;
            } else {
                $verdictCounts['Wrong Answer']++;
            }
        }

        // Điểm theo hoạt động: tổng điểm đạt / tổng điểm của cả bài * 10. Chỉ tính là "đã nộp" khi nộp đủ mọi bài
        // toán của hoạt động; nộp thiếu thì chưa tính điểm.
        foreach ($perActivityStudent as $act => $byUser) {
            $totalProblems = count($actProblemPoints[$act] ?? []);
            $totalPoints = array_sum($actProblemPoints[$act] ?? []);
            foreach ($byUser as $uid => $info) {
                if ($totalProblems > 0 && count($info['problems']) >= $totalProblems) {
                    $score10 = round(min(10.0, max(0.0, ($info['raw'] / $totalPoints) * 10.0)), 2);
                    $studentScores[(string) $uid][] = $score10;
                    $activityDoneUsers[$act][(string) $uid] = true;
                    $activityScores[$act][] = $score10;
                } else {
                    $activityPartialUsers[$act][(string) $uid] = true;
                }
            }
        }

        // Student-level average & histogram
        $participantScores = [];
        $belowCount = 0;
        foreach ($studentScores as $uid => $scores) {
            $avg = round(array_sum($scores) / count($scores), 2);
            $participantScores[$uid] = $avg;
            if ($avg < 5.0) {
                $belowCount++;
            }
            foreach ($bins as &$b) {
                if ($avg >= $b['min'] && $avg < $b['max']) {
                    $b['count']++;
                    break;
                }
            }
            unset($b);
        }

        $totalStudentsInScope = count($allStudentIds);
        if ($totalStudentsInScope === 0 && !empty($participantScores)) {
            $totalStudentsInScope = count($participantScores);
        }
        $participantsCount = count($participantScores);
        $overallAvg = $participantsCount > 0 ? round(array_sum($participantScores) / $participantsCount, 2) : null;
        $passCount = $participantsCount - $belowCount;
        $passRate = $participantsCount > 0 ? round(($passCount / $participantsCount) * 100, 1) : 0.0;

        // 4. Progress per activity (done / inProgress / notStarted)
        $attempts = !empty($selectedActivityIds)
            ? ActivityAttempt::whereIn('assignment_id', $selectedActivityIds)->get()
            : collect();
        $attemptMap = [];
        foreach ($attempts as $att) {
            $attemptMap[(string) $att->assignment_id][(string) $att->user_id] = $att;
        }

        $progress = [];
        foreach ($selectedActivities->take(12) as $act) {
            $actClassIds = $act->classes->pluck('id')->all();
            if (!empty($scopedClassIds) && !empty($actClassIds)) {
                $actClassIds = array_values(array_intersect($actClassIds, $scopedClassIds));
            }
            $eligibleSet = [];
            foreach ($actClassIds as $cid) {
                foreach ($classStudentMap[$cid] ?? [] as $sid) {
                    $eligibleSet[$sid] = true;
                }
            }
            $doneSet = $activityDoneUsers[(string) $act->id] ?? [];
            foreach (array_keys($doneSet) as $sid) {
                $eligibleSet[$sid] = true;
            }
            foreach (array_keys($activityPartialUsers[(string) $act->id] ?? []) as $sid) {
                $eligibleSet[$sid] = true;
            }

            $total = count($eligibleSet);
            if ($total === 0) {
                continue;
            }
            $done = count($doneSet);
            $inProgress = 0;
            foreach (array_keys($eligibleSet) as $sid) {
                if (!isset($doneSet[$sid]) && (isset($attemptMap[(string) $act->id][$sid]) || isset($activityPartialUsers[(string) $act->id][$sid]))) {
                    $inProgress++;
                }
            }
            $notStarted = max(0, $total - $done - $inProgress);

            $progress[] = [
                'id'         => (string) $act->id,
                'title'      => (string) $act->title,
                'done'       => $done,
                'inProgress' => $inProgress,
                'notStarted' => $notStarted,
                'total'      => $total,
            ];
        }

        // 5. Daily trend (submissions + average score)
        $trendBuckets = [];
        foreach ($subs as $sub) {
            if (!$sub->submitted_at) {
                continue;
            }
            $day = $sub->submitted_at->format('Y-m-d');
            $maxPts = $apPointsMap["{$sub->context}:{$sub->problem_id}"] ?? 10.0;
            $rawScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : (float) ($sub->score ?? 0);
            $norm10 = min(10.0, max(0.0, ($rawScore / $maxPts) * 10.0));
            $trendBuckets[$day]['count'] = ($trendBuckets[$day]['count'] ?? 0) + 1;
            $trendBuckets[$day]['sum'] = ($trendBuckets[$day]['sum'] ?? 0.0) + $norm10;
        }
        ksort($trendBuckets);
        $trend = [];
        foreach ($trendBuckets as $day => $b) {
            $trend[] = [
                'date'        => $day,
                'label'       => Carbon::parse($day)->format('d/m'),
                'submissions' => $b['count'],
                'avgScore'    => round($b['sum'] / $b['count'], 2),
            ];
        }
        if (count($trend) > 20) {
            $trend = array_slice($trend, -20);
        }

        // 6. Class averages & pass rates
        $classAverages = [];
        foreach ($filteredClasses as $cls) {
            if (!empty($classIds) && !in_array((string) $cls->id, $classIds, true)) {
                continue;
            }
            $sids = $classStudentMap[$cls->id] ?? [];
            $scores = [];
            $passed = 0;
            foreach ($sids as $sid) {
                if (isset($participantScores[$sid])) {
                    $scores[] = $participantScores[$sid];
                    if ($participantScores[$sid] >= 5.0) {
                        $passed++;
                    }
                }
            }
            $classAverages[] = [
                'id'           => (string) $cls->id,
                'course'       => (string) $cls->course,
                'students'     => count($sids),
                'participants' => count($scores),
                'averageScore' => count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : null,
                'passRate'     => count($scores) > 0 ? round(($passed / count($scores)) * 100, 1) : 0.0,
            ];
        }

        // 7. Weak categories from WorkspaceSubmissionResult sections (DDL / DML / SQL / TRIGGER)
        $latestIds = array_map(fn ($s) => $s->id, $latestList);
        $wsResults = !empty($latestIds)
            ? WorkspaceSubmissionResult::whereIn('submission_id', $latestIds)->get()
            : collect();
        $partStats = [];
        foreach ($wsResults as $ws) {
            if (!is_array($ws->sections)) {
                continue;
            }
            foreach ($ws->sections as $sec) {
                $part = strtoupper((string) ($sec['part'] ?? 'SQL'));
                foreach (($sec['questions'] ?? []) as $q) {
                    $partStats[$part]['total'] = ($partStats[$part]['total'] ?? 0) + 1;
                    $passed = ($q['status'] ?? '') === 'Accepted' || ((float) ($q['ratio'] ?? 0) >= 1.0);
                    if (!$passed) {
                        $partStats[$part]['failed'] = ($partStats[$part]['failed'] ?? 0) + 1;
                    }
                }
            }
        }
        $weakCategories = [];
        foreach ($partStats as $part => $st) {
            $tot = $st['total'];
            $fail = $st['failed'] ?? 0;
            $weakCategories[] = [
                'name'       => $part,
                'total'      => $tot,
                'failed'     => $fail,
                'failureRate'=> $tot > 0 ? round(($fail / $tot) * 100, 1) : 0.0,
            ];
        }
        usort($weakCategories, fn ($a, $b) => $b['failureRate'] <=> $a['failureRate']);

        // 8. Office-level overview metrics (course averages, regrades, violations, grading progress)
        $officeOverview = null;
        $examinationsList = Examination::orderByDesc('exam_date')->get(['id', 'title', 'exam_date']);
        if ($isOffice) {
            $courseBuckets = [];
            foreach ($classAverages as $ca) {
                $cName = $ca['course'] ?: 'Khác';
                $courseBuckets[$cName]['students'] = ($courseBuckets[$cName]['students'] ?? 0) + $ca['students'];
                $courseBuckets[$cName]['participants'] = ($courseBuckets[$cName]['participants'] ?? 0) + $ca['participants'];
                if ($ca['averageScore'] !== null) {
                    $courseBuckets[$cName]['weightedSum'] = ($courseBuckets[$cName]['weightedSum'] ?? 0.0) + ($ca['averageScore'] * $ca['participants']);
                    $courseBuckets[$cName]['passedCount'] = ($courseBuckets[$cName]['passedCount'] ?? 0.0) + (($ca['passRate'] / 100.0) * $ca['participants']);
                }
            }
            $courseAverages = [];
            foreach ($courseBuckets as $cName => $cb) {
                $p = $cb['participants'];
                $courseAverages[] = [
                    'course'       => $cName,
                    'students'     => $cb['students'],
                    'participants' => $p,
                    'averageScore' => $p > 0 ? round(($cb['weightedSum'] ?? 0) / $p, 2) : null,
                    'passRate'     => $p > 0 ? round((($cb['passedCount'] ?? 0) / $p) * 100, 1) : 0.0,
                ];
            }

            // Phúc khảo gắn với cả bài thi (ca thi, sinh viên); khóa theo id bài nộp mới nhất của sinh viên đó.
            $regrades = ExamRegrade::mapForSubmissions($latestList);
            $latestById = [];
            foreach ($latestList as $s) {
                $latestById[(string) $s->id] = $s;
            }

            $awaitingRegradeCount = 0;
            $completedRegradeCount = 0;
            $scoreChangedCount = 0;
            foreach ($regrades as $sid => $reg) {
                $subObj = $latestById[(string) $sid] ?? null;
                if ($reg->completed_at !== null) {
                    $completedRegradeCount++;
                    $prevManual = $reg->previous_evaluated_score;
                    if (
                        $subObj
                        && $prevManual !== null
                        && $subObj->evaluated_score !== null
                        && round((float) $subObj->evaluated_score, 2) !== round((float) $prevManual, 2)
                    ) {
                        $scoreChangedCount++;
                    }
                } else {
                    $awaitingRegradeCount++;
                }
            }

            $regradeSummary = [
                'totalRegrades'    => $regrades->count(),
                'awaitingRegrade'  => $awaitingRegradeCount,
                'completedRegrade' => $completedRegradeCount,
                'scoreChanged'     => $scoreChangedCount,
            ];

            $gradingByActivity = [];
            foreach ($selectedActivities->take(10) as $act) {
                $actSubs = array_filter($latestList, fn ($s) => (string) $s->context === (string) $act->id);
                $graded = 0;
                $needsReview = 0;
                $awaitingRegrade = 0;
                $regraded = 0;
                foreach ($actSubs as $s) {
                    $reg = $regrades->get($s->id);
                    if ($reg && $reg->completed_at) {
                        $regraded++;
                    } elseif ($reg) {
                        $awaitingRegrade++;
                    } elseif ($s->evaluated_score !== null) {
                        $graded++;
                    } else {
                        $needsReview++;
                    }
                }
                if (count($actSubs) > 0) {
                    $gradingByActivity[] = [
                        'id'              => (string) $act->id,
                        'title'           => (string) $act->title,
                        'graded'          => $graded,
                        'needsReview'     => $needsReview,
                        'awaitingRegrade' => $awaitingRegrade,
                        'regraded'        => $regraded,
                        'total'           => count($actSubs),
                    ];
                }
            }

            $violationEvents = 0;
            $flaggedStudents = 0;
            foreach ($attempts as $att) {
                if (is_array($att->violations) && count($att->violations) > 0) {
                    $violationEvents += count($att->violations);
                    $flaggedStudents++;
                }
            }

            $officeOverview = [
                'totalExaminations' => $examinationsList->count(),
                'totalActivities'   => $selectedActivities->count(),
                'violationEvents'   => $violationEvents,
                'flaggedStudents'   => $flaggedStudents,
                'courseAverages'    => $courseAverages,
                'regradeSummary'    => $regradeSummary,
                'gradingByActivity' => $gradingByActivity,
            ];
        }

        return [
            'roleScope'      => $isOffice ? 'office' : 'teacher',
            'terms'          => $terms,
            'courses'        => $courses,
            'examinations'   => $examinationsList->map(fn ($e) => [
                'id'       => (string) $e->id,
                'title'    => (string) $e->title,
                'examDate' => $e->exam_date ? Carbon::parse($e->exam_date)->format('Y-m-d') : null,
            ])->all(),
            'classes'        => $filteredClasses->map(fn (ClassModel $c) => [
                'id'       => (string) $c->id,
                'course'   => (string) $c->course,
                'term'     => (string) $c->term,
                'students' => count($classStudentMap[$c->id] ?? []),
            ])->all(),
            'activities'     => $candidateActivities->map(fn (Assignment $a) => [
                'id'            => (string) $a->id,
                'title'         => (string) $a->title,
                'examinationId' => $a->examination_id ? (string) $a->examination_id : null,
                'isExam'        => (bool) ($a->examination_id || $a->exam_mode),
            ])->all(),
            'kpis'           => [
                'students'       => $totalStudentsInScope,
                'participants'   => $participantsCount,
                'averageScore'   => $overallAvg,
                'below'          => $belowCount,
                'passRate'       => $passRate,
                'pendingGrading' => $pendingGrading,
                'submissions'    => $subs->count(),
            ],
            'progress'       => $progress,
            'distribution'   => array_map(fn ($b) => ['label' => $b['label'], 'count' => $b['count']], $bins),
            'verdictBreakdown' => [
                ['label' => 'Accepted', 'count' => $verdictCounts['Accepted']],
                ['label' => 'Partial', 'count' => $verdictCounts['Partial']],
                ['label' => 'Wrong Answer', 'count' => $verdictCounts['Wrong Answer']],
                ['label' => 'Needs review', 'count' => $verdictCounts['Needs review']],
            ],
            'trend'          => $trend,
            'classAverages'  => $classAverages,
            'weakCategories' => $weakCategories,
            'officeOverview' => $officeOverview,
        ];
    }
}
