<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\AssignmentStudent;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Notification;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignmentService
{
    /**
     * Get all assignments with role-based filtering, optional results scope, and frontend formatting
     */
    public function getAllAssignments(User $user, ?string $scope = null): array
    {
        $isAdmin = $user->isAdmin()
            || $user->isFacultyOffice()
            || $user->role === User::ROLE_ADMIN
            || $user->role === 'admin'
            || $user->role === User::ROLE_DEPARTMENT_HEAD;
        $isInstructor = $user->isInstructor() || $user->canDo('ASSIGNMENT_MGMT', 'view');

        $query = Assignment::with(['classes', 'selectedStudents', 'assignmentProblems.problem']);

        if (!$isAdmin) {
            if ($isInstructor) {
                if ($scope === 'results') {
                    // Include assignments where instructor owns, teaches classes, or proctors an exam session
                    $proctorSessionIds = \App\Models\ExamClassProctor::where('proctor_id', $user->id)
                        ->pluck('session_id')
                        ->all();
                    $query->where(function ($q) use ($user, $proctorSessionIds) {
                        $q->where('instructor_id', $user->id)
                          ->orWhereHas('classes', function ($cq) use ($user) {
                              $cq->where('instructor_id', $user->id)
                                 ->orWhere('practice_instructor_id', $user->id)
                                 ->orWhereHas('instructors', fn ($iq) => $iq->where('users.id', $user->id));
                          });
                        if (!empty($proctorSessionIds)) {
                            $q->orWhereIn('id', $proctorSessionIds);
                        }
                    });
                } else {
                    $query->where('instructor_id', $user->id);
                }
            } else {
                // Student
                $classIds = ClassEnrollment::where('student_id', $user->id)->pluck('class_id')->all();
                $query->where('published', true)
                      ->where(function ($q) use ($user, $classIds) {
                          $q->whereHas('classes', function ($cq) use ($classIds) {
                              $cq->whereIn('classes.id', $classIds);
                          })->orWhereHas('selectedStudents', function ($sq) use ($user) {
                              $sq->where('users.id', $user->id);
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
        $assignment = Assignment::with(['classes', 'selectedStudents', 'assignmentProblems.problem'])->findOrFail($id);

        $isAdmin = $user->isAdmin() || $user->isFacultyOffice() || $user->role === User::ROLE_ADMIN || $user->role === 'admin';
        $isDeptHead = $user->role === User::ROLE_DEPARTMENT_HEAD;
        $isInstructor = $user->isInstructor() || $user->canDo('ASSIGNMENT_MGMT', 'view');

        $isExamActivity = (bool) ($assignment->examination_id || $assignment->is_contest || $assignment->exam_mode);

        if (!$isAdmin && !$isDeptHead) {
            if ($isInstructor) {
                if (!$isExamActivity && $assignment->instructor_id !== $user->id) {
                    // Check if instructor teaches one of the classes or is a proctor
                    $taughtClassIds = ClassModel::where('instructor_id', $user->id)
                        ->orWhere('practice_instructor_id', $user->id)
                        ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
                        ->pluck('id')->all();
                    $assignmentClassIds = $assignment->classes->pluck('id')->all();
                    $isProctor = \App\Models\ExamClassProctor::where('session_id', $assignment->id)
                        ->where('proctor_id', $user->id)
                        ->exists();

                    if (empty(array_intersect($taughtClassIds, $assignmentClassIds)) && !$isProctor) {
                        abort(403, 'Bạn không có quyền truy cập bài tập của giảng viên khác.');
                    }
                }
            } else {
                // Student access check
                if (!$assignment->published) {
                    abort(403, 'Bài tập này chưa được công bố.');
                }
                if ($assignment->audience_type !== 'all_students') {
                    $enrolledClassIds = $user->enrolledClasses()->pluck('classes.id')->all();
                    $assignmentClassIds = $assignment->classes->pluck('id')->all();
                    $isSpecificallySelected = $assignment->selectedStudents()->where('users.id', $user->id)->exists();
                    $hasAdmission = \App\Models\ExamAdmission::where('session_id', $assignment->id)
                        ->where('student_id', $user->id)
                        ->where('status', '!=', 'revoked')
                        ->exists();

                    if (empty(array_intersect($enrolledClassIds, $assignmentClassIds)) && !$isSpecificallySelected && !$hasAdmission) {
                        abort(403, 'Bạn không thuộc danh sách tham gia bài tập này.');
                    }
                }
            }
        }

        return $this->formatAssignmentResponse($assignment, $user);
    }

    protected function taughtClassIdsForUser(User $user): array
    {
        return ClassModel::where(function ($query) use ($user): void {
            $query->where('instructor_id', $user->id)
                ->orWhere('practice_instructor_id', $user->id)
                ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id));
        })->pluck('id')->all();
    }

    protected function canAccessAllClasses(User $user): bool
    {
        return $user->isAdmin() || $user->isFacultyOffice() || $user->role === User::ROLE_DEPARTMENT_HEAD;
    }

    /**
     * Search audience students by query and return whether they are already in the selected classes
     */
    public function searchAudienceStudents(string $query, array $classIds, User $user): array
    {
        $term = trim(strtolower($query));
        $classIds = array_values(array_filter($classIds));
        if (!$this->canAccessAllClasses($user)) {
            $classIds = array_values(array_intersect($classIds, $this->taughtClassIdsForUser($user)));
        }

        $studentsQuery = User::where('role', User::ROLE_STUDENT)->where('status', 'Active');

        if ($term !== '') {
            $studentsQuery->where(function ($q) use ($term) {
                $q->where(DB::raw('LOWER(name)'), 'like', "%{$term}%")
                  ->orWhere(DB::raw('LOWER(email)'), 'like', "%{$term}%")
                  ->orWhere(DB::raw('LOWER(id)'), 'like', "%{$term}%");
            });
        }

        $total = $studentsQuery->count();
        $rows = $studentsQuery->orderBy('name')->orderBy('email')->limit(25)->get();

        $enrolledMap = [];
        if (!empty($classIds) && $rows->isNotEmpty()) {
            $enrollments = DB::table('class_enrollments')
                ->join('classes', 'classes.id', '=', 'class_enrollments.class_id')
                ->whereIn('class_enrollments.class_id', $classIds)
                ->whereIn('class_enrollments.student_id', $rows->pluck('id'))
                ->select('class_enrollments.student_id', 'classes.id as class_id', 'classes.course')
                ->get();

            foreach ($enrollments as $e) {
                $enrolledMap[$e->student_id][] = $e->course ?: $e->class_id;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $studentNumber = explode('@', $row->email)[0];
            $items[] = [
                'id'             => (string) $row->id,
                'name'           => (string) $row->name,
                'email'          => (string) $row->email,
                'coveredClasses' => $enrolledMap[$row->id] ?? [],
                'studentNumber'  => $studentNumber,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Calculate eligible students count and breakdown for an audience
     */
    public function getAudienceCount(string $audienceType, array $classIds, array $studentEmails = [], ?User $user = null): array
    {
        if ($audienceType === 'all_students') {
            $total = User::where('role', User::ROLE_STUDENT)->where('status', 'Active')->count();
            return [
                'eligibleStudents'      => $total,
                'individualStudents'    => 0,
                'overlappingStudents'   => 0,
                'coveredIndividuals'    => [],
                'resolvedStudentEmails' => [],
            ];
        }

        $classIds = array_values(array_filter($classIds));
        if ($user && !$this->canAccessAllClasses($user)) {
            $classIds = array_values(array_intersect($classIds, $this->taughtClassIdsForUser($user)));
        }
        $enrolledStudentIds = [];

        if (!empty($classIds)) {
            $enrolledStudentIds = DB::table('class_enrollments')
                ->join('users', 'class_enrollments.student_id', '=', 'users.id')
                ->whereIn('class_enrollments.class_id', $classIds)
                ->where('users.role', User::ROLE_STUDENT)
                ->where('users.status', 'Active')
                ->pluck('users.id')
                ->unique()
                ->all();
        }

        $classStudentsSet = array_flip($enrolledStudentIds);
        $resolvedIndividualIds = $this->resolveStudentEmails($studentEmails);
        $individualUsers = User::whereIn('id', $resolvedIndividualIds)->get();

        $overlapping = [];
        $resolvedStudentEmails = [];
        $allEligibleSet = $classStudentsSet;

        foreach ($individualUsers as $u) {
            $allEligibleSet[$u->id] = true;
            if (isset($classStudentsSet[$u->id])) {
                $classNames = DB::table('class_enrollments')
                    ->join('classes', 'classes.id', '=', 'class_enrollments.class_id')
                    ->where('class_enrollments.student_id', $u->id)
                    ->whereIn('classes.id', $classIds)
                    ->pluck('classes.course')
                    ->all();

                $overlapping[] = [
                    'id'            => (string) $u->id,
                    'name'          => (string) $u->name,
                    'email'         => (string) $u->email,
                    'studentNumber' => explode('@', $u->email)[0],
                    'classes'       => $classNames,
                ];
            } else {
                $resolvedStudentEmails[] = $u->email;
            }
        }

        return [
            'eligibleStudents'      => count($allEligibleSet),
            'individualStudents'    => count($resolvedIndividualIds),
            'overlappingStudents'   => count($overlapping),
            'coveredIndividuals'    => $overlapping,
            'resolvedStudentEmails' => $resolvedStudentEmails,
        ];
    }

    /**
     * Resolve school emails or student numbers to unique active accounts
     */
    public function resolveStudentEmails(array $emails): array
    {
        $normalized = [];
        foreach ($emails as $item) {
            $parts = preg_split('/[,;\s]+/', (string) $item);
            foreach ($parts as $p) {
                $p = trim(strtolower($p));
                if ($p !== '') {
                    $normalized[$p] = true;
                }
            }
        }
        $normalized = array_keys($normalized);

        if (empty($normalized)) {
            return [];
        }

        $numbers = array_filter($normalized, fn($id) => ctype_digit($id) && strlen($id) >= 6 && strlen($id) <= 12);

        $matched = User::where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->where(function ($q) use ($normalized, $numbers) {
                $q->whereIn('email', $normalized)
                  ->orWhereIn('id', $normalized);
                foreach ($numbers as $num) {
                    $q->orWhere('email', 'like', $num . '@%');
                }
            })
            ->pluck('id')
            ->unique()
            ->all();

        return array_values($matched);
    }

    /**
     * Create a new assignment / contest
     */
    public function createAssignment(array $data, User|string $instructor): array
    {
        $instructorId = $instructor instanceof User ? $instructor->id : (string) $instructor;

        return DB::transaction(function () use ($data, $instructorId) {
            $assignmentId = (string) Str::uuid();

            $assignment = new Assignment();
            $assignment->id = $assignmentId;
            $assignment->title = $data['title'];
            $assignment->is_contest = (bool) ($data['is_contest'] ?? false);
            $assignment->scoring_mode = $data['scoring_mode'] ?? 'equal_questions';
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
            $assignment->exam_violation_limit = isset($data['exam_violation_limit']) ? (int) $data['exam_violation_limit'] : 2;
            $assignment->show_scores_after_submit = (bool) ($data['show_scores_after_submit'] ?? true);
            $assignment->max_submissions = isset($data['max_submissions']) ? (int) $data['max_submissions'] : null;

            $assignment->save();

            // Sync classes with instructor authorization check
            $classIds = $data['class_ids'] ?? [];
            if (!empty($classIds)) {
                $user = User::find($instructorId);
                if ($user && !$user->isAdmin() && !$user->isFacultyOffice() && $user->role !== User::ROLE_DEPARTMENT_HEAD) {
                    $taughtClassIds = ClassModel::where(function ($query) use ($user): void {
                        $query->where('instructor_id', $user->id)
                            ->orWhere('practice_instructor_id', $user->id)
                            ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id));
                    })->pluck('id')->all();

                    $invalidClasses = array_diff($classIds, $taughtClassIds);
                    if (!empty($invalidClasses)) {
                        abort(403, 'Bạn chỉ có thể gán bài tập cho các lớp học do bạn phụ trách.');
                    }
                }
                $this->syncClasses($assignment, $classIds);
            }

            // Sync individual students
            if (!empty($data['student_emails'])) {
                $this->syncSelectedStudents($assignment, $data['student_emails'], $classIds);
            }

            // Sync problems
            $this->syncAssignmentProblems($assignment, $data);

            // Notify students if published
            if ($assignment->published) {
                $this->notifyStudents($assignment);
            }

            $user = User::find($instructorId);
            return $this->formatAssignmentResponse($assignment->load(['classes', 'selectedStudents', 'assignmentProblems.problem']), $user);
        });
    }

    /**
     * Update an existing assignment
     */
    public function updateAssignment(string $id, array $data, User $user): array
    {
        $assignment = Assignment::findOrFail($id);
        if (!empty($assignment->examination_id)) {
            return app(ExaminationService::class)->saveSessionFromAssignmentUpdate($assignment, $data, $user);
        }

        return DB::transaction(function () use ($assignment, $data, $user) {
            if (!$user->isAdmin() && !$user->isFacultyOffice() && $user->role !== User::ROLE_DEPARTMENT_HEAD) {
                if ($assignment->instructor_id !== $user->id) {
                    abort(403, 'Bạn không có quyền chỉnh sửa bài tập của giảng viên khác.');
                }

                if (isset($data['class_ids'])) {
                    $taughtClassIds = ClassModel::where(function ($query) use ($user): void {
                        $query->where('instructor_id', $user->id)
                            ->orWhere('practice_instructor_id', $user->id)
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
            if (array_key_exists('scoring_mode', $data)) $assignment->scoring_mode = $data['scoring_mode'];
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

            $wasPublished = (bool) $assignment->published;
            $isPublishedAssignment = $wasPublished && !$assignment->is_contest && empty($assignment->examination_id);
            if ($wasPublished && array_key_exists('published', $data) && !$data['published']) {
                abort(422, 'Không thể hủy công bố bài tập đã được phát hành.');
            }
            if (array_key_exists('published', $data)) $assignment->published = (bool) $data['published'];

            if (array_key_exists('hints_enabled', $data)) $assignment->hints_enabled = (bool) $data['hints_enabled'];
            if (array_key_exists('comments_enabled', $data)) $assignment->comments_enabled = (bool) $data['comments_enabled'];
            if (array_key_exists('leaderboard_enabled', $data)) $assignment->leaderboard_enabled = (bool) $data['leaderboard_enabled'];
            if (array_key_exists('ai_allowed', $data)) $assignment->ai_allowed = (bool) $data['ai_allowed'];
            if (array_key_exists('exam_mode', $data)) $assignment->exam_mode = (bool) $data['exam_mode'];
            if (array_key_exists('exam_violation_limit', $data)) $assignment->exam_violation_limit = (int) $data['exam_violation_limit'];
            if (array_key_exists('show_scores_after_submit', $data)) $assignment->show_scores_after_submit = (bool) $data['show_scores_after_submit'];
            if (array_key_exists('max_submissions', $data)) $assignment->max_submissions = $data['max_submissions'] ? (int) $data['max_submissions'] : null;

            $assignment->save();

            $prevStudentIds = $wasPublished ? $this->resolveEligibleStudentIds($assignment) : [];

            $classIds = isset($data['class_ids']) ? (array) $data['class_ids'] : $assignment->classes()->pluck('classes.id')->all();
            if (isset($data['class_ids'])) {
                $this->syncClasses($assignment, $classIds);
            }

            if (isset($data['student_emails'])) {
                $this->syncSelectedStudents($assignment, (array) $data['student_emails'], $classIds);
            }

            if (isset($data['problems']) || isset($data['problem_ids'])) {
                if ($isPublishedAssignment) {
                    $this->syncPublishedAssignmentProblems($assignment, $data);
                } else {
                    $this->syncAssignmentProblems($assignment, $data);
                }
            }

            if (!$wasPublished && $assignment->published) {
                $this->notifyStudents($assignment);
            } elseif ($wasPublished && $assignment->published) {
                $currentStudentIds = $this->resolveEligibleStudentIds($assignment);
                $newlyAddedIds = array_values(array_diff($currentStudentIds, $prevStudentIds));
                if (!empty($newlyAddedIds)) {
                    $this->notifyStudents($assignment, $newlyAddedIds);
                }
            }

            return $this->formatAssignmentResponse($assignment->load(['classes', 'selectedStudents', 'assignmentProblems.problem']), $user);
        });
    }

    /**
     * Delete an assignment
     */
    public function deleteAssignment(string $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $assignment = Assignment::findOrFail($id);

            if (!empty($assignment->examination_id)) {
                abort(422, 'Không thể xóa trực tiếp ca thi thuộc kỳ thi.');
            }

            if (!$user->isAdmin() && !$user->isFacultyOffice() && $user->role !== User::ROLE_DEPARTMENT_HEAD && $assignment->instructor_id !== $user->id) {
                abort(403, 'Bạn không có quyền xóa bài tập của giảng viên khác.');
            }

            $assignment->classes()->detach();
            $assignment->selectedStudents()->detach();
            $assignment->assignmentProblems()->delete();
            $assignment->delete();
        });
    }

    /**
     * Get submission summaries for teacher oversight and grading
     */
    public function getAssignmentSubmissions(string $assignmentId, User $user): array
    {
        $assignment = Assignment::with(['classes', 'selectedStudents', 'assignmentProblems.problem'])->findOrFail($assignmentId);

        $isPrivileged = $user->isAdmin()
            || $user->isFacultyOffice()
            || $user->role === User::ROLE_ADMIN
            || $user->role === 'admin'
            || $user->role === User::ROLE_DEPARTMENT_HEAD;

        $isExamActivity = (bool) ($assignment->examination_id || $assignment->is_contest || $assignment->exam_mode);
        $allowedPracticalStudentIds = null;

        if (!$isPrivileged) {
            if ($isExamActivity) {
                $practicalClassIds = ClassModel::where(function ($q) use ($user) {
                    $q->where('practice_instructor_id', $user->id)
                        ->orWhereIn('id', DB::table('class_instructors')
                            ->where('instructor_id', $user->id)
                            ->where('assignment_type', 'practical')
                            ->select('class_id'));
                })->pluck('id')->all();

                $assignmentClassIds = $assignment->classes->pluck('id')->all();
                $scopedPracticalClassIds = !empty($assignmentClassIds)
                    ? array_values(array_intersect($practicalClassIds, $assignmentClassIds))
                    : $practicalClassIds;

                if (empty($scopedPracticalClassIds)) {
                    return [];
                }

                $allowedPracticalStudentIds = ClassEnrollment::whereIn('class_id', $scopedPracticalClassIds)
                    ->pluck('student_id')
                    ->unique()
                    ->values()
                    ->all();

                if (empty($allowedPracticalStudentIds)) {
                    return [];
                }
            } elseif ($assignment->instructor_id !== $user->id) {
                $taughtClassIds = ClassModel::where('instructor_id', $user->id)
                    ->orWhere('practice_instructor_id', $user->id)
                    ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
                    ->pluck('id')->all();
                $assignmentClassIds = $assignment->classes->pluck('id')->all();
                $isProctor = \App\Models\ExamClassProctor::where('session_id', $assignment->id)
                    ->where('proctor_id', $user->id)
                    ->exists();

                if (empty(array_intersect($taughtClassIds, $assignmentClassIds)) && !$isProctor) {
                    abort(403, 'Bạn không có quyền xem bài nộp của bài tập này.');
                }
            }
        }

        $problemItems = $assignment->assignmentProblems;
        $problemIds = $problemItems->pluck('problem_id')->all();
        if (empty($problemIds)) {
            return [];
        }

        $examService = app(ExaminationService::class);
        $problemsPayload = $examService->sessionProblemsPayload($assignment);

        $classIds = $assignment->classes->pluck('id')->all();
        $classesMap = $assignment->classes->keyBy('id');
        $studentClassMap = [];
        if (!empty($classIds)) {
            $enrollments = ClassEnrollment::whereIn('class_id', $classIds)->get();
            foreach ($enrollments as $en) {
                if (!isset($studentClassMap[$en->student_id])) {
                    $studentClassMap[$en->student_id] = $en->class_id;
                }
            }
        }

        $resolveStudentMeta = function (string $studentId, ?string $email) use ($examService, $assignment, $problemsPayload, $studentClassMap, $classesMap) {
            $code = $examService->studentCode($email, $studentId);
            $cid = $studentClassMap[$studentId] ?? null;
            $cObj = $cid ? $classesMap->get($cid) : null;
            $cName = $cObj ? ($cObj->practice_class ?: $cObj->course ?: $cObj->id) : 'Thí sinh tự do';
            $vCode = $examService->resolveStudentVariantCode($studentId, $assignment->id, $problemsPayload);
            return [$code, $cName, $vCode];
        };

        $submissionsQuery = Submission::where('context', $assignment->id)
            ->whereIn('problem_id', $problemIds)
            ->whereIn('source', ['Assignment', 'Contest', 'Assignments', 'Contests'])
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            });

        if ($allowedPracticalStudentIds !== null) {
            $submissionsQuery->whereIn('user_id', $allowedPracticalStudentIds);
        }

        $submissions = $submissionsQuery
            ->orderBy('submitted_at', 'desc')
            ->get();

        $problemsMap = $problemItems->keyBy('problem_id');
        $usersMap = User::whereIn('id', $submissions->pluck('user_id')->unique())->get()->keyBy('id');
        $appealsMap = $submissions->isNotEmpty()
            ? \App\Models\SubmissionAppeal::whereIn('submission_id', $submissions->pluck('id')->all())->get()->keyBy('submission_id')
            : collect();
        $regradesMap = $submissions->isNotEmpty()
            ? \App\Models\SubmissionRegradeAssignment::whereIn('submission_id', $submissions->pluck('id')->all())->get()->keyBy('submission_id')
            : collect();
        $regradeUsersMap = $regradesMap->isNotEmpty()
            ? User::whereIn('id', $regradesMap->pluck('assigned_instructor_id')->unique()->all())->get()->keyBy('id')
            : collect();
        $reviewService = app(SubmissionReviewService::class);

        $seen = [];
        $result = [];

        foreach ($submissions as $sub) {
            $pair = "{$sub->user_id}_{$sub->problem_id}";
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;

            $ap = $problemsMap->get($sub->problem_id);
            $u = $usersMap->get($sub->user_id);
            $prob = $ap?->problem;
            $appeal = $appealsMap->get($sub->id);
            $regrade = $regradesMap->get($sub->id);
            $regradeUser = $regrade ? $regradeUsersMap->get($regrade->assigned_instructor_id) : null;

            $parts = $ap?->workspace_parts ?? ($prob?->workspace_parts ?? ['SQL']);
            [$studentCode, $className, $variantCode] = $resolveStudentMeta((string) $sub->user_id, $u?->email);

            $result[] = [
                'id'             => $sub->id,
                'studentId'      => $sub->user_id,
                'studentCode'    => $studentCode,
                'student'        => $u?->name ?? 'Unknown',
                'className'      => $className,
                'variantCode'    => $variantCode,
                'problemId'      => $sub->problem_id,
                'problem'        => $prob?->title ?? 'Unknown',
                'score'          => $sub->evaluated_score !== null ? (float) $sub->evaluated_score : (float) $sub->score,
                'autoScore'      => (float) $sub->score,
                'evaluatedScore' => $sub->evaluated_score !== null ? (float) $sub->evaluated_score : null,
                'maxScore'       => $ap ? (float) $ap->points : 10.0,
                'status'              => $reviewService->reviewStatus($sub, $appeal, $regrade),
                'appealRequested'     => $appeal !== null,
                'appealStatus'        => $appeal?->status,
                'appealReason'        => $appeal?->reason,
                'appealResolvedAt'    => $appeal && $appeal->resolved_at ? $appeal->resolved_at->toISOString() : null,
                'regradeReviewerId'   => $regrade ? (string) $regrade->assigned_instructor_id : null,
                'regradeReviewerName' => $regradeUser ? (string) $regradeUser->name : null,
                'regradeNote'         => $regrade ? $regrade->note : null,
                'regradeCompletedAt'  => $regrade && $regrade->completed_at ? $regrade->completed_at->toISOString() : null,
                'submittedAt'         => $sub->submitted_at ? $sub->submitted_at->toISOString() : null,
                'parts'          => $parts,
            ];
        }

        // Add eligible students who have not submitted yet
        $eligibleIds = [];

        if (($assignment->audience_type ?: 'classes') === 'all_students') {
            $eligibleIds = User::where('role', User::ROLE_STUDENT)->where('status', 'Active')->pluck('id')->all();
        } else {
            $enrolled = DB::table('class_enrollments')->whereIn('class_id', $classIds)->pluck('student_id')->all();
            $selected = $assignment->selectedStudents->pluck('id')->all();
            $eligibleIds = array_values(array_unique(array_merge($enrolled, $selected)));
        }

        if ($allowedPracticalStudentIds !== null) {
            $eligibleIds = array_values(array_intersect($eligibleIds, $allowedPracticalStudentIds));
        }

        $eligibleUsers = User::whereIn('id', $eligibleIds)->get()->keyBy('id');
        foreach ($eligibleUsers as $studentId => $student) {
            [$studentCode, $className, $variantCode] = $resolveStudentMeta((string) $studentId, $student->email);
            foreach ($problemItems as $ap) {
                $pair = "{$studentId}_{$ap->problem_id}";
                if (!isset($seen[$pair])) {
                    $prob = $ap->problem;
                    $result[] = [
                        'id'          => null,
                        'studentId'   => $studentId,
                        'studentCode' => $studentCode,
                        'student'     => $student->name,
                        'className'   => $className,
                        'variantCode' => $variantCode,
                        'problemId'   => $ap->problem_id,
                        'problem'     => $prob?->title ?? 'Unknown',
                        'score'       => null,
                        'maxScore'    => (float) $ap->points,
                        'status'      => 'Not submitted',
                        'submittedAt' => null,
                        'parts'       => $ap->workspace_parts ?? ($prob?->workspace_parts ?? ['SQL']),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Upload contest banner and source image
     */
    public function uploadBanner(UploadedFile $banner, UploadedFile $source, User $user): array
    {
        $uploadDir = public_path('uploads/contest-banners');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $bannerName = md5(Str::uuid() . microtime()) . '.webp';
        $sourceName = md5(Str::uuid() . microtime() . '_src') . '.webp';

        $banner->move($uploadDir, $bannerName);
        $source->move($uploadDir, $sourceName);

        return [
            'bannerUrl'       => '/api/assignments/banners/' . $bannerName,
            'bannerSourceUrl' => '/api/assignments/banners/' . $sourceName,
        ];
    }

    /**
     * Locate existing banner file on disk
     */
    public function getBannerPath(string $filename): ?string
    {
        if (!preg_match('/^[0-9a-f]{32}\.webp$/', $filename)) {
            return null;
        }
        $path = public_path('uploads/contest-banners/' . $filename);
        return file_exists($path) ? $path : null;
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
     * Sync individually selected students
     */
    protected function syncSelectedStudents(Assignment $assignment, array $emails, array $classIds): void
    {
        $studentIds = $this->resolveStudentEmails($emails);
        $coveredIds = [];
        if (!empty($classIds)) {
            $coveredIds = DB::table('class_enrollments')
                ->whereIn('class_id', $classIds)
                ->pluck('student_id')
                ->all();
        }
        $coveredSet = array_flip($coveredIds);

        $finalIds = array_filter($studentIds, fn($id) => !isset($coveredSet[$id]));

        DB::table('assignment_students')->where('assignment_id', $assignment->id)->delete();

        $rows = [];
        foreach ($finalIds as $sId) {
            $rows[] = [
                'assignment_id' => $assignment->id,
                'student_id'    => $sId,
                'created_at'    => now(),
            ];
        }

        if (!empty($rows)) {
            DB::table('assignment_students')->insert($rows);
        }
    }

    /**
     * Sync problems with points, order, questionSelection, and workspace parts
     */
    protected function syncAssignmentProblems(Assignment $assignment, array $data): void
    {
        $assignment->assignmentProblems()->delete();

        if (!empty($data['problems']) && is_array($data['problems'])) {
            foreach ($data['problems'] as $index => $item) {
                $problemId = is_array($item) ? ($item['id'] ?? null) : $item;
                if (!$problemId) continue;

                $points = is_array($item) ? (float) ($item['points'] ?? 10.0) : 10.0;
                $workspaceParts = is_array($item) ? ($item['workspaceParts'] ?? $item['workspace_parts'] ?? ['DDL', 'DML', 'SQL']) : ['DDL', 'DML', 'SQL'];
                $datasetVisibility = is_array($item) ? ($item['datasetVisibility'] ?? $item['dataset_visibility'] ?? []) : [];
                $questionSelection = is_array($item) ? ($item['questionSelection'] ?? $item['question_selection'] ?? null) : null;
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
                    'question_selection'           => $questionSelection,
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
                    'points'                       => 10.0,
                    'order_index'                  => $index,
                    'workspace_parts'              => ['DDL', 'DML', 'SQL'],
                    'dataset_visibility'           => [],
                    'question_selection'           => null,
                    'database_structure_visible'   => true,
                    'database_description_visible' => true,
                ]);
            }
        }
    }

    /**
     * For already-published assignments, existing problems and sub-questions are locked:
     * teachers may add new problems or new sub-questions, but cannot delete or modify existing ones.
     */
    protected function syncPublishedAssignmentProblems(Assignment $assignment, array $data): void
    {
        $existingRows = $assignment->assignmentProblems()->orderBy('order_index')->get()->keyBy('problem_id');
        $incomingByProblem = [];

        if (!empty($data['problems']) && is_array($data['problems'])) {
            foreach ($data['problems'] as $item) {
                $problemId = is_array($item) ? ($item['id'] ?? null) : $item;
                if (!$problemId) {
                    continue;
                }
                $incomingByProblem[(string) $problemId] = [
                    'id'                           => (string) $problemId,
                    'points'                       => is_array($item) ? (float) ($item['points'] ?? 10.0) : 10.0,
                    'workspace_parts'              => is_array($item) ? ($item['workspaceParts'] ?? $item['workspace_parts'] ?? ['DDL', 'DML', 'SQL']) : ['DDL', 'DML', 'SQL'],
                    'dataset_visibility'           => is_array($item) ? ($item['datasetVisibility'] ?? $item['dataset_visibility'] ?? []) : [],
                    'question_selection'           => is_array($item) ? ($item['questionSelection'] ?? $item['question_selection'] ?? null) : null,
                    'database_structure_visible'   => is_array($item) ? (bool) ($item['databaseStructureVisible'] ?? $item['database_structure_visible'] ?? true) : true,
                    'database_description_visible' => is_array($item) ? (bool) ($item['databaseDescriptionVisible'] ?? $item['database_description_visible'] ?? true) : true,
                ];
            }
        } elseif (!empty($data['problem_ids']) && is_array($data['problem_ids'])) {
            foreach ($data['problem_ids'] as $problemId) {
                $pid = (string) $problemId;
                if ($pid === '') {
                    continue;
                }
                $incomingByProblem[$pid] = [
                    'id'                           => $pid,
                    'points'                       => 10.0,
                    'workspace_parts'              => ['DDL', 'DML', 'SQL'],
                    'dataset_visibility'           => [],
                    'question_selection'           => null,
                    'database_structure_visible'   => true,
                    'database_description_visible' => true,
                ];
            }
        }

        // 1. Ensure no existing problem was removed
        foreach ($existingRows as $pid => $existingRow) {
            if (!isset($incomingByProblem[$pid])) {
                abort(422, 'Bài tập đã công bố không thể xóa các bài toán đã có.');
            }
        }

        // 2. For existing problems: lock settings and forbid removing existing parts/sub-questions; allow adding new sub-questions
        $maxOrder = $existingRows->max('order_index');
        $nextOrder = is_numeric($maxOrder) ? ((int) $maxOrder + 1) : 0;

        foreach ($incomingByProblem as $pid => $item) {
            /** @var AssignmentProblem|null $existingRow */
            $existingRow = $existingRows->get($pid);
            if ($existingRow) {
                $oldParts = is_array($existingRow->workspace_parts) ? $existingRow->workspace_parts : [];
                $newParts = is_array($item['workspace_parts']) ? $item['workspace_parts'] : $oldParts;
                if (!empty(array_diff($oldParts, $newParts))) {
                    abort(422, 'Bài tập đã công bố không thể xóa các phần câu hỏi đã có.');
                }

                $oldSel = is_array($existingRow->question_selection) ? $existingRow->question_selection : null;
                $newSel = is_array($item['question_selection']) ? $item['question_selection'] : null;

                if ($oldSel !== null && $newSel === null) {
                    abort(422, 'Bài tập đã công bố không thể xóa hoặc bỏ chọn các câu hỏi con đã có.');
                }

                if ($oldSel !== null && $newSel !== null) {
                    foreach (['DDL', 'DML', 'SQL', 'TRIGGER'] as $part) {
                        $oldIds = $this->extractSelectionPartQuestionIds($oldSel[$part] ?? null);
                        if (!empty($oldIds)) {
                            $newIds = $this->extractSelectionPartQuestionIds($newSel[$part] ?? null);
                            if (!empty(array_diff($oldIds, $newIds))) {
                                abort(422, 'Bài tập đã công bố không thể xóa hoặc bỏ chọn các câu hỏi con đã có.');
                            }
                        }
                    }
                }

                // Preserve locked problem settings; only allow additive updates to workspace_parts & question_selection
                // and keep equal_questions points synchronized with the recalculated total.
                $mergedParts = array_values(array_unique(array_merge($oldParts, $newParts)));
                $existingRow->workspace_parts = $mergedParts;
                if ($item['points'] > 0) {
                    $existingRow->points = $item['points'];
                }
                if ($newSel !== null) {
                    $existingRow->question_selection = $newSel;
                }
                $existingRow->save();
            } else {
                // 3. New problem added after publish
                AssignmentProblem::create([
                    'id'                           => (string) Str::uuid(),
                    'assignment_id'                => $assignment->id,
                    'problem_id'                   => $pid,
                    'points'                       => $item['points'],
                    'order_index'                  => $nextOrder++,
                    'workspace_parts'              => $item['workspace_parts'],
                    'dataset_visibility'           => $item['dataset_visibility'],
                    'question_selection'           => $item['question_selection'],
                    'database_structure_visible'   => $item['database_structure_visible'],
                    'database_description_visible' => $item['database_description_visible'],
                ]);
            }
        }
    }

    protected function extractSelectionPartQuestionIds(mixed $partSel): array
    {
        if (!is_array($partSel)) {
            return [];
        }
        if (array_is_list($partSel)) {
            return array_values(array_filter(array_map('strval', $partSel)));
        }
        $ids = $partSel['questionIds'] ?? $partSel['ids'] ?? [];
        return is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
    }

    protected function resolveEligibleStudentIds(Assignment $assignment): array
    {
        if ($assignment->is_contest && $assignment->audience_type === 'all_students') {
            return User::where('role', User::ROLE_STUDENT)
                ->where('status', 'Active')
                ->pluck('id')
                ->all();
        }

        $classIds = $assignment->classes()->pluck('classes.id')->all();
        $studentIds = [];
        if (!empty($classIds)) {
            $studentIds = DB::table('class_enrollments')
                ->join('users', 'class_enrollments.student_id', '=', 'users.id')
                ->whereIn('class_enrollments.class_id', $classIds)
                ->where('users.role', User::ROLE_STUDENT)
                ->where('users.status', 'Active')
                ->pluck('users.id')
                ->all();
        }

        $selectedStudentIds = $assignment->selectedStudents()->pluck('users.id')->all();
        return array_values(array_unique(array_merge($studentIds, $selectedStudentIds)));
    }

    /**
     * Send notifications to eligible students when assignment is published
     */
    protected function notifyStudents(Assignment $assignment, ?array $onlyStudentIds = null): void
    {
        $allIds = $onlyStudentIds !== null
            ? array_values(array_unique($onlyStudentIds))
            : $this->resolveEligibleStudentIds($assignment);

        $title = ($assignment->is_contest ? 'Kỳ thi mới: ' : 'Bài tập mới: ') . $assignment->title;
        $link = $assignment->is_contest ? '/contests' : '/assignments';

        $notifications = [];
        foreach ($allIds as $sId) {
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
                'points'                     => (float) $ap->points,
                'datasetVisibility'          => $ap->dataset_visibility ?? (object) [],
                'questionSelection'          => $ap->question_selection,
                'databaseStructureVisible'   => (bool) ($ap->database_structure_visible ?? true),
                'databaseDescriptionVisible' => (bool) ($ap->database_description_visible ?? true),
                'workspaceParts'             => $ap->workspace_parts ?? ['DDL', 'DML', 'SQL'],
            ];
        })->values()->all();

        $studentEmails = $a->selectedStudents->pluck('email')->all();

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
        } elseif (!empty($studentEmails)) {
            $classStr = 'Selected students';
        } else {
            $classStr = 'No classes';
        }

        $audienceCounts = $this->getAudienceCount($audienceType, $classIds, $studentEmails);
        $eligibleStudents = $audienceCounts['eligibleStudents'] ?? 0;

        // Submissions calculation
        $problemIds = array_column($problemsList, 'id');
        $submittedStudents = 0;
        $avgScore = 0;
        $awaiting = 0;
        $reviewId = null;

        if ($eligibleStudents > 0 && !empty($problemIds)) {
            $problemPoints = [];
            foreach ($problemsList as $p) {
                $pts = (float) ($p['points'] ?? 10.0);
                $problemPoints[(string) $p['id']] = $pts > 0 ? $pts : 10.0;
            }

            $eligibleUserMap = null;
            if ($audienceType !== 'all_students') {
                $enrolledIds = !empty($classIds)
                    ? ClassEnrollment::whereIn('class_id', $classIds)->pluck('student_id')->all()
                    : [];
                $selectedIds = $a->selectedStudents->pluck('id')->all();
                $eligibleUserMap = array_flip(array_values(array_unique(array_merge($enrolledIds, $selectedIds))));
            }

            $latestSubsQuery = Submission::where('context', $a->id)
                ->whereIn('problem_id', $problemIds)
                ->whereIn('source', ['Assignments', 'Contests', 'Assignment', 'Contest'])
                ->whereDoesntHave('workspaceResult', function ($q) {
                    $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
                })
                ->orderByDesc('submitted_at');

            if ($eligibleUserMap !== null) {
                $latestSubsQuery->whereIn('user_id', array_keys($eligibleUserMap));
            }

            $latestSubs = $latestSubsQuery->get(['id', 'user_id', 'problem_id', 'score', 'evaluated_score']);

            $subIds = $latestSubs->pluck('id')->all();
            $pendingAppeals = !empty($subIds)
                ? \App\Models\SubmissionAppeal::whereIn('submission_id', $subIds)->whereNull('resolved_at')->pluck('submission_id')->flip()->all()
                : [];
            $pendingRegrades = !empty($subIds)
                ? \App\Models\SubmissionRegradeAssignment::whereIn('submission_id', $subIds)->whereNull('completed_at')->pluck('submission_id')->flip()->all()
                : [];

            $seenPairs = [];
            $submittedUsers = [];
            $awaitingUsers = [];
            $pctSum = 0.0;
            $pctCount = 0;

            foreach ($latestSubs as $sub) {
                $submittedUsers[$sub->user_id] = true;
                $pair = "{$sub->user_id}_{$sub->problem_id}";
                if (isset($seenPairs[$pair])) {
                    continue;
                }
                $seenPairs[$pair] = true;

                $effectiveScore = $sub->evaluated_score !== null ? (float) $sub->evaluated_score : (float) $sub->score;
                $maxPts = $problemPoints[(string) $sub->problem_id] ?? 10.0;
                $pctSum += min(100.0, max(0.0, ($effectiveScore / $maxPts) * 100.0));
                $pctCount++;

                if ($sub->evaluated_score === null || isset($pendingAppeals[$sub->id]) || isset($pendingRegrades[$sub->id])) {
                    $reviewId ??= $sub->id;
                    $awaitingUsers[$sub->user_id] = true;
                }
            }

            $submittedStudents = count($submittedUsers);
            $avgScore = $pctCount > 0 ? (int) round($pctSum / $pctCount) : 0;
            $awaiting = count($awaitingUsers);
        }

        $submittedStr = "{$submittedStudents}/{$eligibleStudents}";

        return [
            'id'               => (string) $a->id,
            'title'            => (string) $a->title,
            'isContest'        => (bool) $a->is_contest,
            'is_contest'       => (bool) $a->is_contest,
            'scoringMode'      => (string) ($a->scoring_mode ?: 'equal_questions'),
            'scoring_mode'     => (string) ($a->scoring_mode ?: 'equal_questions'),
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
                'examViolationLimit'    => (int) ($a->exam_violation_limit ?? 2),
                'showScoresAfterSubmit' => (bool) $a->show_scores_after_submit,
                'maxSubmissions'        => $a->max_submissions,
            ],
            'problemList'       => $problemsList,
            'classIds'          => $classIds,
            'studentEmails'     => $studentEmails,
            'examinationId'     => $a->examination_id,
            'sessionIndex'      => $a->session_index,
            'proctors'          => is_array($a->proctors) ? $a->proctors : [],
            'room'              => (string) ($a->room ?? ''),
            'capacity'          => $a->capacity,
            'admissionRequired' => (bool) $a->admission_required,
            'createdAt'         => $a->created_at ? $a->created_at->toISOString() : null,
            'created_at'        => $a->created_at ? $a->created_at->toISOString() : null,
        ];
    }

    /**
     * Get student's enrolled classes, assignments, deadlines, and problems.
     * Corresponds to GET /api/student/assignments in SQL-UIT
     */
    public function getStudentAssignments(User $currentUser): array
    {
        if ($currentUser->role !== User::ROLE_STUDENT && $currentUser->role !== 'student' && !$currentUser->isAdmin()) {
            abort(403, 'Only active students can view assigned activities.');
        }

        if ($currentUser->status !== 'Active') {
            abort(403, 'Account is not active.');
        }

        $individualClassId = '__individual__';

        // 1. Get classes student is enrolled in
        $enrollments = ClassEnrollment::where('student_id', $currentUser->id)->get();
        $classIds = $enrollments->pluck('class_id')->all();

        // 2. Class details
        $studentClasses = ClassModel::whereIn('id', $classIds)
            ->with('instructor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $classesRes = [];
        foreach ($studentClasses as $c) {
            $classesRes[] = [
                'createdAt' => $c->created_at ? $c->created_at->toISOString() : null,
                'id'        => (string) $c->id,
                'code'      => "{$c->course} · {$c->term}",
                'name'      => (string) $c->course,
                'lecturer'  => (string) ($c->instructor ? $c->instructor->name : ''),
            ];
        }

        // 3. Get assignments assigned to student's classes or student individually or all_students or ExamAdmission
        $studentAssignmentClasses = DB::table('assignment_classes')
            ->whereIn('class_id', $classIds)
            ->get();
        $assignmentIds = $studentAssignmentClasses->pluck('assignment_id')->unique()->all();

        $individualAssignmentIds = DB::table('assignment_students')
            ->where('student_id', $currentUser->id)
            ->pluck('assignment_id')
            ->all();

        $admissionAssignmentIds = \App\Models\ExamAdmission::where('student_id', $currentUser->id)
            ->where('status', '!=', 'revoked')
            ->pluck('session_id')
            ->all();

        $allTargetIds = array_values(array_unique(array_merge($assignmentIds, $individualAssignmentIds, $admissionAssignmentIds)));

        $studentAssignments = Assignment::where('published', true)
            ->where(function ($q) use ($allTargetIds) {
                if (!empty($allTargetIds)) {
                    $q->whereIn('id', $allTargetIds);
                }
                $q->orWhere(function ($sq) {
                    $sq->where('is_contest', true)
                        ->where('audience_type', 'all_students');
                });
            })
            ->with(['assignmentProblems.problem'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $admissionService = app(ExamAdmissionService::class);
        $attemptsMap = \App\Models\ActivityAttempt::where('user_id', $currentUser->id)
            ->whereIn('assignment_id', $studentAssignments->pluck('id')->all())
            ->get()
            ->keyBy('assignment_id');

        $assignmentsRes = [];
        $deadlinesRes = [];
        $individual = [];

        foreach ($studentAssignments as $a) {
            $assignedClassIds = $studentAssignmentClasses->where('assignment_id', $a->id)->pluck('class_id')->all();

            if (!$a->is_contest && empty($assignedClassIds)) {
                $assignedClassIds = [$individualClassId];
                $individual[] = $a;
            }

            $now = now();
            $admissionUnlocked = $admissionService->hasUnlockedAdmission(request(), $currentUser, $a);
            $attempt = $attemptsMap->get($a->id);
            $isOpen = (empty($a->opens) || $a->opens <= $now)
                && (!$a->admission_required || $admissionUnlocked || ($attempt && $attempt->status === 'submitted'));
            $contestStatus = null;
            if ($a->is_contest) {
                $contestStatus = ($a->opens && $now < $a->opens)
                    ? 'Upcoming'
                    : (($a->closes && $now >= $a->closes) ? 'Closed' : 'Live');
            }

            $aProblems = $a->assignmentProblems ?? collect([]);
            $pIds = $aProblems->pluck('problem_id')->all();

            // Submissions by this student (exclude per-question partial saves)
            $subs = Submission::where('user_id', $currentUser->id)
                ->whereIn('problem_id', $pIds)
                ->where('context', $a->id)
                ->whereIn('source', ['Assignments', 'Contests', 'Assignment', 'Contest'])
                ->whereDoesntHave('workspaceResult', function ($q) {
                    $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
                })
                ->get();

            $solvedCount = $subs->where('result', 'Accepted')->pluck('problem_id')->unique()->count();
            $attemptedCount = $subs->pluck('problem_id')->unique()->count();

            if ($attempt && $attempt->status === 'submitted') {
                if (!$a->show_scores_after_submit) {
                    $status = 'In progress';
                } elseif (count($pIds) > 0 && $solvedCount === count($pIds)) {
                    $status = 'Solved';
                } else {
                    $status = 'In progress';
                }
            } elseif (count($pIds) > 0 && $solvedCount === count($pIds)) {
                $status = $a->show_scores_after_submit ? 'Solved' : 'In progress';
            } elseif ($attemptedCount > 0) {
                $status = 'In progress';
            } else {
                $status = 'Not started';
            }

            $problemProgress = [];
            foreach ($pIds as $pId) {
                $pSubs = $subs->where('problem_id', $pId);
                if ($pSubs->isEmpty()) {
                    $problemProgress[$pId] = 'Not started';
                } elseif (!$a->show_scores_after_submit) {
                    $problemProgress[$pId] = 'In progress';
                } elseif ($pSubs->contains('result', 'Accepted')) {
                    $problemProgress[$pId] = 'Solved';
                } else {
                    $problemProgress[$pId] = 'In progress';
                }
            }

            $score = 0;
            foreach ($aProblems as $ap) {
                $best = $subs->where('problem_id', $ap->problem_id)->max(function ($s) {
                    return $s->evaluated_score !== null ? $s->evaluated_score : $s->score;
                }) ?? 0;
                $score += min($ap->points, $best);
            }
            if (!$a->show_scores_after_submit) {
                $score = 0;
            }

            $submissionCounts = [];
            foreach ($pIds as $pId) {
                $submissionCounts[$pId] = $subs->where('problem_id', $pId)->count();
            }

            $totalPoints = (float) $aProblems->sum('points');

            if ($a->is_contest && $a->audience_type === 'all_students') {
                $scope = 'All students';
            } else {
                $matchedNames = collect($classesRes)->whereIn('id', $assignedClassIds)->pluck('name')->all();
                $scope = !empty($matchedNames) ? implode(', ', $matchedNames) : 'Selected students';
            }

            $problemDetails = [];
            if ($isOpen && $a->is_contest) {
                $probs = Problem::whereIn('id', $pIds)->get();
                foreach ($probs as $p) {
                    $ap = $aProblems->firstWhere('problem_id', $p->id);
                    $problemDetails[] = [
                        'id'         => (string) $p->id,
                        'title'      => (string) $p->title,
                        'difficulty' => (string) $p->difficulty,
                        'topic'      => (string) $p->topic,
                        'points'     => $ap ? (float) $ap->points : 0.0,
                    ];
                }
            }

            $assignmentsRes[] = [
                'id'                    => (string) $a->id,
                'title'                 => (string) $a->title,
                'createdAt'             => $a->created_at ? $a->created_at->toISOString() : null,
                'instructions'          => (string) ($a->instructions ?? ''),
                'classIds'              => array_values($assignedClassIds),
                'date'                  => $a->closes ? $a->closes->copy()->utc()->format('Y-m-d') : '',
                'time'                  => $a->closes ? $a->closes->copy()->utc()->format('H:i') : '',
                'status'                => $status,
                'problemIds'            => $isOpen ? array_values($pIds) : [],
                'problemDetails'        => $problemDetails,
                'problemCount'          => count($pIds),
                'problemProgress'       => $isOpen ? (object) $problemProgress : (object) [],
                'isContest'             => (bool) $a->is_contest,
                'audienceType'          => (string) ($a->audience_type ?: 'classes'),
                'scope'                 => $scope,
                'shortDescription'      => (string) ($a->short_description ?? ''),
                'description'           => (string) ($a->description ?? ''),
                'rules'                 => (string) ($a->rules ?? ''),
                'bannerUrl'             => $a->is_contest ? $a->banner_url : null,
                'opens'                 => $a->opens ? $a->opens->toISOString() : null,
                'closes'                => $a->closes ? $a->closes->toISOString() : null,
                'contestStatus'         => $contestStatus,
                'leaderboardEnabled'    => (bool) ($a->is_contest && $a->leaderboard_enabled),
                'aiAllowed'             => (bool) $a->ai_allowed,
                'examMode'              => (bool) $a->exam_mode,
                'showScoresAfterSubmit' => (bool) $a->show_scores_after_submit,
                'maxSubmissions'        => $a->max_submissions,
                'submissionCounts'      => (object) $submissionCounts,
                'totalPoints'           => $totalPoints,
                'score'                 => $score,
                'submitters'            => 0,
                'rank'                  => null,
                'leaderboard'           => [],
                'examinationId'         => $a->examination_id,
                'sessionIndex'          => $a->session_index,
                'room'                  => (string) ($a->room ?? ''),
                'examViolationLimit'    => (int) ($a->exam_violation_limit ?? 2),
                'proctors'              => is_array($a->proctors) ? $a->proctors : [],
                'admissionRequired'     => (bool) $a->admission_required,
                'admissionUnlocked'     => (bool) $admissionUnlocked,
                'attemptStatus'         => $attempt ? $attempt->status : null,
                'finishReason'          => $attempt ? $attempt->finish_reason : null,
                'serverTime'            => now()->toISOString(),
            ];

            if ($a->closes && $a->closes >= $now && $status !== 'Solved') {
                $deadlinesRes[] = [
                    'id'        => (string) $a->id,
                    'title'     => (string) $a->title,
                    'createdAt' => $a->created_at ? $a->created_at->toISOString() : null,
                    'date'      => $a->closes->copy()->utc()->format('Y-m-d'),
                    'time'      => $a->closes->copy()->utc()->format('H:i'),
                    'kind'      => $a->is_contest ? 'Contest' : 'Assignment',
                    'context'   => $scope,
                    'to'        => $a->is_contest ? ('/contests/' . $a->id) : ('/assignments?work=' . $a->id),
                ];
            }
        }

        usort($deadlinesRes, function ($d1, $d2) {
            return strcmp($d1['date'] . 'T' . $d1['time'], $d2['date'] . 'T' . $d2['time']);
        });

        // 4. Problem details catalog for frontend
        $allPIds = [];
        foreach ($assignmentsRes as $ar) {
            foreach ($ar['problemIds'] as $pid) {
                $allPIds[$pid] = true;
            }
        }

        $problemsDb = Problem::whereIn('id', array_keys($allPIds))->get();
        $problemsRes = [];
        foreach ($problemsDb as $p) {
            $problemsRes[] = [
                'id'         => (string) $p->id,
                'title'      => (string) $p->title,
                'topic'      => (string) ($p->topic ?? ''),
                'difficulty' => (string) ($p->difficulty ?? 'Medium'),
            ];
        }

        if (!empty($individual)) {
            $ownerIds = collect($individual)->pluck('instructor_id')->filter()->unique()->all();
            $owners = User::whereIn('id', $ownerIds)->pluck('name')->all();
            $lecturerName = count($owners) === 1 ? $owners[0] : (count($owners) > 1 ? 'Nhiều giảng viên' : '');
            $maxCreatedAt = collect($individual)->pluck('created_at')->filter()->max();

            array_unshift($classesRes, [
                'createdAt' => $maxCreatedAt ? $maxCreatedAt->toISOString() : null,
                'id'        => $individualClassId,
                'code'      => 'Giao riêng · Bài tập được giao trực tiếp',
                'name'      => 'Bài tập giao riêng',
                'lecturer'  => $lecturerName,
            ]);
        }

        return [
            'classes'     => $classesRes,
            'assignments' => $assignmentsRes,
            'deadlines'   => $deadlinesRes,
            'problems'    => $problemsRes,
        ];
    }
}
