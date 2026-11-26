import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import {useEffect, useMemo, useState} from "react";
import {Navigate, useNavigate, useParams, useLocation} from "react-router-dom";
import {
  CalendarDays,
  AlertTriangle,
  Unlock,
  UserPlus,
  Eye,
  Users,
  ArrowLeft,
  ShieldCheck,
  AlertCircle,
  RotateCcw,
  Search,
  Laptop,
  CheckCircle2,
  Clock,
  Bell,
} from "lucide-react";
import {apiFetch} from "@/shared/api/apiClient";
import {toStudentCheckResult, type StudentCheckResponse, type StudentCheckResult} from "@/shared/lib/proctor/studentCheck";
import {useAuth} from "@/app/providers/AuthContext";
import {RoleHeader} from "@/app/shells/RoleHeader";
import {Dialog} from "@/shared/ui/ui";
import {Table, TableRow} from "@/shared/ui/Table";
import {TeacherListFilters} from "@/shared/components/TeacherListFilters";
import {TeacherListPagination} from "@/shared/components/TeacherListPagination";
import {parseServerDateTime} from "@/shared/lib/datetime/serverDateTime";
import {matchesListSearch} from "@/shared/lib/listSearch";
import {toast} from "@/shared/ui/StackingToasts";
import "@/styles/exam-portal.css";

export type SessionRaw = {
  sessionId: string;
  title: string;
  roomId: string;
  room: string;
  opens: string;
  closes: string;
};

export type ExamRoom = {
  roomId: string;
  room: string;
};

export type ExamGroup = {
  sessionId: string;
  title: string;
  opens: string;
  closes: string;
  rooms: ExamRoom[];
};

export type Candidate = {
  studentId: string;
  studentCode?: string;
  name: string;
  email: string;
  roomId: string | null;
  admissionId: string | null;
  locked: boolean;
  isViolationLocked?: boolean;
  isDuplicateLocked?: boolean;
  violationCount?: number;
  violationLimit?: number;
  finishReason?: string | null;
  incidentAt: string | null;
  attemptStatus: string;
  browsers: { id: string; code: string; status: string; createdAt?: string | null; startedAt?: string | null; ipAddress?: string | null; userAgent?: string; lastSeenAt: string | null }[];
};

/** A proctor action stored on the server (GET /api/proctor/sessions/:id/notices). */
type ProctorNotice = {
  id: string;
  kind: 'unlock' | 'add_student' | 'transfer_student' | 'approve_browser';
  studentId: string;
  studentName?: string;
  student?: string;
  studentCode?: string | null;
  actorName?: string | null;
  actor?: string | null;
  detail?: string | null;
  createdAt: string;
};

export function ProctorPage() {
  const {session, preferredArea} = useAuth();
  if (!session) return <Navigate to="/login" replace />;
  // Khu vực đã có tab Proctoring thì dùng trang đó (cùng nội dung, có thanh điều hướng đúng).
  if (preferredArea?.tabs.some(tab => tab.path === '/teacher/exams')) return <Navigate to="/teacher/exams" replace />;
  return (
    <>
      <RoleHeader />
      <ProctorRoomsView />
    </>
  );
}

export function ProctorRoomsView({onBack}: {onBack?: () => void}) {
  const {t, locale} = useLanguage();
  const {session, permissions} = useAuth();
  const {sessionId: routeSessionId} = useParams<{sessionId?: string}>();
  const navigate = useNavigate();
  const location = useLocation();

  const isOfficeProctor = location.pathname.startsWith('/proctor');
  const basePath = isOfficeProctor ? '/proctor' : '/teacher/exams';

  const [sessions, setSessions] = useState<SessionRaw[]>([]);
  const [selectedSessionId, setSelectedSessionId] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);

  // Khớp backend: coi thi cần exam.proctor hoặc examination.manage (siêu quản trị có examination.manage).
  const canProctor = Boolean(
    permissions?.includes('exam.proctor') ||
    permissions?.includes('examination.manage')
  );

  useEffect(() => {
    if (permissions === null) return;
    let active = true;
    if (!canProctor) {
      setSessions([]);
      setLoading(false);
      return;
    }
    const load = async () => {
      try {
        const data = await apiFetch<SessionRaw[]>("/api/proctor/sessions");
        if (active) {
          setSessions(data);
          setError("");
        }
      } catch (e) {
        if (active) setError(e instanceof Error ? e.message : t("Could not load exam rooms."));
      } finally {
        if (active) setLoading(false);
      }
    };
    void load();
    const timer = setInterval(() => void load(), 60000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [session?.id, canProctor, permissions === null]);

  // Group rooms by exam session
  const examGroups = useMemo<ExamGroup[]>(() => {
    const map = new Map<string, ExamGroup>();
    for (const item of sessions) {
      let group = map.get(item.sessionId);
      if (!group) {
        group = {
          sessionId: item.sessionId,
          title: item.title,
          opens: item.opens,
          closes: item.closes,
          rooms: [],
        };
        map.set(item.sessionId, group);
      }
      if (!group.rooms.some(r => r.roomId === item.roomId)) {
        group.rooms.push({roomId: item.roomId, room: item.room});
      }
    }
    return Array.from(map.values());
  }, [sessions]);

  const now = Date.now();
  const date = (value: string) => parseServerDateTime(value).toLocaleString(locale);

  const getStatusInfo = (opens: string, closes: string) => {
    const start = parseServerDateTime(opens).getTime();
    const end = parseServerDateTime(closes).getTime();
    if (now < start) return {key: 'upcoming', label: t('Sắp diễn ra'), className: 'upcoming'};
    if (now <= end) return {key: 'active', label: t('Đang diễn ra'), className: 'active'};
    return {key: 'finished', label: t('Đã kết thúc'), className: 'finished'};
  };

  const visible = useMemo(() => {
    return examGroups.filter(exam => {
      const matchSearch = matchesListSearch(search, exam.title, ...exam.rooms.map(r => r.room));
      if (!matchSearch) return false;
      if (!status) return true;
      const statusInfo = getStatusInfo(exam.opens, exam.closes);
      return statusInfo.key === status;
    });
  }, [examGroups, search, status]);

  const pageCount = Math.max(1, Math.ceil(visible.length / 15));
  const currentPage = Math.min(page, pageCount);
  const start = (currentPage - 1) * 15;
  const rows = visible.slice(start, start + 15);

  const selectedGroup = rows.find(r => r.sessionId === selectedSessionId) || rows[0] || null;

  useEffect(() => {
    setPage(1);
    setSelectedSessionId('');
  }, [search, status]);

  // Match session from URL params
  const currentExam = useMemo(() => {
    if (!routeSessionId) return null;
    return examGroups.find(g => g.sessionId === routeSessionId) || null;
  }, [examGroups, routeSessionId]);

  if (routeSessionId && loading) {
    return (
      <div className="proctor-dashboard-wrap" style={{ display: 'grid', placeItems: 'center', minHeight: '50vh' }}>
        <p className="muted"><TranslatedText>{t("Đang tải dữ liệu ca thi…")}</TranslatedText></p>
      </div>
    );
  }

  if (routeSessionId && !currentExam && !loading) {
    return (
      <div className="proctor-dashboard-wrap" style={{ padding: 24, textAlign: 'center' }}>
        <p role="alert" style={{ color: 'var(--color-danger, #ef4444)' }}>
          <TranslatedText>{t("Không tìm thấy ca thi hoặc bạn không có quyền giám sát ca thi này.")}</TranslatedText>
        </p>
        <button
          type="button"
          className="button secondary"
          onClick={() => (onBack ? onBack() : navigate(basePath))}
          style={{ marginTop: 12 }}
        >
          <ArrowLeft size={14} style={{ marginRight: 6 }} />
          <TranslatedText>{t("Quay lại danh sách kỳ thi")}</TranslatedText>
        </button>
      </div>
    );
  }

  if (currentExam) {
    return (
      <ProctorExamDashboard
        exam={currentExam}
        onBack={() => (onBack ? onBack() : navigate(basePath))}
      />
    );
  }

  return (
    <section className="teacher-page compact-teacher-classes proctor-list-page">
      <h1 className="sr-only"><TranslatedText>{t("Kỳ thi")}</TranslatedText></h1>
      {error && <p role="alert">{error}</p>}
      <div className="teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main">
          <TeacherListFilters
            search={search}
            onSearchChange={setSearch}
            searchLabel={t("Tìm kiếm kỳ thi, ca thi hoặc lớp…")}
            actionsTarget={actionsTarget}
            filters={[
              {
                key: 'status',
                label: t('Trạng thái'),
                value: status,
                defaultValue: '',
                onChange: setStatus,
                icon: <CalendarDays size={17} />,
                options: [
                  {value: '', label: t('Tất cả trạng thái')},
                  {value: 'active', label: t('Đang diễn ra')},
                  {value: 'upcoming', label: t('Sắp diễn ra')},
                  {value: 'finished', label: t('Đã kết thúc')},
                ],
              },
            ]}
          />
          <div className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
            <Table className="teacher-table sticky-list-table">
              <thead>
                <tr>
                  <th><TranslatedText>{t("Tên kỳ thi / Ca thi")}</TranslatedText></th>
                  <th><TranslatedText>{t("Bắt đầu")}</TranslatedText></th>
                  <th><TranslatedText>{t("Kết thúc")}</TranslatedText></th>
                  <th><TranslatedText>{t("Lớp phụ trách")}</TranslatedText></th>
                  <th><TranslatedText>{t("Trạng thái")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {rows.map(item => {
                  const isSelected = selectedGroup?.sessionId === item.sessionId;
                  const itemStatus = getStatusInfo(item.opens, item.closes);
                  return (
                    <TableRow
                      key={item.sessionId}
                      selected={isSelected}
                      onClick={() => setSelectedSessionId(item.sessionId)}
                    >
                      <td>
                        <button
                          type="button"
                          className="teacher-table-link"
                          aria-current={isSelected ? 'true' : undefined}
                          onClick={() => setSelectedSessionId(item.sessionId)}
                        >
                          <strong>{item.title}</strong>
                        </button>
                      </td>
                      <td>{date(item.opens)}</td>
                      <td>{date(item.closes)}</td>
                      <td>
                        <span title={item.rooms.map(r => r.room).join(', ')}>
                          {item.rooms.length > 1
                            ? `${item.rooms.length} ${t("lớp")}: ${item.rooms.map(r => r.room).join(', ')}`
                            : item.rooms[0]?.room || t("Chưa phân lớp")}
                        </span>
                      </td>
                      <td>
                        <span className={`proctor-status-pill ${itemStatus.className}`}>
                          {itemStatus.label}
                        </span>
                      </td>
                    </TableRow>
                  );
                })}
                {!rows.length && (
                  <tr>
                    <td colSpan={5} role="status">
                      <TranslatedText>
                        {loading
                          ? t('Đang tải danh sách kỳ thi…')
                          : sessions.length
                          ? t('Không có kỳ thi nào khớp với bộ lọc.')
                          : t('Không có phân công giám sát thi trong giai đoạn này.')}
                      </TranslatedText>
                    </td>
                  </tr>
                )}
              </tbody>
            </Table>
          </div>
          <TeacherListPagination
            page={currentPage}
            pageCount={pageCount}
            onPageChange={next => {
              setPage(next);
              setSelectedSessionId('');
            }}
            summary={<>{visible.length ? start + 1 : 0}–{start + rows.length} / {visible.length} <TranslatedText>{t('Kỳ thi')}</TranslatedText></>}
            paginationLabel={t('Kỳ thi')}
            pageIndicator={<>{currentPage} / {pageCount}</>}
            previousLabel={<TranslatedText>{t('← Trước')}</TranslatedText>}
            nextLabel={<TranslatedText>{t('Tiếp →')}</TranslatedText>}
          />
        </div>

        {/* Right side panel: Only auxiliary summary information & "Chi tiết kỳ thi" button */}
        <div className="teacher-filter-side">
          <div ref={setActionsTarget} className="teacher-filter-actions" />
          <aside className="teacher-list-detail-panel proctor-room-details">
            {selectedGroup ? (
              <>
                <span className="teacher-detail-eyebrow">
                  <TranslatedText>{t('Thông tin kỳ thi')}</TranslatedText>
                </span>
                <h2>{selectedGroup.title}</h2>
                <p className="muted">
                  <Clock size={13} style={{verticalAlign: 'middle', marginRight: 4}} />
                  {date(selectedGroup.opens)} – {date(selectedGroup.closes)}
                </p>

                <div className="proctor-status-indicator">
                  {(() => {
                    const st = getStatusInfo(selectedGroup.opens, selectedGroup.closes);
                    return <span className={`proctor-status-pill ${st.className}`}>{st.label}</span>;
                  })()}
                </div>

                <div className="proctor-summary-classes">
                  <div className="proctor-summary-classes-header">
                    <Users size={15} />
                    <strong><TranslatedText>{t("Các lớp phụ trách")}</TranslatedText></strong>
                    <span className="proctor-class-count">({selectedGroup.rooms.length} {t("lớp")})</span>
                  </div>
                  <div className="proctor-class-tag-list">
                    {selectedGroup.rooms.map(r => (
                      <div key={r.roomId} className="proctor-class-tag">
                        <span className="proctor-class-dot" />
                        <span>{r.room}</span>
                      </div>
                    ))}
                    {!selectedGroup.rooms.length && (
                      <p className="muted" style={{fontSize: 12}}>{t("Chưa có lớp nào được gán.")}</p>
                    )}
                  </div>
                </div>

                <div className="proctor-summary-actions">
                  <button
                    type="button"
                    className="button primary full-width proctor-open-dashboard-btn"
                    onClick={() => navigate(`${basePath}/${selectedGroup.sessionId}`)}
                  >
                    <Eye size={16} />
                    <span><TranslatedText>{t("Chi tiết kỳ thi")}</TranslatedText></span>
                  </button>
                </div>
              </>
            ) : null}
          </aside>
        </div>
      </div>
    </section>
  );
}

export type {StudentCheckResult};

function formatSessionTime(opens: string, closes: string) {
  const start = parseServerDateTime(opens);
  const end = parseServerDateTime(closes);
  const pad = (n: number) => n.toString().padStart(2, '0');
  const startTime = `${pad(start.getHours())}:${pad(start.getMinutes())}`;
  const endTime = `${pad(end.getHours())}:${pad(end.getMinutes())}`;
  const dateStr = `${pad(start.getDate())}/${pad(start.getMonth() + 1)}/${start.getFullYear()}`;
  return `${startTime} – ${endTime} · ${dateStr}`;
}

/**
 * Detailed Proctoring Dashboard (Màn hình chính theo dõi kỳ thi)
 * Left column: 3 Tabs (Tất cả / Thông báo / Cảnh báo) Activity Feed with "Mở cho SV làm bài"
 * Right column: Class Filter Tabs, Add Student by MSSV box, Search & Candidate Roster
 */
export function ProctorExamDashboard({
  exam,
  onBack,
}: {
  exam: ExamGroup;
  onBack: () => void;
}) {
  const {t, locale} = useLanguage();
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedRoomId, setSelectedRoomId] = useState<string>("all");
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'not_started' | 'submitted' | 'locked'>('all');
  const [search, setSearch] = useState("");
  const [unlockingId, setUnlockingId] = useState<string | null>(null);
  const [approvingBrowserId, setApprovingBrowserId] = useState<string | null>(null);
  const [newStudentCode, setNewStudentCode] = useState("");
  const [checkBusy, setCheckBusy] = useState(false);
  const [checkResult, setCheckResult] = useState<StudentCheckResult | null>(null);
  const [checkError, setCheckError] = useState("");
  const [addBusy, setAddBusy] = useState(false);
  const [isAddOpen, setIsAddOpen] = useState(false);
  const [addMessage, setAddMessage] = useState("");
  const [version, setVersion] = useState(0);

  // 3-Tab Feed state: "all" | "notices" | "warnings"
  const [feedTab, setFeedTab] = useState<'all' | 'notices' | 'warnings'>('all');
  const [notices, setNotices] = useState<ProctorNotice[]>([]);
  const isAddStudentExpanded = isAddOpen || Boolean(checkResult) || Boolean(checkError) || checkBusy;

  const date = (value: string) => parseServerDateTime(value).toLocaleString(locale);

  // Poll candidate roster
  useEffect(() => {
    let active = true;
    let running = false;
    const load = async () => {
      if (running) return;
      running = true;
      try {
        const [data, noticeRows] = await Promise.all([
          apiFetch<Candidate[]>(`/api/proctor/sessions/${exam.sessionId}`),
          // The roster is the essential part; a failed notice request only keeps the previous list.
          apiFetch<ProctorNotice[]>(`/api/proctor/sessions/${exam.sessionId}/notices`).catch(() => null),
        ]);
        if (active) {
          setCandidates(data);
          if (noticeRows) setNotices(noticeRows);
          setError("");
        }
      } catch (e) {
        if (active) {
          setError(e instanceof Error ? e.message : t("Không thể tải danh sách thí sinh."));
        }
      } finally {
        if (active) setLoading(false);
        running = false;
      }
    };
    void load();
    const timer = setInterval(() => void load(), 3000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [exam.sessionId, version]);

  // Map roomId to room code/name
  const roomMap = useMemo(() => {
    const map = new Map<string, string>();
    for (const r of exam.rooms) {
      map.set(r.roomId, r.room);
    }
    return map;
  }, [exam.rooms]);

  const getCandidateRoomName = (c: Candidate) => {
    if (!c.roomId || c.roomId === 'direct') return t("Thí sinh tự do");
    return roomMap.get(c.roomId) || c.roomId;
  };

  // Unlock student (Mở cho SV làm bài)
  const handleUnlock = async (studentId: string, studentName?: string) => {
    setUnlockingId(studentId);
    setError("");
    const targetStudent = candidates.find(c => c.studentId === studentId);
    const displayName = studentName || targetStudent?.name || studentId;
    const studentCode = targetStudent?.studentCode;
    const roomName = targetStudent ? getCandidateRoomName(targetStudent) : "";

    try {
      await apiFetch(`/api/proctor/sessions/${exam.sessionId}/students/${studentId}/unlock`, {
        method: 'POST',
      });

      toast.show({
        title: t("Đã mở bài thi thành công"),
        description: t("Thí sinh {name} có thể tiếp tục làm bài.", {name: displayName}),
        variant: "success",
      });
      setVersion(v => v + 1);
    } catch (e) {
      const msg = e instanceof Error ? e.message : t("Không thể mở khóa bài thi cho thí sinh.");
      setError(msg);
      toast.show({
        title: t("Mở bài thi thất bại"),
        description: msg,
        variant: "error",
      });
    } finally {
      setUnlockingId(null);
    }
  };

  // Approve pending browser session for duplicate/device-switch login
  const handleApproveBrowser = async (
    admissionId: string,
    browserId: string,
    browserCode: string,
    studentName?: string
  ) => {
    const reason = window.prompt(
      t("Nhập lý do duyệt đổi thiết bị/trình duyệt ({code}) cho {name}:", {
        code: browserCode,
        name: studentName || "",
      }),
      t("Xác nhận thí sinh đổi máy tại phòng thi")
    )?.trim();
    if (!reason) return;

    setApprovingBrowserId(browserId);
    setError("");
    try {
      await apiFetch(`/api/proctor/credentials/${encodeURIComponent(admissionId)}/approve`, {
        method: 'POST',
        body: JSON.stringify({ browser_id: browserId, reason }),
      });
      toast.show({
        title: t("Đã duyệt trình duyệt thành công"),
        description: t("Phiên {code} của thí sinh {name} đã được kích hoạt.", {
          code: browserCode,
          name: studentName || "",
        }),
        variant: "success",
      });
      setVersion(v => v + 1);
    } catch (e) {
      const msg = e instanceof Error ? e.message : t("Không thể duyệt phiên trình duyệt.");
      setError(msg);
      toast.show({
        title: t("Duyệt trình duyệt thất bại"),
        description: msg,
        variant: "error",
      });
    } finally {
      setApprovingBrowserId(null);
    }
  };

  // Check student eligibility by MSSV
  const handleCheckStudent = async () => {
    const code = newStudentCode.trim();
    if (!code) return;
    setCheckBusy(true);
    setCheckError("");
    setCheckResult(null);
    try {
      const res = await apiFetch<StudentCheckResponse>(`/api/proctor/sessions/${exam.sessionId}/students/check`, {
        method: 'POST',
        body: JSON.stringify({ student_code: code }),
      });
      setCheckResult(toStudentCheckResult(res));
    } catch (e) {
      const msg = e instanceof Error ? e.message : t("Không thể kiểm tra thông tin sinh viên.");
      setCheckError(msg);
    } finally {
      setCheckBusy(false);
    }
  };

  // Confirm add or transfer student to session
  const handleConfirmAddStudent = async () => {
    if (!checkResult?.student?.studentCode) return;
    setAddBusy(true);
    const code = checkResult.student.studentCode;
    try {
      const res = await apiFetch<{ok: boolean; student?: StudentCheckResponse; credentials?: unknown}>(
        `/api/proctor/sessions/${exam.sessionId}/students/add`,
        {
          method: 'POST',
          body: JSON.stringify({ student_code: code }),
        }
      );
      const successMsg = t("Đã thêm sinh viên vào bài thi thành công.");

      toast.show({
        title: t("Bổ sung thí sinh thành công"),
        description: successMsg,
        variant: "success",
      });

      setNewStudentCode("");
      setCheckResult(null);
      setCheckError("");
      setIsAddOpen(false);
      setVersion(v => v + 1);
    } catch (e) {
      const err = e instanceof Error ? e.message : t("Không thể thêm sinh viên vào ca thi.");
      setCheckError(err);
      toast.show({
        title: t("Thêm thí sinh thất bại"),
        description: err,
        variant: "error",
      });
    } finally {
      setAddBusy(false);
    }
  };

  // Filter candidates for right column
  const filteredCandidates = useMemo(() => {
    return candidates.filter(c => {
      if (selectedRoomId !== 'all') {
        if (c.roomId !== selectedRoomId) return false;
      }
      if (statusFilter === 'active' && c.attemptStatus !== 'active' && c.attemptStatus !== 'in_progress') return false;
      if (statusFilter === 'not_started' && c.attemptStatus !== 'not_started') return false;
      if (statusFilter === 'submitted' && c.attemptStatus !== 'submitted') return false;
      if (statusFilter === 'locked' && !(c.locked || c.isViolationLocked || c.finishReason === 'violations')) return false;

      if (search.trim()) {
        const query = search.trim().toLowerCase();
        const codeMatch = c.studentCode ? c.studentCode.toLowerCase().includes(query) : false;
        const nameMatch = c.name.toLowerCase().includes(query);
        const emailMatch = c.email.toLowerCase().includes(query);
        if (!codeMatch && !nameMatch && !emailMatch) return false;
      }
      return true;
    });
  }, [candidates, selectedRoomId, statusFilter, search]);

  // Cảnh báo (Warnings): candidates with lockout, active violations, or duplicate browser issues
  const warnings = useMemo(() => {
    return candidates.filter(c =>
      c.isViolationLocked ||
      c.isDuplicateLocked ||
      c.locked ||
      (c.violationCount && c.violationCount > 0) ||
      c.finishReason === 'violations'
    ).sort((a, b) => {
      const scoreA = (a.isViolationLocked ? 4 : 0) + (a.locked ? 2 : 0) + (a.violationCount || 0);
      const scoreB = (b.isViolationLocked ? 4 : 0) + (b.locked ? 2 : 0) + (b.violationCount || 0);
      return scoreB - scoreA;
    });
  }, [candidates]);

  // Thông báo (Notices): proctor actions saved on the server, so they survive a reload and are shared by every proctor.
  const noticeTitle = (notice: ProctorNotice) =>
    notice.kind === 'unlock' ? t("Mở bài thi thành công")
    : notice.kind === 'add_student' ? t("Thêm thí sinh vào ca thi")
    : notice.kind === 'transfer_student' ? t("Chuyển ca thi thí sinh")
    : t("Phê duyệt phiên đăng nhập");
  const noticeMessage = (notice: ProctorNotice) =>
    notice.kind === 'unlock' ? t("Đã mở bài thi thành công. Thí sinh có thể tiếp tục làm bài.")
    : notice.kind === 'add_student' ? t("Đã thêm sinh viên vào bài thi thành công.")
    : notice.kind === 'transfer_student' ? t("Đã chuyển sinh viên sang ca thi này.")
    : t("Đã phê duyệt phiên trình duyệt {code} cho thí sinh.", {code: notice.detail || ""});
  const noticeRoomName = (notice: ProctorNotice) => {
    const candidate = candidates.find(c => c.studentId === notice.studentId);
    return candidate ? getCandidateRoomName(candidate) : "";
  };

  // Active items for the selected feed tab
  const totalWarningsCount = warnings.length;
  const totalNoticesCount = notices.length;
  const totalFeedCount = totalWarningsCount + totalNoticesCount;

  // Statistics
  const activeCount = candidates.filter(c => c.attemptStatus === 'active' || c.attemptStatus === 'in_progress').length;
  const notStartedCount = candidates.filter(c => c.attemptStatus === 'not_started').length;
  const submittedCount = candidates.filter(c => c.attemptStatus === 'submitted').length;
  const lockedCount = candidates.filter(c => c.locked || c.isViolationLocked).length;

  return (
    <div className="proctor-dashboard-wrap">
      {error && <p role="alert" style={{color: 'var(--color-danger, #ef4444)'}}>{error}</p>}

      {/* 2-Column Main Dashboard Grid */}
      <div className="proctor-dashboard-grid">
        {/* LEFT COLUMN: Header Info + 3-Tab Activity Feed */}
        <div className="proctor-incidents-column">
          <div className="proctor-panel-card">
            {/* Integrated Session Header */}
            <div className="proctor-side-header">
              <div className="proctor-side-header-top">
                <button type="button" className="proctor-back-link" onClick={onBack}>
                  <ArrowLeft size={13} />
                  <span><TranslatedText>{t("Danh sách kỳ thi")}</TranslatedText></span>
                </button>
              </div>
              <h2 className="proctor-side-title">{exam.title}</h2>
              <div className="proctor-side-time">
                {formatSessionTime(exam.opens, exam.closes)}
              </div>
            </div>

            <div className="proctor-panel-card-header">
              <h3>
                <TranslatedText>{t("Bảng theo dõi & Sự cố")}</TranslatedText>
              </h3>
              {warnings.length > 0 && (
                <span className="proctor-incident-count-badge">
                  {warnings.length} <TranslatedText>{t("cần xử lý")}</TranslatedText>
                </span>
              )}
            </div>

            {/* 3 Tabs: Tất cả / Thông báo / Cảnh báo */}
            <div className="proctor-feed-tabs">
              <button
                type="button"
                className={`proctor-feed-tab ${feedTab === 'all' ? 'active' : ''}`}
                onClick={() => setFeedTab('all')}
              >
                <TranslatedText>{t("Tất cả")}</TranslatedText> ({totalFeedCount})
              </button>
              <button
                type="button"
                className={`proctor-feed-tab ${feedTab === 'notices' ? 'active' : ''}`}
                onClick={() => setFeedTab('notices')}
              >
                <TranslatedText>{t("Thông báo")}</TranslatedText> ({totalNoticesCount})
              </button>
              <button
                type="button"
                className={`proctor-feed-tab ${feedTab === 'warnings' ? 'active' : ''}`}
                onClick={() => setFeedTab('warnings')}
              >
                <TranslatedText>{t("Cảnh báo")}</TranslatedText>
                {totalWarningsCount > 0 && (
                  <span className="proctor-tab-badge danger">{totalWarningsCount}</span>
                )}
              </button>
            </div>

            {/* Feed List Items */}
            <div className="proctor-incident-list">
              {/* Warnings (Cảnh báo vi phạm & cấm thi) */}
              {(feedTab === 'all' || feedTab === 'warnings') &&
                warnings.map(person => {
                  const roomName = getCandidateRoomName(person);
                  const isViolationLocked = person.isViolationLocked || person.finishReason === 'violations';
                  const isWarning = !isViolationLocked && Boolean(person.violationCount && person.violationCount > 0);
                  const pendingBrowsers = (person.browsers || []).filter(b => b.status === 'pending');

                  return (
                    <div
                      key={`warn-${person.studentId}`}
                      className={`proctor-incident-item ${
                        isViolationLocked
                          ? 'violation-locked'
                          : isWarning
                          ? 'violation-warning'
                          : 'duplicate-locked'
                      }`}
                    >
                      <div className="proctor-incident-item-header">
                        <div>
                          <h4 className="proctor-incident-user">
                            {person.name}{' '}
                            {person.studentCode && (
                              <span className="proctor-student-code">({person.studentCode})</span>
                            )}
                          </h4>
                          <p className="proctor-incident-meta">
                            {roomName} · {person.email}
                          </p>
                        </div>

                        {isViolationLocked ? (
                          <span className="proctor-incident-type-badge danger">
                            <TranslatedText>{t("Bị cấm thi")}</TranslatedText>
                          </span>
                        ) : isWarning ? (
                          <span className="proctor-incident-type-badge warning">
                            <TranslatedText>{t("Cảnh báo vi phạm")}</TranslatedText>
                          </span>
                        ) : (
                          <span className="proctor-incident-type-badge info">
                            <TranslatedText>{t("Đổi thiết bị / Trình duyệt")}</TranslatedText>
                          </span>
                        )}
                      </div>

                      <p className="proctor-incident-message">
                        {isViolationLocked ? (
                          <TranslatedText>
                            {t("Thí sinh bị cấm thi do vi phạm quy chế ({value0}/{value1} lần: Chuyển tab hoặc rời toàn màn hình). Bài thi đã tự động khóa.", {
                              value0: String(person.violationCount ?? person.violationLimit ?? 2),
                              value1: String(person.violationLimit ?? 2),
                            })}
                          </TranslatedText>
                        ) : isWarning ? (
                          <TranslatedText>
                            {t("Thí sinh vừa vi phạm ở chế độ thi ({value0}/{value1} lần: Rời màn hình làm bài).", {
                              value0: String(person.violationCount ?? 1),
                              value1: String(person.violationLimit ?? 2),
                            })}
                          </TranslatedText>
                        ) : (
                          <TranslatedText>
                            {t("Sinh viên đăng nhập từ thiết bị/trình duyệt mới. Nếu là sự cố đổi máy, kiểm tra mã hiển thị trên máy SV và bấm duyệt bên dưới.")}
                            {pendingBrowsers.length > 0 && ` (${t("Mã chờ duyệt")}: ${pendingBrowsers.map(b => b.code).join(", ")})`}
                          </TranslatedText>
                        )}
                      </p>

                      <div className="proctor-incident-footer">
                        <span className="proctor-incident-time">
                          {person.incidentAt ? date(person.incidentAt) : t("Vừa ghi nhận")}
                        </span>

                        <div className="proctor-incident-actions">
                          {person.admissionId && pendingBrowsers.map(browser => (
                            <button
                              key={browser.id}
                              type="button"
                              className="button primary compact proctor-feed-action-btn"
                              disabled={approvingBrowserId === browser.id}
                              onClick={() => void handleApproveBrowser(person.admissionId!, browser.id, browser.code, person.name)}
                            >
                              <span>
                                <TranslatedText>
                                  {approvingBrowserId === browser.id
                                    ? t("Đang duyệt…")
                                    : t("Duyệt máy ({code})", { code: browser.code })}
                                </TranslatedText>
                              </span>
                            </button>
                          ))}
                          {/* Primary Action Button: "Mở cho SV làm bài" */}
                          {(isViolationLocked || isWarning || pendingBrowsers.length === 0) && (
                            <button
                              type="button"
                              className="button primary compact proctor-feed-action-btn"
                              disabled={unlockingId === person.studentId}
                              onClick={() => void handleUnlock(person.studentId, person.name)}
                            >
                              <span>
                                <TranslatedText>
                                  {unlockingId === person.studentId ? t("Đang mở…") : t("Mở cho SV làm bài")}
                                </TranslatedText>
                              </span>
                            </button>
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })}

              {/* Notices (Thông báo: mở bài thi, thêm/chuyển thí sinh, phê duyệt phiên) */}
              {(feedTab === 'all' || feedTab === 'notices') &&
                notices.map(notice => (
                  <div key={notice.id} className="proctor-incident-item notice-item">
                    <div className="proctor-incident-item-header">
                      <div>
                        <h4 className="proctor-incident-user">
                          {notice.studentName || notice.student || notice.studentId}{' '}
                          {notice.studentCode && (
                            <span className="proctor-student-code">({notice.studentCode})</span>
                          )}
                        </h4>
                        <p className="proctor-incident-meta">
                          {noticeRoomName(notice)}
                        </p>
                      </div>

                      <span className="proctor-incident-type-badge success">
                        <TranslatedText>{noticeTitle(notice)}</TranslatedText>
                      </span>
                    </div>

                    <p className="proctor-incident-message">{noticeMessage(notice)}</p>

                    <div className="proctor-incident-footer">
                      <span className="proctor-incident-time">
                        {date(notice.createdAt)}
                        {(notice.actorName || notice.actor) ? ` · ${notice.actorName || notice.actor}` : ""}
                      </span>
                    </div>
                  </div>
                ))}

              {/* Empty States */}
              {feedTab === 'warnings' && warnings.length === 0 && !loading && (
                <div className="proctor-empty-incidents compact">
                  <h4><TranslatedText>{t("Chưa có cảnh báo")}</TranslatedText></h4>
                  <p><TranslatedText>{t("Chưa ghi nhận sự cố cần xử lý.")}</TranslatedText></p>
                </div>
              )}

              {feedTab === 'notices' && notices.length === 0 && !loading && (
                <div className="proctor-empty-incidents compact">
                  <h4><TranslatedText>{t("Chưa có thông báo")}</TranslatedText></h4>
                  <p><TranslatedText>{t("Các thao tác mở bài thi, thêm thí sinh và phê duyệt phiên sẽ được ghi nhận tại đây.")}</TranslatedText></p>
                </div>
              )}

              {feedTab === 'all' && totalFeedCount === 0 && !loading && (
                <div className="proctor-empty-incidents compact">
                  <h4><TranslatedText>{t("Chưa có hoạt động nào")}</TranslatedText></h4>
                  <p><TranslatedText>{t("Các thông báo và cảnh báo trong ca thi sẽ xuất hiện tại đây.")}</TranslatedText></p>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* RIGHT COLUMN: Add Student by MSSV, Class Tabs & Candidates Roster */}
        <div className="proctor-roster-column">
          <div className="proctor-panel-card">
            {/* "Bổ sung thí sinh vào ca thi" expandable box */}
            <div className={`proctor-add-student-box ${isAddStudentExpanded ? 'expanded' : 'collapsed'}`}>
              <div
                className="proctor-add-student-toggle-bar"
                role="button"
                tabIndex={0}
                onClick={() => setIsAddOpen(!isAddStudentExpanded)}
                onKeyDown={e => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    setIsAddOpen(!isAddStudentExpanded);
                  }
                }}
              >
                <div className="proctor-add-student-toggle-title">
                  <UserPlus size={14} />
                  <span><TranslatedText>{t("Bổ sung thí sinh vào ca thi")}</TranslatedText></span>
                </div>
                <button
                  type="button"
                  className="button secondary compact proctor-add-toggle-btn"
                  onClick={e => {
                    e.stopPropagation();
                    setIsAddOpen(!isAddStudentExpanded);
                  }}
                >
                  <TranslatedText>{isAddStudentExpanded ? t("Thu gọn") : t("+ Thêm thí sinh")}</TranslatedText>
                </button>
              </div>

              {isAddStudentExpanded && (
                <div className="proctor-add-student-body">
                  <form
                    className="proctor-add-student-row"
                    onSubmit={e => {
                      e.preventDefault();
                      void handleCheckStudent();
                    }}
                  >
                    <input
                      id="proctor-dashboard-student-code"
                      type="text"
                      placeholder={t("Nhập MSSV sinh viên…")}
                      value={newStudentCode}
                      onChange={e => {
                        setNewStudentCode(e.target.value);
                        if (checkResult || checkError) {
                          setCheckResult(null);
                          setCheckError("");
                        }
                      }}
                      disabled={checkBusy || addBusy}
                      autoFocus
                    />
                    <button
                      type="submit"
                      className="button primary compact"
                      disabled={checkBusy || addBusy || !newStudentCode.trim()}
                    >
                      <TranslatedText>{checkBusy ? t("Đang kiểm tra…") : t("Kiểm tra")}</TranslatedText>
                    </button>
                  </form>

                  {checkError && (
                    <div className="proctor-check-error" role="alert">
                      {checkError}
                    </div>
                  )}

                  {checkResult && (
                    <div className={`proctor-check-result-card ${checkResult.eligible ? 'eligible' : 'ineligible'}`}>
                      {checkResult.student ? (
                        <div className="proctor-check-result-body">
                          <div className="proctor-check-result-header">
                            <div>
                              <div className="proctor-check-name">{checkResult.student.name}</div>
                              <div className="proctor-check-meta">
                                <span>MSSV: <strong>{checkResult.student.studentCode}</strong></span>
                                {checkResult.student.email && <span> · {checkResult.student.email}</span>}
                                {checkResult.student.className && (
                                  <span> · Lớp: <strong>{checkResult.student.className.replace(/^Lớp\s+/i, '')}</strong></span>
                                )}
                              </div>
                            </div>
                            <span className={`proctor-check-status-badge ${checkResult.eligible ? 'success' : 'danger'}`}>
                              {checkResult.eligible
                                ? (checkResult.canTransfer ? t("Có thể chuyển ca") : t("Hợp lệ"))
                                : t("Không hợp lệ")}
                            </span>
                          </div>

                          {checkResult.previousSession && (
                            <div className="proctor-check-prev-session">
                              <span className="proctor-check-prev-label">{t("Thông tin ca thi trước:")}</span>{' '}
                              <strong>{checkResult.previousSession.title}</strong>
                              {' '}({checkResult.previousSession.status === 'absent'
                                ? t("Vắng thi / Chưa bắt đầu")
                                : checkResult.previousSession.status === 'submitted'
                                ? t("Đã nộp bài")
                                : checkResult.previousSession.status === 'started'
                                ? t("Đang / Đã làm bài")
                                : checkResult.previousSession.status})
                            </div>
                          )}

                          <div className="proctor-check-reason">
                            {checkResult.reason}
                          </div>

                          <div className="proctor-check-result-actions">
                            <button
                              type="button"
                              className="button secondary compact"
                              onClick={() => {
                                setCheckResult(null);
                                setCheckError("");
                                setIsAddOpen(false);
                              }}
                              disabled={addBusy}
                            >
                              <TranslatedText>{t("Hủy")}</TranslatedText>
                            </button>
                            {checkResult.eligible && (
                              <button
                                type="button"
                                className="button primary compact"
                                onClick={() => void handleConfirmAddStudent()}
                                disabled={addBusy}
                              >
                                <TranslatedText>
                                  {addBusy
                                    ? t("Đang thêm…")
                                    : checkResult.canTransfer
                                    ? t("Chuyển vào ca thi")
                                    : t("Thêm vào ca thi")}
                                </TranslatedText>
                              </button>
                            )}
                          </div>
                        </div>
                      ) : (
                        <div className="proctor-check-result-body">
                          <div className="proctor-check-reason">{checkResult.reason || t("Không tìm thấy sinh viên.")}</div>
                          <div className="proctor-check-result-actions">
                            <button
                              type="button"
                              className="button secondary compact"
                              onClick={() => {
                                setCheckResult(null);
                                setCheckError("");
                                setIsAddOpen(false);
                              }}
                            >
                              <TranslatedText>{t("Đóng")}</TranslatedText>
                            </button>
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* Class Selector Tabs */}
            <div className="proctor-class-tabs">
              <button
                type="button"
                className={`proctor-class-tab ${selectedRoomId === 'all' ? 'active' : ''}`}
                onClick={() => setSelectedRoomId('all')}
              >
                <TranslatedText>{t("Tất cả")}</TranslatedText> ({candidates.length})
              </button>
              {exam.rooms.map(r => {
                const count = candidates.filter(c => c.roomId === r.roomId).length;
                const cleanRoomName = r.room.replace(/^Lớp\s+/i, '');
                return (
                  <button
                    key={r.roomId}
                    type="button"
                    className={`proctor-class-tab ${selectedRoomId === r.roomId ? 'active' : ''}`}
                    onClick={() => setSelectedRoomId(r.roomId)}
                  >
                    {cleanRoomName} ({count})
                  </button>
                );
              })}
            </div>

            {/* Quick Stats Chips (clickable to filter candidate list) */}
            <div className="proctor-roster-stats">
              <button
                type="button"
                className={`proctor-stat-chip ${statusFilter === 'all' ? 'active' : ''}`}
                onClick={() => setStatusFilter('all')}
                title={t("Hiển thị tất cả thí sinh")}
              >
                <TranslatedText>{t("Tổng")}</TranslatedText>: <strong>{candidates.length}</strong>
              </button>
              <button
                type="button"
                className={`proctor-stat-chip ${statusFilter === 'active' ? 'active' : ''}`}
                onClick={() => setStatusFilter(prev => prev === 'active' ? 'all' : 'active')}
                title={t("Lọc thí sinh đang làm")}
              >
                <TranslatedText>{t("Đang làm")}</TranslatedText>: <strong style={{color: '#2563eb'}}>{activeCount}</strong>
              </button>
              <button
                type="button"
                className={`proctor-stat-chip ${statusFilter === 'not_started' ? 'active' : ''}`}
                onClick={() => setStatusFilter(prev => prev === 'not_started' ? 'all' : 'not_started')}
                title={t("Lọc thí sinh chưa bắt đầu")}
              >
                <TranslatedText>{t("Chưa bắt đầu")}</TranslatedText>: <strong>{notStartedCount}</strong>
              </button>
              <button
                type="button"
                className={`proctor-stat-chip ${statusFilter === 'submitted' ? 'active' : ''}`}
                onClick={() => setStatusFilter(prev => prev === 'submitted' ? 'all' : 'submitted')}
                title={t("Lọc thí sinh đã nộp")}
              >
                <TranslatedText>{t("Đã nộp")}</TranslatedText>: <strong style={{color: '#16a34a'}}>{submittedCount}</strong>
              </button>
              {lockedCount > 0 && (
                <button
                  type="button"
                  className={`proctor-stat-chip ${statusFilter === 'locked' ? 'active' : ''}`}
                  onClick={() => setStatusFilter(prev => prev === 'locked' ? 'all' : 'locked')}
                  title={t("Lọc thí sinh bị khóa hoặc cấm thi")}
                >
                  <TranslatedText>{t("Bị khóa / Cấm")}</TranslatedText>: <strong style={{color: '#dc2626'}}>{lockedCount}</strong>
                </button>
              )}
            </div>

            {/* Search Input */}
            <div className="proctor-search-row">
              <Search size={15} className="proctor-search-icon" />
              <input
                type="text"
                placeholder={t("Tìm kiếm theo MSSV hoặc họ tên sinh viên…")}
                value={search}
                onChange={e => setSearch(e.target.value)}
              />
            </div>

            {/* Students Table */}
            <div className="proctor-candidate-table-wrap">
              <Table className="teacher-table">
                <thead>
                  <tr>
                    <th>MSSV</th>
                    <th><TranslatedText>{t("Họ và tên")}</TranslatedText></th>
                    {selectedRoomId === 'all' && <th><TranslatedText>{t("Lớp")}</TranslatedText></th>}
                    <th><TranslatedText>{t("Trạng thái")}</TranslatedText></th>
                    <th><TranslatedText>{t("Vi phạm")}</TranslatedText></th>
                    <th><TranslatedText>{t("Thao tác")}</TranslatedText></th>
                  </tr>
                </thead>
                <tbody>
                  {filteredCandidates.map(person => {
                    const isViolationLocked = person.isViolationLocked || person.finishReason === 'violations';
                    const hasViolation = Boolean(person.violationCount && person.violationCount > 0);
                    const pendingBrowsers = (person.browsers || []).filter(b => b.status === 'pending');
                    const canUnlock = person.locked || isViolationLocked || hasViolation;

                    return (
                      <TableRow key={person.studentId} selected={false}>
                        <td>
                          <strong>{person.studentCode || (person.email ? person.email.split('@')[0] : '') || person.studentId}</strong>
                        </td>
                        <td>
                          <div>
                            <span>{person.name}</span>
                            <small className="muted" style={{display: 'block', fontSize: 11}}>
                              {person.email}
                            </small>
                          </div>
                        </td>
                        {selectedRoomId === 'all' && (
                          <td>
                            <small>{getCandidateRoomName(person).replace(/^Lớp\s+/i, '')}</small>
                          </td>
                        )}
                        <td>
                          {isViolationLocked ? (
                            <span className="proctor-incident-type-badge danger">
                              <TranslatedText>{t("Bị cấm thi")}</TranslatedText>
                            </span>
                          ) : person.locked ? (
                            <span className="proctor-incident-type-badge danger">
                              <TranslatedText>{t("Bị khóa")}</TranslatedText>
                            </span>
                          ) : person.attemptStatus === 'active' || person.attemptStatus === 'in_progress' ? (
                            <span className="proctor-status-pill active">
                              <TranslatedText>{t("Đang làm bài")}</TranslatedText>
                            </span>
                          ) : person.attemptStatus === 'submitted' ? (
                            <span className="proctor-status-pill finished">
                              <TranslatedText>{t("Đã nộp bài")}</TranslatedText>
                            </span>
                          ) : (
                            <span className="muted" style={{fontSize: 12}}>
                              <TranslatedText>{t("Chưa bắt đầu")}</TranslatedText>
                            </span>
                          )}
                        </td>
                        <td>
                          {person.violationCount && person.violationCount > 0 ? (
                            <span
                              className={`proctor-incident-type-badge ${isViolationLocked ? 'danger' : 'warning'}`}
                              title={`${person.violationCount}/${person.violationLimit ?? 2} vi phạm`}
                            >
                              {person.violationCount}/{person.violationLimit ?? 2}
                            </span>
                          ) : (
                            <span className="muted" style={{fontSize: 12}}>0</span>
                          )}
                        </td>
                        <td>
                          {canUnlock || (person.admissionId && pendingBrowsers.length > 0) ? (
                            <div style={{display: 'flex', flexWrap: 'wrap', gap: 6}}>
                              {person.admissionId && pendingBrowsers.map(browser => (
                                <button
                                  key={browser.id}
                                  type="button"
                                  className="button primary compact proctor-feed-action-btn"
                                  disabled={approvingBrowserId === browser.id}
                                  onClick={() => void handleApproveBrowser(person.admissionId!, browser.id, browser.code, person.name)}
                                  title={t("Duyệt phiên trình duyệt {code}", {code: browser.code})}
                                >
                                  <span>{approvingBrowserId === browser.id ? t("Đang duyệt…") : t("Duyệt máy ({code})", {code: browser.code})}</span>
                                </button>
                              ))}
                              {canUnlock && (isViolationLocked || hasViolation || pendingBrowsers.length === 0) && (
                                <button
                                  type="button"
                                  className="button primary compact proctor-feed-action-btn"
                                  disabled={unlockingId === person.studentId}
                                  onClick={() => void handleUnlock(person.studentId, person.name)}
                                  title={t("Mở cho SV làm bài")}
                                >
                                  <span>{unlockingId === person.studentId ? t("Đang mở…") : t("Mở làm bài")}</span>
                                </button>
                              )}
                            </div>
                          ) : (
                            <span className="muted" style={{fontSize: 12}}>—</span>
                          )}
                        </td>
                      </TableRow>
                    );
                  })}
                  {!filteredCandidates.length && (
                    <tr>
                      <td colSpan={selectedRoomId === 'all' ? 6 : 5} role="status">
                        <TranslatedText>
                          {loading
                            ? t('Đang tải danh sách thí sinh…')
                            : search.trim()
                            ? t('Không tìm thấy thí sinh nào khớp với từ khóa.')
                            : t('Chưa có thí sinh nào trong lớp này.')}
                        </TranslatedText>
                      </td>
                    </tr>
                  )}
                </tbody>
              </Table>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
