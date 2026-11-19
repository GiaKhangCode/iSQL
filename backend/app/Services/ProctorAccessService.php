<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\ExamClassProctor;
use App\Models\User;
use App\Services\Access\StaffAccess;

/**
 * Ai được coi thi ca nào, và danh sách ca/phòng của một giám thị.
 *
 * Quyền vào một ca: quản lý kỳ thi, chủ ca, giám thị ghi tên trong ca (theo tên hoặc email), giám thị được phân
 * công cho lớp/phòng, hoặc giảng viên dạy một lớp của ca (cột instructor_id, practice_instructor_id hay bảng
 * class_instructors). Khi chọn một phòng (roomId) thì chỉ tính phân công/giảng dạy của đúng lớp đó.
 */
class ProctorAccessService
{
    public function __construct(protected readonly StaffAccess $staff)
    {
    }

    public function assertCanProctor(User $user): void
    {
        abort_unless($this->staff->canProctor($user), 403, 'Bạn không có quyền coi thi.');
    }

    /** Ca thi (assignment có examination_id) mà $user được phép coi; ném 403/404 nếu không. */
    public function requireSession(string $sessionId, User $user, ?string $roomId = null): Assignment
    {
        $this->assertCanProctor($user);
        $session = Assignment::where('id', $sessionId)
            ->whereNotNull('examination_id')
            ->firstOrFail();

        if ($this->staff->canManageExaminations($user) || $session->instructor_id === $user->id) {
            return $session;
        }

        if ($this->isNamedProctor($session, $user)) {
            return $session;
        }

        $assignedProctor = ExamClassProctor::where('session_id', $session->id)
            ->where('proctor_id', $user->id)
            ->when($roomId, fn ($q) => $q->where('class_id', $roomId))
            ->exists();
        if ($assignedProctor) {
            return $session;
        }

        $sessionClassIds = $session->classes()->pluck('classes.id')->all();
        if ($roomId) {
            $sessionClassIds = array_values(array_intersect($sessionClassIds, [$roomId]));
        }
        if (!empty($sessionClassIds)) {
            $teaches = ClassModel::whereIn('id', $sessionClassIds)
                ->where(function ($q) use ($user) {
                    $q->where('instructor_id', $user->id)
                      ->orWhere('practice_instructor_id', $user->id)
                      ->orWhereHas('instructors', fn ($iq) => $iq->where('users.id', $user->id));
                })
                ->exists();
            if ($teaches) {
                return $session;
            }
        }

        abort(403, 'Bạn không được phân công coi thi ca này.');
    }

    /**
     * GET /proctor/sessions: mỗi (ca, phòng) mà giám thị được coi. Ca chưa gán lớp chỉ hiện cho quản lý,
     * chủ ca hoặc giám thị ghi tên trong ca.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsFor(User $user): array
    {
        $this->assertCanProctor($user);

        $sessions = Assignment::whereNotNull('examination_id')
            ->where('published', true)
            ->with('classes')
            ->orderBy('opens')
            ->orderBy('session_index')
            ->get();

        $taughtClassIds = ClassModel::where('instructor_id', $user->id)
            ->orWhere('practice_instructor_id', $user->id)
            ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
            ->pluck('id')
            ->flip()
            ->all();

        $proctorPairs = [];
        foreach (ExamClassProctor::where('proctor_id', $user->id)->get() as $r) {
            $proctorPairs["{$r->session_id}:{$r->class_id}"] = true;
        }

        $items = [];
        foreach ($sessions as $s) {
            $sClasses = $s->classes;
            $isAdminOrOwner = $this->staff->canManageExaminations($user) || $s->instructor_id === $user->id;
            $namedProctor = $this->isNamedProctor($s, $user);

            if ($sClasses->isEmpty()) {
                if ($isAdminOrOwner || $namedProctor) {
                    $items[] = [
                        'sessionId' => (string) $s->id,
                        'title'     => (string) $s->title,
                        'roomId'    => null,
                        'room'      => $s->room ?: 'Chưa gán lớp',
                        'opens'     => $s->opens ? $s->opens->toISOString() : null,
                        'closes'    => $s->closes ? $s->closes->toISOString() : null,
                    ];
                }
                continue;
            }

            foreach ($sClasses as $c) {
                $allowed = $isAdminOrOwner
                    || $namedProctor
                    || isset($proctorPairs["{$s->id}:{$c->id}"])
                    || isset($taughtClassIds[$c->id]);

                if ($allowed) {
                    $label = $c->practice_class ?: $c->course ?: $c->id;
                    $items[] = [
                        'sessionId' => (string) $s->id,
                        'title'     => (string) $s->title,
                        'roomId'    => (string) $c->id,
                        'room'      => (string) $label,
                        'opens'     => $s->opens ? $s->opens->toISOString() : null,
                        'closes'    => $s->closes ? $s->closes->toISOString() : null,
                    ];
                }
            }
        }

        return $items;
    }

    /** Giám thị ghi tên trong ca (cột proctors) bằng tên hoặc email, không phân biệt hoa thường. */
    protected function isNamedProctor(Assignment $session, User $user): bool
    {
        $proctorNames = is_array($session->proctors) ? array_map('mb_strtolower', $session->proctors) : [];

        return in_array(mb_strtolower((string) $user->name), $proctorNames, true)
            || in_array(mb_strtolower((string) $user->email), $proctorNames, true);
    }
}
