<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignmentService
{
    /**
     * Get all assignments with role-based filtering and frontend formatting
     */
    public function getAllAssignments(User $user): array
    {
        $isAdmin = $user->isAdmin() || $user->role === User::ROLE_DEPARTMENT_HEAD;
        $isInstructor = $user->isInstructor() || $user->canDo('ASSIGNMENT_MGMT', 'view');

        $query = Assignment::with(['classes', 'assignmentProblems.problem']);

        if (!$isAdmin) {
            if ($isInstructor) {
                $query->where('instructor_id', $user->id);
            } else {
                // Student
                $classIds = ClassEnrollment::where('student_id', $user->id)->pluck('class_id');
                $query->where('published', true)
                      ->where(function ($q) use ($classIds) {
                          $q->whereHas('classes', function ($cq) use ($classIds) {
                              $cq->whereIn('classes.id', $classIds);
                          })->orWhere(function ($oq) {
                              $oq->where('is_contest', true)->where('audience_type', 'all_students');
                          });
                      });
            }
        }

        $assignments = $query->orderBy('created_at', 'desc')->get();

        return $assignments->map(fn($a) => $this->formatAssignmentResponse($a, $user))->values()->all();
    }

    /**
     * Get single assignment by ID with authorization and frontend formatting
     */
    public function getAssignmentById(string $id, User $user): array
    {
        $assignment = Assignment::with(['classes', 'assignmentProblems.problem'])->findOrFail($id);

        $isAdmin = $user->isAdmin();
        $isDeptHead = $user->role === User::ROLE_DEPARTMENT_HEAD;
        $isInstructor = $user->isInstructor() || $user->canDo('ASSIGNMENT_MGMT', 'view');

        if (!$isAdmin && !$isDeptHead) {
            if ($isInstructor) {
                if ($assignment->instructor_id !== $user->id) {
                    abort(403, 'Bạn không có quyền truy cập bài tập của giảng viên khác.');
                }
            } else {
                // Student access check
                if (!$assignment->published) {
                    abort(403, 'Bài tập này chưa được công bố.');
                }
                if ($assignment->audience_type !== 'all_students') {
                    $enrolledClassIds = $user->enrolledClasses()->pluck('classes.id')->all();
                    $assignmentClassIds = $assignment->classes->pluck('id')->all();
                    if (empty(array_intersect($enrolledClassIds, $assignmentClassIds))) {
                        abort(403, 'Bạn không thuộc lớp học được giao bài tập này.');
                    }
                }
            }
        }

        return $this->formatAssignmentResponse($assignment, $user);
    }

    /**
     * Calculate eligible students count for an audience
     */
    public function getAudienceCount(string $audienceType, array $classIds, ?User $user = null): int
    {
        if ($audienceType === 'all_students') {
            return User::where('role', User::ROLE_STUDENT)->where('status', 'Active')->count();
        }

        if (empty($classIds)) {
            return 0;
        }

        return (int) DB::table('class_enrollments')
            ->join('users', 'class_enrollments.student_id', '=', 'users.id')
            ->whereIn('class_enrollments.class_id', $classIds)
            ->where('users.role', User::ROLE_STUDENT)
            ->where('users.status', 'Active')
            ->distinct('class_enrollments.student_id')
            ->count('class_enrollments.student_id');
    }

    /**
     * Create a new assignment / contest
     */
    public function createAssignment(array $data, string $instructorId): array
    {
        return DB::transaction(function () use ($data, $instructorId) {
            $assignmentId = (string) Str::uuid();

            $assignment = new Assignment();
            $assignment->id = $assignmentId;
            $assignment->title = $data['title'];
            $assignment->is_contest = (bool) ($data['is_contest'] ?? false);
            $assignment->audience_type = $data['audience_type'] ?? 'classes';
            $assignment->short_description = $data['short_description'] ?? null;
            $assignment->description = $data['description'] ?? null;
            $assignment->rules = $data['rules'] ?? null;
            $assignment->banner_url = $data['banner_url'] ?? null;
            $assignment->banner_source_url = $data['banner_source_url'] ?? null;
            $assignment->banner_crop = $data['banner_crop'] ?? null;
            $assignment->instructions = $data['instructions'] ?? null;
            $assignment->opens = !empty($data['opens']) ? $data['opens'] : null;
            $assignment->closes = !empty($data['closes']) ? $data['closes'] : null;
            $assignment->published = (bool) ($data['published'] ?? false);
            $assignment->instructor_id = $instructorId;

            // Student options
            $assignment->hints_enabled = (bool) ($data['hints_enabled'] ?? true);
            $assignment->comments_enabled = (bool) ($data['comments_enabled'] ?? true);
            $assignment->leaderboard_enabled = (bool) ($data['leaderboard_enabled'] ?? false);
            $assignment->ai_allowed = (bool) ($data['ai_allowed'] ?? true);
            $assignment->exam_mode = (bool) ($data['exam_mode'] ?? false);
            $assignment->show_scores_after_submit = (bool) ($data['show_scores_after_submit'] ?? true);
            $assignment->max_submissions = isset($data['max_submissions']) ? (int) $data['max_submissions'] : null;

            $assignment->save();

            // Sync classes with instructor authorization check
            $classIds = $data['class_ids'] ?? [];
            if (!empty($classIds)) {
                $user = User::find($instructorId);
                if ($user && !$user->isAdmin() && $user->role !== User::ROLE_DEPARTMENT_HEAD) {
                    $taughtClassIds = ClassModel::where(function ($query) use ($user): void {
                        $query->where('instructor_id', $user->id)
                            ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id));
                    })->pluck('id')->all();

                    $invalidClasses = array_diff($classIds, $taughtClassIds);
                    if (!empty($invalidClasses)) {
                        abort(403, 'Bạn chỉ có thể gán bài tập cho các lớp học do bạn phụ trách.');
                    }
                }
                $this->syncClasses($assignment, $classIds);
            }

            // Sync problems
            $this->syncAssignmentProblems($assignment, $data);

            // Notify students if published
            if ($assignment->published) {
                $this->notifyStudents($assignment);
            }

            $user = User::find($instructorId);
            return $this->formatAssignmentResponse($assignment->load(['classes', 'assignmentProblems.problem']), $user);
        });
    }

    /**
     * Update an existing assignment
     */
    public function updateAssignment(string $id, array $data, User $user): array
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $assignment = Assignment::findOrFail($id);

            if (!$user->isAdmin() && $user->role !== User::ROLE_DEPARTMENT_HEAD) {
                if ($assignment->instructor_id !== $user->id) {
                    abort(403, 'Bạn không có quyền chỉnh sửa bài tập của giảng viên khác.');
                }

                if (isset($data['class_ids'])) {
                    $taughtClassIds = ClassModel::where(function ($query) use ($user): void {
                        $query->where('instructor_id', $user->id)
                            ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id));
                    })->pluck('id')->all();

                    $invalidClasses = array_diff((array) $data['class_ids'], $taughtClassIds);
                    if (!empty($invalidClasses)) {
                        abort(403, 'Bạn chỉ có thể gán bài tập cho các lớp học do bạn phụ trách.');
                    }
                }
            }

            $assignment->title = $data['title'] ?? $assignment->title;
            if (array_key_exists('is_contest', $data)) $assignment->is_contest = (bool) $data['is_contest'];
            if (array_key_exists('audience_type', $data)) $assignment->audience_type = $data['audience_type'];
            if (array_key_exists('short_description', $data)) $assignment->short_description = $data['short_description'];
            if (array_key_exists('description', $data)) $assignment->description = $data['description'];
            if (array_key_exists('rules', $data)) $assignment->rules = $data['rules'];
            if (array_key_exists('banner_url', $data)) $assignment->banner_url = $data['banner_url'];
            if (array_key_exists('banner_source_url', $data)) $assignment->banner_source_url = $data['banner_source_url'];
            if (array_key_exists('banner_crop', $data)) $assignment->banner_crop = $data['banner_crop'];
            if (array_key_exists('instructions', $data)) $assignment->instructions = $data['instructions'];
            if (array_key_exists('opens', $data)) $assignment->opens = !empty($data['opens']) ? $data['opens'] : null;
            if (array_key_exists('closes', $data)) $assignment->closes = !empty($data['closes']) ? $data['closes'] : null;

            $wasPublished = $assignment->published;
            if (array_key_exists('published', $data)) $assignment->published = (bool) $data['published'];

            if (array_key_exists('hints_enabled', $data)) $assignment->hints_enabled = (bool) $data['hints_enabled'];
            if (array_key_exists('comments_enabled', $data)) $assignment->comments_enabled = (bool) $data['comments_enabled'];
            if (array_key_exists('leaderboard_enabled', $data)) $assignment->leaderboard_enabled = (bool) $data['leaderboard_enabled'];
            if (array_key_exists('ai_allowed', $data)) $assignment->ai_allowed = (bool) $data['ai_allowed'];
            if (array_key_exists('exam_mode', $data)) $assignment->exam_mode = (bool) $data['exam_mode'];
            if (array_key_exists('show_scores_after_submit', $data)) $assignment->show_scores_after_submit = (bool) $data['show_scores_after_submit'];
            if (array_key_exists('max_submissions', $data)) $assignment->max_submissions = $data['max_submissions'] ? (int) $data['max_submissions'] : null;

            $assignment->save();

            if (isset($data['class_ids'])) {
                $this->syncClasses($assignment, (array) $data['class_ids']);
            }

            if (isset($data['problems']) || isset($data['problem_ids'])) {
                $this->syncAssignmentProblems($assignment, $data);
            }

            if (!$wasPublished && $assignment->published) {
                $this->notifyStudents($assignment);
            }

            return $this->formatAssignmentResponse($assignment->load(['classes', 'assignmentProblems.problem']), $user);
        });
    }

    /**
     * Delete an assignment
     */
    public function deleteAssignment(string $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $assignment = Assignment::findOrFail($id);

            if (!$user->isAdmin() && $user->role !== User::ROLE_DEPARTMENT_HEAD && $assignment->instructor_id !== $user->id) {
                abort(403, 'Bạn không có quyền xóa bài tập của giảng viên khác.');
            }

            $assignment->classes()->detach();
            $assignment->assignmentProblems()->delete();
            $assignment->delete();
        });
    }

    /**
     * Sync classes with generated UUIDs for the pivot table
     */
    protected function syncClasses(Assignment $assignment, array $classIds): void
    {
        $syncData = [];
        foreach ($classIds as $classId) {
            $syncData[$classId] = ['id' => (string) Str::uuid()];
        }
        $assignment->classes()->sync($syncData);
    }

    /**
     * Sync problems with points, order, and workspace parts
     */
    protected function syncAssignmentProblems(Assignment $assignment, array $data): void
    {
        $assignment->assignmentProblems()->delete();

        if (!empty($data['problems']) && is_array($data['problems'])) {
            foreach ($data['problems'] as $index => $item) {
                $problemId = is_array($item) ? ($item['id'] ?? null) : $item;
                if (!$problemId) continue;

                $points = is_array($item) ? (int) ($item['points'] ?? 10) : 10;
                $workspaceParts = is_array($item) ? ($item['workspaceParts'] ?? $item['workspace_parts'] ?? ['DDL', 'DML', 'SQL']) : ['DDL', 'DML', 'SQL'];
                $datasetVisibility = is_array($item) ? ($item['datasetVisibility'] ?? $item['dataset_visibility'] ?? []) : [];
                $dbStructVisible = is_array($item) ? (bool) ($item['databaseStructureVisible'] ?? $item['database_structure_visible'] ?? true) : true;
                $dbDescVisible = is_array($item) ? (bool) ($item['databaseDescriptionVisible'] ?? $item['database_description_visible'] ?? true) : true;

                AssignmentProblem::create([
                    'id'                           => (string) Str::uuid(),
                    'assignment_id'                => $assignment->id,
                    'problem_id'                   => $problemId,
                    'points'                       => $points,
                    'order_index'                  => $index,
                    'workspace_parts'              => $workspaceParts,
                    'dataset_visibility'           => $datasetVisibility,
                    'database_structure_visible'   => $dbStructVisible,
                    'database_description_visible' => $dbDescVisible,
                ]);
            }
        } elseif (!empty($data['problem_ids']) && is_array($data['problem_ids'])) {
            foreach ($data['problem_ids'] as $index => $problemId) {
                AssignmentProblem::create([
                    'id'                           => (string) Str::uuid(),
                    'assignment_id'                => $assignment->id,
                    'problem_id'                   => $problemId,
                    'points'                       => 10,
                    'order_index'                  => $index,
                    'workspace_parts'              => ['DDL', 'DML', 'SQL'],
                    'dataset_visibility'           => [],
                    'database_structure_visible'   => true,
                    'database_description_visible' => true,
                ]);
            }
        }
    }

    /**
     * Send notifications to eligible students when assignment is published
     */
    protected function notifyStudents(Assignment $assignment): void
    {
        $classIds = $assignment->classes()->pluck('classes.id')->all();
        $studentIds = DB::table('class_enrollments')
            ->join('users', 'class_enrollments.student_id', '=', 'users.id')
            ->whereIn('class_enrollments.class_id', $classIds)
            ->where('users.role', User::ROLE_STUDENT)
            ->where('users.status', 'Active')
            ->pluck('users.id')
            ->unique();

        $title = ($assignment->is_contest ? 'Kỳ thi mới: ' : 'Bài tập mới: ') . $assignment->title;
        $link = $assignment->is_contest ? '/contests' : '/assignments';

        $notifications = [];
        foreach ($studentIds as $sId) {
            $notifications[] = [
                'id'         => (string) Str::uuid(),
                'user_id'    => $sId,
                'title'      => $title,
                'message'    => 'Giảng viên đã giao một bài mới. Hãy kiểm tra ngay!',
                'type'       => 'Assignment',
                'link'       => $link,
                'is_read'    => false,
                'created_at' => now(),
            ];
        }

        if (!empty($notifications)) {
            Notification::insert($notifications);
        }
    }

    /**
     * Format an Assignment model into the JSON payload expected by frontend React
     */
    public function formatAssignmentResponse(Assignment $a, ?User $currentUser = null): array
    {
        $classIds = $a->classes->pluck('id')->all();
        $problemItems = $a->assignmentProblems ?? $a->assignmentProblems()->get();

        $problemsList = $problemItems->map(function ($ap) {
            return [
                'id'                         => (string) $ap->problem_id,
                'points'                     => (int) $ap->points,
                'datasetVisibility'          => $ap->dataset_visibility ?? (object) [],
                'databaseStructureVisible'   => (bool) ($ap->database_structure_visible ?? true),
                'databaseDescriptionVisible' => (bool) ($ap->database_description_visible ?? true),
                'workspaceParts'             => $ap->workspace_parts ?? ['DDL', 'DML', 'SQL'],
            ];
        })->values()->all();

        // Calculate status
        $now = now();
        if (!$a->published) {
            $status = 'Draft';
        } elseif ($a->opens && $a->opens > $now) {
            $status = 'Scheduled';
        } elseif ($a->closes && $a->closes < $now) {
            $status = 'Closed';
        } else {
            $status = 'Open';
        }

        $due = $a->closes ? $a->closes->format('M d') : '';
        $audienceType = $a->audience_type ?: 'classes';

        $classNames = $a->classes->map(fn($c) => $c->course ?? $c->name ?? $c->id)->filter()->all();
        if ($a->is_contest && $audienceType === 'all_students') {
            $classStr = 'All students';
        } elseif (!empty($classNames)) {
            $classStr = implode(', ', $classNames);
        } else {
            $classStr = 'No classes';
        }

        $eligibleStudents = $this->getAudienceCount($audienceType, $classIds);

        // Submissions calculation
        $problemIds = array_column($problemsList, 'id');
        $submittedStudents = 0;
        $avgScore = 0;
        $awaiting = 0;
        $reviewId = null;

        if ($eligibleStudents > 0 && !empty($problemIds)) {
            $submittedStudents = (int) Submission::where('context', $a->id)
                ->whereIn('source', ['Assignments', 'Contests'])
                ->distinct('user_id')
                ->count('user_id');

            if ($submittedStudents > 0) {
                $avgScore = (int) round((float) Submission::where('context', $a->id)
                    ->whereIn('source', ['Assignments', 'Contests'])
                    ->avg(DB::raw('COALESCE(evaluated_score, score)')));

                $awaitingSub = Submission::where('context', $a->id)
                    ->whereIn('source', ['Assignments', 'Contests'])
                    ->whereNull('evaluated_score')
                    ->first();

                if ($awaitingSub) {
                    $reviewId = $awaitingSub->id;
                    $awaiting = Submission::where('context', $a->id)
                        ->whereIn('source', ['Assignments', 'Contests'])
                        ->whereNull('evaluated_score')
                        ->count();
                }
            }
        }

        $submittedStr = "{$submittedStudents}/{$eligibleStudents}";

        return [
            'id'               => (string) $a->id,
            'title'            => (string) $a->title,
            'isContest'        => (bool) $a->is_contest,
            'is_contest'       => (bool) $a->is_contest,
            'audienceType'     => $audienceType,
            'audience_type'    => $audienceType,
            'shortDescription' => (string) ($a->short_description ?? ''),
            'description'      => (string) ($a->description ?? ''),
            'rules'            => (string) ($a->rules ?? ''),
            'bannerUrl'        => $a->is_contest ? $a->banner_url : null,
            'bannerSourceUrl'  => $a->is_contest ? $a->banner_source_url : null,
            'bannerCrop'       => $a->is_contest ? $a->banner_crop : null,
            'eligibleStudents' => $eligibleStudents,
            'classes'          => $classStr,
            'problems'         => count($problemsList),
            'due'              => $due,
            'submitted'        => $submittedStr,
            'average'          => "{$avgScore}%",
            'awaiting'         => $awaiting,
            'reviewId'         => $reviewId,
            'status'           => $status,
            'instructions'     => (string) ($a->instructions ?? ''),
            'opens'            => $a->opens ? $a->opens->toIso8601String() : null,
            'closes'           => $a->closes ? $a->closes->toIso8601String() : null,
            'published'        => (bool) $a->published,
            'studentOptions'   => [
                'hints'                 => (bool) $a->hints_enabled,
                'comments'              => (bool) $a->comments_enabled,
                'leaderboard'           => (bool) $a->leaderboard_enabled,
                'aiAllowed'             => (bool) $a->ai_allowed,
                'examMode'              => (bool) $a->exam_mode,
                'showScoresAfterSubmit' => (bool) $a->show_scores_after_submit,
                'maxSubmissions'        => $a->max_submissions,
            ],
            'problemList'      => $problemsList,
            'classIds'         => $classIds,
        ];
    }

    /**
     * Get student assignments, deadlines, enrolled classes and problems for student screens
     */
    public function getStudentAssignments(User $user): array
    {
        // 1. Classes student is enrolled in
        $enrolledClasses = $user->enrolledClasses()
            ->with(['instructor', 'instructors', 'courseRecord', 'academicTerm'])
            ->get();

        $classes = $enrolledClasses->map(function ($c) {
            $code = $c->courseRecord?->code ?? $c->course ?? $c->id;
            $term = $c->academicTerm?->name ?? $c->term ?? '';
            $fullCode = $term ? "{$code} · {$term}" : $code;
            $name = $c->courseRecord?->name ?? $c->course ?? 'Class';
            $lecturer = $c->instructor?->name ?? 'Instructor';

            return [
                'id'       => (string) $c->id,
                'code'     => (string) $fullCode,
                'name'     => (string) $name,
                'lecturer' => (string) $lecturer,
            ];
        })->values()->all();

        $enrolledClassIds = $enrolledClasses->pluck('id')->all();

        // 2. Published assignments & contests
        $assignmentsQuery = Assignment::with(['classes', 'assignmentProblems.problem'])
            ->where('published', true)
            ->where(function ($q) use ($enrolledClassIds) {
                if (!empty($enrolledClassIds)) {
                    $q->whereHas('classes', function ($cq) use ($enrolledClassIds) {
                        $cq->whereIn('classes.id', $enrolledClassIds);
                    })->orWhere(function ($oq) {
                        $oq->where('is_contest', true)->where('audience_type', 'all_students');
                    });
                } else {
                    $q->where('is_contest', true)->where('audience_type', 'all_students');
                }
            })
            ->orderBy('closes', 'asc');

        $assignmentModels = $assignmentsQuery->get();

        // Submissions by user for checking progress
        $userSubs = Submission::where('user_id', $user->id)
            ->whereIn('source', ['Assignments', 'Contests'])
            ->select('problem_id', 'context', 'result')
            ->get();

        $deadlines = [];
        $problemsMap = [];
        $assignmentsList = [];

        foreach ($assignmentModels as $a) {
            $classIds = $a->classes->pluck('id')->all();
            $problems = $a->assignmentProblems ?? collect();
            $probIds = [];
            $problemProgress = [];
            $allSolved = $problems->isNotEmpty();
            $anyAttempt = false;

            foreach ($problems as $ap) {
                $pid = (string) $ap->problem_id;
                $probIds[] = $pid;

                $pSubs = $userSubs->where('problem_id', $pid)->where('context', (string) $a->id);
                if ($pSubs->contains('result', 'Accepted')) {
                    $problemProgress[$pid] = 'Solved';
                    $anyAttempt = true;
                } elseif ($pSubs->isNotEmpty()) {
                    $problemProgress[$pid] = 'In progress';
                    $anyAttempt = true;
                    $allSolved = false;
                } else {
                    $problemProgress[$pid] = 'Not started';
                    $allSolved = false;
                }

                if ($ap->problem && !isset($problemsMap[$pid])) {
                    $p = $ap->problem;
                    $problemsMap[$pid] = [
                        'id'         => (string) $p->id,
                        'title'      => (string) $p->title,
                        'topic'      => (string) ($p->topic ?: (is_array($p->topics) && count($p->topics) ? $p->topics[0] : 'SQL')),
                        'difficulty' => (string) $p->difficulty,
                    ];
                }
            }

            $overallStatus = 'Not started';
            if ($allSolved && $problems->isNotEmpty()) {
                $overallStatus = 'Solved';
            } elseif ($anyAttempt) {
                $overallStatus = 'In progress';
            }

            $closesDate = $a->closes ? $a->closes->format('Y-m-d') : '';
            $closesTime = $a->closes ? $a->closes->format('H:i') : '';

            $assignmentItem = [
                'id'              => (string) $a->id,
                'title'           => (string) $a->title,
                'instructions'    => (string) ($a->instructions ?? ''),
                'classIds'        => $classIds,
                'date'            => $closesDate,
                'time'            => $closesTime,
                'status'          => $overallStatus,
                'problemIds'      => $probIds,
                'problemProgress' => $problemProgress,
                'isContest'       => (bool) $a->is_contest,
                'is_contest'      => (bool) $a->is_contest,
            ];

            $assignmentsList[] = $assignmentItem;

            if ($a->closes) {
                $classNames = $a->classes->map(fn($c) => $c->course ?? $c->name ?? $c->id)->filter()->all();
                $contextStr = $a->is_contest && $a->audience_type === 'all_students'
                    ? 'All students'
                    : (!empty($classNames) ? implode(', ', $classNames) : 'Assignment');

                $deadlines[] = [
                    'id'      => (string) $a->id,
                    'title'   => (string) $a->title,
                    'date'    => $closesDate,
                    'time'    => $closesTime,
                    'kind'    => $a->is_contest ? 'Contest' : 'Assignment',
                    'context' => $contextStr,
                    'to'      => $a->is_contest ? '/contests/' . $a->id : '/assignments/' . $a->id,
                ];
            }
        }

        return [
            'classes'     => $classes,
            'assignments' => $assignmentsList,
            'deadlines'   => $deadlines,
            'problems'    => array_values($problemsMap),
        ];
    }
}
