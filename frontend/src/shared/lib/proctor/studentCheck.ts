/**
 * Kết quả kiểm tra một sinh viên trước khi thêm/chuyển vào ca thi.
 *
 * Backend (POST /api/proctor/sessions/{id}/students/check) trả phẳng: { status, canAdd, studentId, studentCode,
 * name, email, message, otherSessionId?, otherSession? }. Thẻ kết quả trong ProctorPage dùng dạng `StudentCheckResult`
 * (eligible, canTransfer, reason, student, previousSession), hàm `toStudentCheckResult` chuyển từ dạng trên sang dạng này.
 */

export type StudentCheckStatus =
  | 'eligible'
  | 'already_in_session'
  | 'in_other_session'
  | 'in_other_session_class'
  | 'already_participated';

export type StudentCheckResponse = {
  status: StudentCheckStatus;
  canAdd: boolean;
  studentId: string;
  studentCode: string;
  name: string;
  email: string;
  message: string;
  otherSessionId?: string;
  otherSession?: string;
};

export type StudentCheckResult = {
  ok: boolean;
  found: boolean;
  eligible: boolean;
  canTransfer?: boolean;
  reason?: string;
  student?: {
    studentId: string;
    studentCode: string;
    name: string;
    email: string;
    className?: string | null;
    classId?: string | null;
  };
  previousSession?: {
    id?: string;
    title: string;
    sessionIndex?: number;
    status: 'absent' | 'started' | 'submitted' | 'ongoing' | 'upcoming';
    ended?: boolean;
  };
};

/**
 * - eligible: được thêm (canAdd).
 * - canTransfer: đang thuộc ca khác (thêm riêng) và chưa thi, nên thêm vào đây là chuyển ca.
 * - previousSession: ca khác mà sinh viên đang thuộc; "started" nếu đã tham gia thi ở đó, còn lại "absent".
 */
export function toStudentCheckResult(res: StudentCheckResponse): StudentCheckResult {
  const previousSession = res.otherSession
    ? {
        id: res.otherSessionId,
        title: res.otherSession,
        status: res.status === 'already_participated' ? ('started' as const) : ('absent' as const),
      }
    : undefined;

  return {
    ok: true,
    found: true,
    eligible: Boolean(res.canAdd),
    canTransfer: res.status === 'in_other_session',
    reason: res.message,
    student: {
      studentId: res.studentId,
      studentCode: res.studentCode,
      name: res.name,
      email: res.email,
    },
    previousSession,
  };
}
