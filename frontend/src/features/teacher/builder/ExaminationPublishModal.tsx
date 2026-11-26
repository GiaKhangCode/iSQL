import { useMemo, useState, useEffect } from "react";
import { Dialog } from "@/shared/ui/ui";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { formatDisplayDate, sessionWeekday, type ExaminationPlan } from "@/data/examinations";
import { AlertTriangle, AlertCircle, CheckCircle2, Download, Archive } from "lucide-react";
import type { WorkspacePart } from "@/data/models";
import { WORKSPACE_PARTS, WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { questionFullText } from "@/shared/lib/workspace/questionLabel";
import { catalogOf, type QuestionOption } from "@/features/teacher/builder/BuilderProblemSettings";
import { selectedQuestions } from "@/features/teacher/builder/BuilderSelectedQuestions";
import { apiFetch } from "@/shared/api/apiClient";
import { storage } from "@/shared/api/storage";
import { notify } from "@/shared/ui/notify";

interface ExaminationPublishModalProps {
  examinationId?: string;
  plan: ExaminationPlan;
  draftProblems?: any[];
  allProblems: any[];
  problemDetails: Record<string, any>;
  isPublishing?: boolean;
  isAlreadyPublished?: boolean;
  availableClasses?: any[];
  examinationTitle?: string;
  onClose: () => void;
  onConfirmPublish?: () => void;
  onNavigateToList?: () => void;
}

export function ExaminationPublishModal({
  examinationId,
  plan,
  draftProblems,
  allProblems,
  problemDetails,
  isPublishing = false,
  isAlreadyPublished = false,
  availableClasses = [],
  examinationTitle = "",
  onClose,
  onConfirmPublish,
}: ExaminationPublishModalProps) {
  const [mainTab, setMainTab] = useState<"structure" | "accounts">("structure");
  const [sessionAccounts, setSessionAccounts] = useState<any>(null);
  const [loadingAccounts, setLoadingAccounts] = useState(false);
  const [accountsError, setAccountsError] = useState("");
  const [exportingZip, setExportingZip] = useState(false);

  // Extract unique exam dates
  const planSessions = plan?.sessions || [];
  const dates = useMemo(() => {
    const set = new Set<string>();
    for (const s of planSessions) {
      if (s.examDate) set.add(s.examDate);
      else if (plan?.examDate) set.add(plan.examDate);
    }
    return Array.from(set).sort();
  }, [planSessions, plan?.examDate]);

  const [activeDate, setActiveDate] = useState<string>(() => dates[0] || plan?.examDate || "");

  // Update activeDate if dates array changes
  useEffect(() => {
    if (dates.length > 0 && !dates.includes(activeDate)) {
      setActiveDate(dates[0]);
    }
  }, [dates, activeDate]);

  // Sessions for active date
  const sessionsForDate = useMemo(() => {
    return planSessions.filter(s => (s.examDate || plan?.examDate) === activeDate);
  }, [planSessions, plan?.examDate, activeDate]);

  const [activeSessionIndex, setActiveSessionIndex] = useState<number>(() => sessionsForDate[0]?.index ?? 1);

  // Sync activeSessionIndex when sessionsForDate changes
  useEffect(() => {
    if (sessionsForDate.length > 0) {
      if (!sessionsForDate.some(s => s.index === activeSessionIndex)) {
        setActiveSessionIndex(sessionsForDate[0].index);
      }
    }
  }, [sessionsForDate, activeSessionIndex]);

  const currentSession = useMemo(() => {
    return sessionsForDate.find(s => s.index === activeSessionIndex) || sessionsForDate[0];
  }, [sessionsForDate, activeSessionIndex]);

  // Overview metrics across all sessions
  const totalSessions = planSessions.length;
  const emptySessions = useMemo(() => {
    return planSessions.filter(s => !(s.problems && s.problems.length > 0));
  }, [planSessions]);
  const hasEmptySessions = emptySessions.length > 0;

  const assignedClassIds = useMemo(() => {
    return Array.from(new Set(planSessions.flatMap(s => s.classIds || [])));
  }, [planSessions]);

  const assignedClasses = useMemo(() => {
    return availableClasses.filter(c => assignedClassIds.includes(c.id));
  }, [availableClasses, assignedClassIds]);

  const proctorsOrLecturers = useMemo(() => {
    const names = new Set<string>();
    for (const s of planSessions) {
      if (s.proctors && s.proctors.length > 0) {
        s.proctors.forEach(p => names.add(p));
      }
    }
    for (const c of assignedClasses) {
      if (c.lecturer) names.add(c.lecturer);
    }
    return Array.from(names);
  }, [planSessions, assignedClasses]);

  const totalStudents = useMemo(() => {
    return assignedClasses.reduce((sum, c) => sum + (c.students ?? 0), 0);
  }, [assignedClasses]);

  // Check if a specific date has any empty sessions
  function dateHasEmpty(d: string): boolean {
    return plan.sessions.some(
      s => (s.examDate || plan.examDate) === d && !(s.problems && s.problems.length > 0)
    );
  }

  function handleConfirmClick() {
    if (hasEmptySessions) {
      const confirmed = window.confirm(
        `Còn ${emptySessions.length} ca thi chưa có bài thi nào. Bạn có chắc chắn muốn công bố kỳ thi không?`
      );
      if (!confirmed) return;
    }
    onConfirmPublish?.();
  }

  // Fetch student accounts for active session when on accounts tab
  useEffect(() => {
    if (mainTab !== "accounts") return;
    if (!examinationId) {
      setAccountsError("Kỳ thi chưa được lưu trên hệ thống. Vui lòng lưu hoặc công bố kỳ thi trước.");
      return;
    }
    const sessionId = currentSession?.id || (currentSession?.index != null ? String(currentSession.index) : "");
    if (!sessionId) {
      setSessionAccounts(null);
      return;
    }

    let active = true;
    setLoadingAccounts(true);
    setAccountsError("");
    apiFetch(`/api/examinations/${encodeURIComponent(examinationId)}/sessions/${encodeURIComponent(sessionId)}/student-accounts`)
      .then((data) => {
        if (!active) return;
        setSessionAccounts(data);
      })
      .catch((err) => {
        if (!active) return;
        setAccountsError(err instanceof Error ? err.message : "Không thể tải danh sách tài khoản");
      })
      .finally(() => {
        if (active) setLoadingAccounts(false);
      });

    return () => {
      active = false;
    };
  }, [mainTab, examinationId, currentSession?.id, currentSession?.index]);

  function handleExportSessionCsv() {
    if (!sessionAccounts?.students?.length) return;
    const session = sessionAccounts.session || {};
    const headers = ["STT", "MSSV", "Họ và tên", "Lớp", "Giáo viên", "Mã đề", "Tài khoản đăng nhập", "Mật khẩu", "Trạng thái"];
    const rows = sessionAccounts.students.map((s: any) => [
      s.stt,
      `"${(s.studentCode || "").replace(/"/g, '""')}"`,
      `"${(s.name || "").replace(/"/g, '""')}"`,
      `"${(s.className || "").replace(/"/g, '""')}"`,
      `"${(s.teacherName || "").replace(/"/g, '""')}"`,
      `"${(s.variantCode || "").replace(/"/g, '""')}"`,
      `"${(s.username || "").replace(/"/g, '""')}"`,
      `"${(s.password || "").replace(/"/g, '""')}"`,
      `"${(s.status || "").replace(/"/g, '""')}"`,
    ]);
    const csvContent = "\ufeff" + [headers.join(","), ...rows.map((r: (string | number)[]) => r.join(","))].join("\r\n");
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    const caNum = session.sessionIndex || currentSession?.sessionNumber || currentSession?.index || "Ca";
    const dateStr = session.examDate || activeDate || "";
    a.download = `TaiKhoan_Ca${caNum}_${dateStr}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    notify.success("Đã tải file tài khoản thi.");
  }

  async function handleExportZip() {
    if (!examinationId) return;
    try {
      setExportingZip(true);
      const token = storage.get("sql-practice:access-token");
      const headers: Record<string, string> = token ? { Authorization: `Bearer ${token}` } : {};
      const baseUrl = import.meta.env.VITE_API_URL || "";
      const res = await fetch(`${baseUrl}/api/examinations/${encodeURIComponent(examinationId)}/accounts-zip`, {
        headers,
        credentials: "include",
      });
      if (!res.ok) {
        const errorText = await res.text();
        throw new Error(errorText || "Lỗi khi xuất tệp ZIP.");
      }
      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `TaiKhoanThi_${examinationId}.zip`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
      notify.success("Đã tải tệp ZIP tài khoản thi.");
    } catch (e: any) {
      notify.fail("Không thể tải tệp ZIP.", e);
    } finally {
      setExportingZip(false);
    }
  }

  return (
    <Dialog
      title={isAlreadyPublished ? `Kỳ thi đã công bố: ${examinationTitle || "Kỳ thi"}` : "Xác nhận công bố kỳ thi"}
      onClose={onClose}
      className="builder-publish-dialog"
    >
      <div className="builder-publish-modal-body">
        {/* Top Summary Bar */}
        <div className="builder-publish-summary-bar">
          <div className="builder-publish-summary-left">
            {isAlreadyPublished ? (
              <span className="builder-publish-badge-published">
                <span>Đã công bố chính thức</span>
              </span>
            ) : null}
            <span className="builder-publish-summary-text">
              Tổng số: <strong>{totalSessions}</strong> ca thi ({dates.length} ngày)
            </span>

            {isAlreadyPublished && (
              <div className="examination-integration-pills">
                <div className="examination-integration-pill is-proctor" tabIndex={0} role="note" aria-label="Chi tiết Giám sát thi cho Giảng viên">
                  <span>Giám sát thi (GV)</span>
                  <div className="examination-integration-tooltip" role="tooltip">
                    <strong>Đã kích hoạt màn hình "Giám sát thi" cho Giảng viên</strong>
                    <p>
                      Hệ thống đã tự động cấp quyền và thêm tab <strong>Giám sát thi</strong> trên thanh điều hướng cho các Giảng viên hướng dẫn thực hành
                      {proctorsOrLecturers.length > 0 ? <> (<strong>{proctorsOrLecturers.join(", ")}</strong>)</> : null}{" "}
                      phụ trách {assignedClassIds.length > 0 ? <>lớp: <strong>{assignedClassIds.join(", ")}</strong></> : "kỳ thi"}.
                      Giảng viên có thể theo dõi trực tuyến thời gian thực, quản lý cảnh báo vi phạm và hỗ trợ mở khóa dự thi cho sinh viên.
                    </p>
                  </div>
                </div>

                <div className="examination-integration-pill is-student" tabIndex={0} role="note" aria-label="Chi tiết Kỳ thi cho Sinh viên">
                  <span>Kỳ thi SV ({totalStudents > 0 ? `${totalStudents} SV` : assignedClassIds.join(", ")})</span>
                  <div className="examination-integration-tooltip" role="tooltip">
                    <strong>Đã tích hợp kỳ thi vào mục "Kỳ thi" cho Sinh viên</strong>
                    <p>
                      Đề thi và ca thi đã được đồng bộ trực tiếp vào mục <strong>Kỳ thi</strong> của{" "}
                      {totalStudents > 0 ? <strong>{totalStudents} sinh viên thuộc </strong> : null}
                      các lớp: <strong>{assignedClassIds.join(", ")}</strong>. Sinh viên đăng nhập bằng tài khoản trường và vào làm bài thi theo đúng ca và khung giờ quy định.
                    </p>
                  </div>
                </div>
              </div>
            )}
          </div>

          <div className="builder-publish-summary-right">
            <div className="examination-modal-tab-indicator" role="tablist">
              <button
                type="button"
                role="tab"
                aria-selected={mainTab === "structure"}
                className={`examination-modal-tab-btn ${mainTab === "structure" ? "is-active" : ""}`}
                onClick={() => setMainTab("structure")}
              >
                Cấu trúc đề
              </button>
              <button
                type="button"
                role="tab"
                aria-selected={mainTab === "accounts"}
                className={`examination-modal-tab-btn ${mainTab === "accounts" ? "is-active" : ""}`}
                onClick={() => setMainTab("accounts")}
              >
                Tài khoản thi
              </button>
            </div>
          </div>
        </div>

        {totalSessions === 0 ? (
          <div className="builder-publish-empty-state">
            <p>Chưa có ca thi nào được tạo trong lịch thi.</p>
          </div>
        ) : (
          <>
            {/* Date Tabs */}
            <div className="builder-publish-date-tabs" role="tablist" aria-label="Ngày thi">
              {dates.map((d) => {
                const isSelected = activeDate === d;
                const isWarn = dateHasEmpty(d);
                const weekday = sessionWeekday(d, "vi");
                return (
                  <button
                    type="button"
                    key={d}
                    role="tab"
                    aria-selected={isSelected}
                    className={`builder-publish-date-tab${isSelected ? " is-active" : ""}${isWarn ? " has-warning" : ""}`}
                    onClick={() => setActiveDate(d)}
                  >
                    <span>{weekday}, {formatDisplayDate(d)}</span>
                    {isWarn && <span className="builder-publish-date-dot" title="Có ca thi chưa có bài thi" />}
                  </button>
                );
              })}
            </div>

            {/* Shift / Session Tabs for Active Date */}
            <div className="builder-publish-shift-tabs" role="tablist" aria-label="Ca thi trong ngày">
              {sessionsForDate.map((s) => {
                const isSelected = currentSession?.index === s.index;
                const hasProblems = !!(s.problems && s.problems.length > 0);
                const caNum = s.sessionNumber || ((s.index - 1) % 4) + 1;

                return (
                  <button
                    type="button"
                    key={s.index}
                    role="tab"
                    aria-selected={isSelected}
                    className={`builder-publish-shift-tab${isSelected ? " is-active" : ""}${!hasProblems ? " is-empty" : ""}`}
                    onClick={() => setActiveSessionIndex(s.index)}
                  >
                    <div className="builder-publish-shift-title">
                      <span>Ca {caNum}</span>
                      <small>({s.starts} - {s.ends})</small>
                    </div>
                    {!hasProblems ? (
                      <span className="builder-publish-empty-badge">Chưa có đề</span>
                    ) : (
                      <span className="builder-publish-ok-badge">{s.problems?.length} bài</span>
                    )}
                  </button>
                );
              })}
            </div>

            {/* Tab 1: Exam Structure */}
            {mainTab === "structure" && (
              <div className="builder-publish-session-container">
                {currentSession ? (
                  <>
                    <div className="builder-publish-problem-list">
                      {!(currentSession.problems && currentSession.problems.length > 0) ? (
                        <div className="builder-publish-empty-alert">
                          <AlertCircle size={15} aria-hidden="true" />
                          <div>
                            <strong>Ca thi này chưa có bài thi!</strong>
                            <p>Chưa có bài tập nào được gán cho ca này. Ca thi sẽ bị báo đỏ cho đến khi được gán đề.</p>
                          </div>
                        </div>
                      ) : (
                        currentSession.problems.map((p: any, idx: number) => {
                          const detail = problemDetails[p.id];
                          const summary = allProblems.find((item: any) => item.id === p.id);

                          return (
                            <PublishProblemCard
                              key={`${currentSession.index}-${p.id || idx}`}
                              problemItem={p}
                              draftProblems={draftProblems}
                              index={idx}
                              detail={detail}
                              summary={summary}
                            />
                          );
                        })
                      )}
                    </div>
                  </>
                ) : (
                  <div className="builder-publish-empty-state">
                    <p>Không có ca thi nào vào ngày đã chọn.</p>
                  </div>
                )}
              </div>
            )}

            {/* Tab 2: Student Exam Accounts */}
            {mainTab === "accounts" && (
              <div className="examination-accounts-container">
                <div className="examination-account-table-toolbar">
                  <div className="examination-account-summary">
                    <span>Tổng số sinh viên: <strong>{sessionAccounts?.students?.length ?? 0}</strong></span>
                  </div>
                  <div className="examination-account-export-actions">
                    {sessionAccounts?.students?.length ? (
                      <button
                        type="button"
                        className="button subtle teacher-small-button examination-export-csv-btn"
                        onClick={handleExportSessionCsv}
                        title="Tải về file CSV danh sách tài khoản cho ca thi này"
                      >
                        <Download size={13} aria-hidden="true" />
                        <span>Xuất CSV (Ca {currentSession?.sessionNumber || currentSession?.index || 1})</span>
                      </button>
                    ) : null}
                    {examinationId ? (
                      <button
                        type="button"
                        className="button subtle teacher-small-button examination-export-zip-btn"
                        onClick={handleExportZip}
                        disabled={exportingZip}
                        title="Tải về file ZIP chứa danh sách tài khoản CSV của tất cả các ca thi"
                      >
                        <Archive size={13} aria-hidden="true" />
                        <span>{exportingZip ? "Đang xuất ZIP…" : "Xuất ZIP (toàn bộ ca)"}</span>
                      </button>
                    ) : null}
                  </div>
                </div>

                {loadingAccounts ? (
                  <div className="examination-accounts-loading">
                    <span>Đang tải danh sách tài khoản thi…</span>
                  </div>
                ) : accountsError ? (
                  <div className="builder-publish-empty-alert">
                    <AlertCircle size={15} aria-hidden="true" />
                    <div>
                      <strong>Thông báo</strong>
                      <p>{accountsError}</p>
                    </div>
                  </div>
                ) : !sessionAccounts?.students?.length ? (
                  <div className="builder-publish-empty-state">
                    <p>Ca thi này chưa có sinh viên hoặc chưa phân lớp.</p>
                  </div>
                ) : (
                  <div className="examination-accounts-table-container">
                    <table className="examination-accounts-table">
                      <thead>
                        <tr>
                          <th style={{ width: 40, textAlign: "center" }}>STT</th>
                          <th style={{ width: 95 }}>MSSV</th>
                          <th>Họ và tên</th>
                          <th style={{ width: 100 }}>Lớp</th>
                          <th style={{ width: 130 }}>Giáo viên</th>
                          <th style={{ width: 75, textAlign: "center" }}>Mã đề</th>
                          <th style={{ width: 135 }}>Tài khoản đăng nhập</th>
                          <th style={{ width: 110 }}>Mật khẩu</th>
                          <th style={{ width: 105, textAlign: "center" }}>Trạng thái</th>
                        </tr>
                      </thead>
                      <tbody>
                        {sessionAccounts.students.map((student: any) => (
                          <tr key={student.studentId || student.username}>
                            <td style={{ textAlign: "center" }} className="cell-stt">{student.stt}</td>
                            <td className="cell-code">{student.studentCode}</td>
                            <td className="cell-name">{student.name}</td>
                            <td className="cell-class">{student.className}</td>
                            <td className="cell-teacher">{student.teacherName || "—"}</td>
                            <td style={{ textAlign: "center" }}>
                              <span className="examination-variant-badge">{student.variantCode}</span>
                            </td>
                            <td className="cell-username">{student.username}</td>
                            <td className="cell-password">{student.password}</td>
                            <td style={{ textAlign: "center" }}>
                              <span className={`examination-status-badge ${
                                student.status === "Đã nộp bài"
                                  ? "is-submitted"
                                  : student.status === "Đang làm bài"
                                  ? "is-active"
                                  : "is-pending"
                              }`}>
                                {student.status}
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {/* Footer Actions */}
        <div className="builder-publish-dialog-footer">
          <div className="builder-publish-footer-status">
            {!isAlreadyPublished && hasEmptySessions ? (
              <span className="builder-publish-warn-status">
                <AlertTriangle size={13} aria-hidden="true" />
                <span>Có {emptySessions.length} ca thi chưa có đề thi!</span>
              </span>
            ) : !isAlreadyPublished ? (
              <span className="builder-publish-ok-status">
                <CheckCircle2 size={13} aria-hidden="true" />
                <span>Tất cả các ca thi đã sẵn sàng.</span>
              </span>
            ) : null}
          </div>
          <div className="builder-publish-footer-actions">
            {isAlreadyPublished ? (
              <>
                <button
                  type="button"
                  className="button subtle teacher-small-button"
                  onClick={onClose}
                  disabled={isPublishing}
                >
                  <TranslatedText>Đóng</TranslatedText>
                </button>
                <button
                  type="button"
                  className="button primary teacher-small-button"
                  onClick={() => {
                    if (onConfirmPublish) {
                      onConfirmPublish();
                    } else {
                      handleConfirmClick();
                    }
                  }}
                  disabled={isPublishing || hasEmptySessions}
                >
                  <TranslatedText>{isPublishing ? "Đang công bố lại…" : "Công bố lại"}</TranslatedText>
                </button>
              </>
            ) : (
              <button
                type="button"
                className={`button teacher-small-button ${hasEmptySessions ? "subtle" : "primary"}`}
                onClick={handleConfirmClick}
                disabled={isPublishing || totalSessions === 0}
              >
                <TranslatedText>{isPublishing ? "Đang công bố..." : "Xác nhận công bố"}</TranslatedText>
              </button>
            )}
          </div>
        </div>
      </div>
    </Dialog>
  );
}

function PublishProblemCard({
  problemItem,
  draftProblems,
  index,
  detail,
  summary,
}: {
  problemItem: any;
  draftProblems?: any[];
  index: number;
  detail: any;
  summary: any;
}) {
  const title = summary?.title || detail?.title || `Bài tập ${index + 1}`;
  const catalog = useMemo(() => catalogOf(detail || null), [detail]);

  const draftProblem = draftProblems?.find((p: any) => p.id === problemItem.id);
  const resolvedSelection = problemItem.questionSelection || draftProblem?.questionSelection || {};

  // Variants (if generated)
  const variants: any[] = useMemo(() => {
    return (
      resolvedSelection._variants ||
      resolvedSelection.variants ||
      problemItem.variants ||
      draftProblem?.variants ||
      []
    );
  }, [resolvedSelection, problemItem.variants, draftProblem?.variants]);

  const [activeVariantCode, setActiveVariantCode] = useState<string>(() => {
    return variants[0]?.code || "";
  });

  // Keep activeVariantCode valid if variants change
  useEffect(() => {
    if (variants.length > 0 && !variants.some((v: any) => v.code === activeVariantCode)) {
      setActiveVariantCode(variants[0].code);
    }
  }, [variants, activeVariantCode]);

  // Questions mapped by workspace part
  const questionsByPart = useMemo(() => {
    const res: Record<WorkspacePart, QuestionOption[]> = {
      DDL: [],
      DML: [],
      SQL: [],
      TRIGGER: [],
    };

    if (variants.length > 0) {
      const selectedVariant = variants.find((v: any) => v.code === activeVariantCode) || variants[0];
      for (const part of WORKSPACE_PARTS) {
        const qIds = selectedVariant?.questions?.[part] || [];
        const partCatalog = catalog[part] || [];
        res[part] = qIds
          .map((id: string) => partCatalog.find(q => q.id === id))
          .filter(Boolean) as QuestionOption[];
      }
    } else {
      const selected = selectedQuestions(
        detail || null,
        problemItem.workspaceParts || draftProblem?.workspaceParts || WORKSPACE_PARTS,
        resolvedSelection
      );
      for (const part of WORKSPACE_PARTS) {
        res[part] = selected[part] || [];
      }
    }
    return res;
  }, [variants, activeVariantCode, catalog, detail, problemItem.workspaceParts, draftProblem?.workspaceParts, resolvedSelection]);

  // Active question-type tab: DDL, DML, DQL (SQL), Trigger
  const availableParts = useMemo(() => {
    return WORKSPACE_PARTS.filter(p => (questionsByPart[p]?.length || 0) > 0);
  }, [questionsByPart]);

  const [activePart, setActivePart] = useState<WorkspacePart>(() => availableParts[0] || "SQL");

  useEffect(() => {
    if (availableParts.length > 0 && !availableParts.includes(activePart)) {
      setActivePart(availableParts[0]);
    }
  }, [availableParts, activePart]);

  const activeQuestions = questionsByPart[activePart] || [];

  const sharedIds = useMemo(() => {
    const raw = resolvedSelection._sharedQuestions || resolvedSelection.sharedQuestions;
    if (!raw) return new Set<string>();
    if (Array.isArray(raw)) return new Set<string>(raw);
    if (typeof raw === "object") {
      const allIds = Object.values(raw).flatMap((val: any) => Array.isArray(val) ? val : []);
      return new Set<string>(allIds);
    }
    return new Set<string>();
  }, [resolvedSelection]);

  function isShared(qId: string): boolean {
    return sharedIds.has(qId);
  }

  return (
    <div className="builder-publish-problem-card">
      <div className="builder-publish-problem-header">
        <div className="builder-publish-problem-title">
          <span className="builder-publish-problem-idx">{String(index + 1).padStart(2, "0")}.</span>
          <strong title={title}>{title}</strong>
        </div>

        {/* Variant Tabs Bar */}
        <div className="builder-publish-variant-bar">
          <span className="builder-publish-variant-label">MÃ ĐỀ:</span>
          {variants.length > 0 ? (
            <>
              {variants.map((v: any) => (
                <button
                  type="button"
                  key={v.code}
                  className={`builder-publish-variant-pill${activeVariantCode === v.code ? " is-active" : ""}`}
                  onClick={() => setActiveVariantCode(v.code)}
                >
                  {v.code}
                </button>
              ))}
              <span className="builder-publish-variant-note">({variants.length} mã đề)</span>
            </>
          ) : (
            <>
              <button
                type="button"
                className="builder-publish-variant-pill is-active"
              >
                001
              </button>
              <span className="builder-publish-variant-note">(1 mã đề · Đề cố định)</span>
            </>
          )}
        </div>
      </div>

      {/* The 4 Question Type Tabs: DDL, DML, DQL, Trigger */}
      <div className="builder-publish-part-tabs" role="tablist" aria-label="Loại câu hỏi">
        {WORKSPACE_PARTS.map((partKey) => {
          const count = questionsByPart[partKey]?.length || 0;
          const isSelected = activePart === partKey;
          const isEnabled = (problemItem.workspaceParts || WORKSPACE_PARTS).includes(partKey);

          return (
            <button
              key={partKey}
              type="button"
              role="tab"
              aria-selected={isSelected}
              disabled={!isEnabled && count === 0}
              className={`builder-publish-part-tab${isSelected ? " is-active" : ""}${count === 0 ? " is-zero" : ""}`}
              onClick={() => setActivePart(partKey)}
            >
              <span>{WORKSPACE_PART_LABELS[partKey]}</span>
              <span className="builder-publish-part-count">{count}</span>
            </button>
          );
        })}
      </div>

      {/* Question List for the Selected Part */}
      <div className="builder-publish-q-list" role="tabpanel">
        {activeQuestions.map((q, qIdx) => {
          const shared = isShared(q.id);
          return (
            <div key={q.id || qIdx} className="builder-publish-q-row">
              <div className="builder-publish-q-header">
                <span className="builder-publish-q-index">{String(qIdx + 1).padStart(2, "0")}.</span>
                <span className="builder-publish-q-name">{questionFullText(q.description) || `Câu ${qIdx + 1}`}</span>
                {q.difficulty && (
                  <span className="builder-q-tag difficulty">{q.difficulty}</span>
                )}
                {q.category && (
                  <span className="builder-publish-q-cat">{q.category}</span>
                )}
                {shared && (
                  <span className="builder-q-tag shared">Câu chung</span>
                )}
              </div>
            </div>
          );
        })}

        {activeQuestions.length === 0 && (
          <div className="builder-publish-q-empty">
            <span>Không có câu hỏi {WORKSPACE_PART_LABELS[activePart]} nào trong cấu hình này.</span>
          </div>
        )}
      </div>
    </div>
  );
}
