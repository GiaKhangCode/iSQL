<?php

namespace App\Services;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Submission;
use App\Models\User;

/**
 * Hồ sơ của sinh viên (GET /student/profile): thông tin, lớp đang học, số bài luyện tập và lịch sử bài tập / kỳ thi
 * kèm điểm. Điểm của một bài = điểm chính thức nếu có, không thì điểm tự động, tối đa bằng điểm của bài; chỉ tính
 * lần nộp mới nhất của mỗi bài; điểm ẩn (null) khi hoạt động chưa công bố điểm.
 */
class StudentProfileService
{
    public function __construct(protected readonly ExaminationService $examinationService)
    {
    }

    /** @return array<string, mixed> */
    public function profile(User $user): array
    {
        $code = $this->examinationService->studentCode($user->email, $user->id);

        $classIds = ClassEnrollment::where('student_id', $user->id)->pluck('class_id')->all();
        $classes = !empty($classIds)
            ? ClassModel::whereIn('id', $classIds)->with('instructor')->orderBy('course')->get()
            : collect([]);

        $classesPayload = $classes->map(fn (ClassModel $c) => [
            'id'           => (string) $c->id,
            'course'       => (string) $c->course,
            'courseCode'   => (string) ($c->course_code ?? ''),
            'theoryClass'  => (string) ($c->theory_class ?? $c->id),
            'practiceClass'=> (string) ($c->practice_class ?? $c->id),
            'term'         => (string) $c->term,
            'lecturer'     => (string) ($c->instructor ? $c->instructor->name : ''),
        ])->values()->all();

        $practiceSubmissions = Submission::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('source', 'Practice')->orWhere('context', 'Practice');
            })
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->count();

        $subs = Submission::where('user_id', $user->id)
            ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereNotNull('context')
            ->where('context', '!=', 'Practice')
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->orderByDesc('submitted_at')
            ->get();

        $byActivity = [];
        foreach ($subs as $s) {
            $byActivity[$s->context][$s->problem_id] = $byActivity[$s->context][$s->problem_id] ?? $s;
        }

        $attemptIds = ActivityAttempt::where('user_id', $user->id)->pluck('assignment_id')->all();
        $activityIds = array_values(array_unique(array_merge(array_keys($byActivity), $attemptIds)));

        $activities = !empty($activityIds)
            ? Assignment::whereIn('id', $activityIds)->with('assignmentProblems')->orderByDesc('closes')->get()
            : collect([]);

        $history = [];
        foreach ($activities as $act) {
            $aps = $act->assignmentProblems;
            $maxScore = (float) $aps->sum('points');
            $earned = 0.0;
            $submittedProblems = 0;
            $latestSubAt = null;
            foreach ($aps as $ap) {
                $s = $byActivity[$act->id][$ap->problem_id] ?? null;
                if ($s) {
                    $submittedProblems++;
                    if ($s->submitted_at && (!$latestSubAt || $s->submitted_at->gt($latestSubAt))) {
                        $latestSubAt = $s->submitted_at;
                    }
                    $val = $s->evaluated_score !== null ? (float) $s->evaluated_score : (float) ($s->score ?? 0);
                    $earned += min((float) $ap->points, $val);
                }
            }
            $att = ActivityAttempt::where('assignment_id', $act->id)->where('user_id', $user->id)->first();
            $submittedAtIso = $att && $att->submitted_at
                ? $att->submitted_at->toISOString()
                : ($latestSubAt ? $latestSubAt->toISOString() : null);

            $status = 'not_submitted';
            if ($att && $att->status === 'active' && $submittedProblems === 0) {
                $status = 'in_progress';
            } elseif ($submittedProblems > 0 || ($att && $att->status === 'submitted')) {
                $status = $act->show_scores_after_submit ? 'graded' : 'unpublished';
            }

            $history[] = [
                'id'                => (string) $act->id,
                'title'             => (string) $act->title,
                'kind'              => $act->examination_id ? 'Examination' : 'Assignment',
                'examinationId'     => $act->examination_id,
                'sessionIndex'      => $act->session_index,
                'room'              => $act->room ?? '',
                'date'              => $submittedAtIso,
                'submittedAt'       => $submittedAtIso,
                'finishReason'      => $att ? $att->finish_reason : null,
                'status'            => $status,
                'score'             => $act->show_scores_after_submit ? round($earned, 2) : null,
                'maxScore'          => $maxScore,
                'scorePublished'    => (bool) $act->show_scores_after_submit,
                'submittedProblems' => $submittedProblems,
                'totalProblems'     => $aps->count(),
            ];
        }

        return [
            'id'                  => (string) $user->id,
            'name'                => (string) $user->name,
            'email'               => (string) $user->email,
            'studentCode'         => $code,
            'department'          => 'Khoa Hệ thống Thông tin',
            'practiceSubmissions' => $practiceSubmissions,
            'classes'             => $classesPayload,
            'profile'             => [
                'id'                  => (string) $user->id,
                'name'                => (string) $user->name,
                'email'               => (string) $user->email,
                'studentCode'         => $code,
                'department'          => 'Khoa Hệ thống Thông tin',
                'practiceSubmissions' => $practiceSubmissions,
                'classes'             => $classesPayload,
            ],
            'history' => $history,
        ];
    }
}
