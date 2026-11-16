import { useState, useEffect, useRef, useMemo } from "react";
import { Plus, X, Settings, Settings2, Calendar, Clock, ArrowUp, ArrowDown, Trash2, CircleHelp, AlertTriangle, ChevronDown } from "lucide-react";
import { BuilderSelectedQuestions, selectedQuestions } from "@/features/teacher/builder/BuilderSelectedQuestions";
import { BuilderInlineSettings } from "@/features/teacher/builder/BuilderInlineSettings";
import { useLanguage } from "@/shared/context/LanguageContext";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import {
  DEFAULT_SESSION_HOURS,
  formatDisplayDate,
  type ExamSessionPlan,
  type ExaminationPlan,
  nextExamDate,
} from "@/data/examinations";
import { ClassPicker } from "@/features/teacher/components/TeacherPageParts";
import { StudentSearchPicker } from "@/shared/components/StudentSearchPicker";
import { ClassProctorPicker } from "@/shared/components/ClassProctorPicker";
import type { ProctorOption } from "@/shared/api/examSessions";
import type { WorkspacePart } from "@/data/models";
import type { BuilderProblem } from "@/features/teacher/builder/BuilderShared";
import { WORKSPACE_PARTS } from "@/shared/lib/workspace/workspaceParts";
import "@/styles/examinations.css";

export type ExaminationSessionPlannerProps = {
  value: ExaminationPlan;
  onChange: (plan: ExaminationPlan) => void;
  examinationTitle?: string;
  initialSession?: number;
  onRefresh?: (() => void) | (() => Promise<void>);
  refreshing?: boolean;
  availableClasses?: any[];
  /** Lecturer accounts that can be chosen as proctors of a class. */
  proctorOptions?: ProctorOption[];
  proctorsLoading?: boolean;
  proctorsError?: string | null;
  classesLoading?: boolean;
  classesError?: string | null;
  allProblems?: any[];
  problemsLoading?: boolean;
  problemsError?: string | null;
  onOpenProblemLibrary?: (sessionIndex: number) => void;
  onOpenProblemSettings?: (problemId: string, sessionIndex: number) => void;
  settingsProblemId?: string | null;
  problemDetails?: Record<string, any>;
  catalogErrors?: Record<string, string>;
  onRetryCatalog?: () => void;
  errorTarget?: {
    sessionIndex?: number;
    field?: "classes" | "room" | "proctors" | "times" | "session";
    message: string;
    conflictStudents?: Array<{ id: string; name: string; email: string; student_id?: string }>;
  } | null;
  onClearError?: () => void;
};

type ModalSlotState = {
  slotNum: number;
  label: string;
  enabled: boolean;
  starts: string;
  ends: string;
};

function DayDateInput({
  value,
  onChange,
}: {
  value: string;
  onChange: (val: string) => void;
}) {
  const calendarRef = useRef<HTMLInputElement>(null);
  const [day, setDay] = useState(() => value ? value.slice(8, 10) : "");
  const [month, setMonth] = useState(() => value ? value.slice(5, 7) : "");
  const [year, setYear] = useState(() => value ? value.slice(0, 4) : "");

  useEffect(() => {
    if (value) {
      setDay(value.slice(8, 10));
      setMonth(value.slice(5, 7));
      setYear(value.slice(0, 4));
    }
  }, [value]);

  function commit(d: string, m: string, y: string) {
    if (d && m && y && d.length === 2 && m.length === 2 && y.length === 4) {
      const dayNum = Number(d);
      const monthNum = Number(m);
      const yearNum = Number(y);
      if (monthNum >= 1 && monthNum <= 12 && dayNum >= 1 && dayNum <= 31 && yearNum >= 2000 && yearNum <= 2100) {
        onChange(`${yearNum.toString().padStart(4, "0")}-${monthNum.toString().padStart(2, "0")}-${dayNum.toString().padStart(2, "0")}`);
      }
    }
  }

  function handleSegmentChange(part: "day" | "month" | "year", val: string) {
    const digits = val.replace(/\D/g, "").slice(0, part === "year" ? 4 : 2);
    if (part === "day") {
      setDay(digits);
      commit(digits, month, year);
    } else if (part === "month") {
      setMonth(digits);
      commit(day, digits, year);
    } else {
      setYear(digits);
      commit(day, month, digits);
    }
  }

  function handleBlur(part: "day" | "month") {
    const val = part === "day" ? day : month;
    if (val && val.length === 1) {
      const padded = val.padStart(2, "0");
      if (part === "day") {
        setDay(padded);
        commit(padded, month, year);
      } else {
        setMonth(padded);
        commit(day, padded, year);
      }
    }
  }

  return (
    <div className="examination-day-date-input">
      <div className="examination-day-date-segments">
        <input
          type="text"
          inputMode="numeric"
          maxLength={2}
          placeholder="DD"
          value={day}
          aria-label="Ngày"
          onChange={(e) => handleSegmentChange("day", e.target.value)}
          onBlur={() => handleBlur("day")}
        />
        <span>/</span>
        <input
          type="text"
          inputMode="numeric"
          maxLength={2}
          placeholder="MM"
          value={month}
          aria-label="Tháng"
          onChange={(e) => handleSegmentChange("month", e.target.value)}
          onBlur={() => handleBlur("month")}
        />
        <span>/</span>
        <input
          type="text"
          inputMode="numeric"
          maxLength={4}
          placeholder="YYYY"
          value={year}
          aria-label="Năm"
          onChange={(e) => handleSegmentChange("year", e.target.value)}
        />
      </div>
      <button
        type="button"
        className="examination-day-date-picker-btn"
        aria-label="Chọn ngày từ lịch"
        title="Chọn ngày từ lịch"
        onClick={() => {
          const input = calendarRef.current as any;
          if (input?.showPicker) input.showPicker();
          else input?.focus();
        }}
      >
        <Calendar size={16} />
      </button>
      <input
        ref={calendarRef}
        type="date"
        className="examination-hidden-calendar-input"
        value={value}
        onChange={(e) => {
          if (e.target.value) {
            onChange(e.target.value);
          }
        }}
        tabIndex={-1}
        aria-hidden="true"
      />
    </div>
  );
}

export function ExaminationSessionPlanner({
  value,
  onChange,
  examinationTitle = "",
  initialSession,
  onRefresh,
  refreshing = false,
  availableClasses = [],
  proctorOptions = [],
  proctorsLoading = false,
  proctorsError = null,
  classesLoading = false,
  classesError = null,
  allProblems = [],
  problemsLoading = false,
  problemsError = null,
  onOpenProblemLibrary,
  onOpenProblemSettings,
  settingsProblemId = null,
  problemDetails = {},
  catalogErrors = {},
  onRetryCatalog,
  errorTarget = null,
  onClearError,
}: ExaminationSessionPlannerProps) {
  const { t } = useLanguage("teacher");

  // Dates list
  const dates = useMemo(() => {
    const set = new Set<string>();
    for (const s of value.sessions) {
      if (s.examDate) set.add(s.examDate);
      else if (value.examDate) set.add(value.examDate);
    }
    return Array.from(set).sort();
  }, [value.sessions, value.examDate]);

  // Active date and session
  const [activeDate, setActiveDate] = useState<string>(() => dates[0] || value.examDate || "");
  const [activeSessionIndex, setActiveSessionIndex] = useState<number>(() => {
    if (initialSession && value.sessions.some(s => s.index === initialSession)) return initialSession;
    return value.sessions[0]?.index ?? 1;
  });

  // Handle errorTarget: switch date/session and auto-scroll to error element
  useEffect(() => {
    if (!errorTarget) return;

    if (errorTarget.sessionIndex) {
      const targetSession = value.sessions.find(s => s.index === errorTarget.sessionIndex);
      if (targetSession) {
        const sessionDate = targetSession.examDate || value.examDate || "";
        if (sessionDate && activeDate !== sessionDate) {
          setActiveDate(sessionDate);
        }
        if (activeSessionIndex !== targetSession.index) {
          setActiveSessionIndex(targetSession.index);
        }
      }
    }

    const timer = setTimeout(() => {
      const el =
        document.querySelector('.is-conflict-student') ||
        document.querySelector('.examination-section-error-badge') ||
        document.querySelector('.examination-session-times-group.has-error') ||
        document.querySelector('.examination-slot-tab.has-error') ||
        document.querySelector('.examination-session-detail-card.has-error');
      if (el) {
        el.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }, 120);

    return () => clearTimeout(timer);
  }, [errorTarget]);

  // Ensure activeDate and activeSessionIndex stay valid when sessions change
  useEffect(() => {
    if (dates.length > 0 && !dates.includes(activeDate)) {
      setActiveDate(dates[0]);
    }
  }, [dates, activeDate]);

  const daySessions = useMemo(() => {
    return value.sessions.filter(s => (s.examDate || value.examDate) === activeDate);
  }, [value.sessions, value.examDate, activeDate]);

  useEffect(() => {
    if (daySessions.length > 0) {
      if (!daySessions.some(s => s.index === activeSessionIndex)) {
        setActiveSessionIndex(daySessions[0].index);
      }
    }
  }, [daySessions, activeSessionIndex]);

  const currentSession = useMemo(() => {
    return value.sessions.find(s => s.index === activeSessionIndex) || daySessions[0];
  }, [value.sessions, activeSessionIndex, daySessions]);

  // Context menu on date tabs
  const [menuDate, setMenuDate] = useState<string | null>(null);
  const contextMenuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (contextMenuRef.current && !contextMenuRef.current.contains(e.target as Node)) {
        setMenuDate(null);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  // Modal State for Add Date / Config Slots
  const [modalMode, setModalMode] = useState<"add" | "config" | null>(null);
  const [configSourceDate, setConfigSourceDate] = useState<string | null>(null);
  const [modalDate, setModalDate] = useState<string>("");
  const [modalSlots, setModalSlots] = useState<ModalSlotState[]>([]);
  const [deleteConfirmDate, setDeleteConfirmDate] = useState<string | null>(null);

  function openAddDateModal() {
    const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
    const nextDate = dates.length > 0 ? nextExamDate(dates[dates.length - 1], 1) : nextExamDate(today, 1);
    setConfigSourceDate(null);
    setModalDate(nextDate);
    setModalSlots(
      DEFAULT_SESSION_HOURS.map(slot => ({
        ...slot,
        enabled: slot.slotNum === 1,
      }))
    );
    setModalMode("add");
    setMenuDate(null);
  }

  function openConfigModal(date: string) {
    setConfigSourceDate(date);
    setModalDate(date);
    const existingForDate = value.sessions.filter(s => (s.examDate || value.examDate) === date);
    setModalSlots(
      DEFAULT_SESSION_HOURS.map(defaultSlot => {
        const found = existingForDate.find(s => {
          if (s.sessionNumber) return s.sessionNumber === defaultSlot.slotNum;
          if (defaultSlot.slotNum === 1 && s.starts <= "08:30") return true;
          if (defaultSlot.slotNum === 2 && s.starts > "08:30" && s.starts <= "12:00") return true;
          if (defaultSlot.slotNum === 3 && s.starts > "12:00" && s.starts <= "14:30") return true;
          if (defaultSlot.slotNum === 4 && s.starts > "14:30") return true;
          return false;
        });
        return {
          slotNum: defaultSlot.slotNum,
          label: defaultSlot.label,
          enabled: Boolean(found),
          starts: found ? found.starts : defaultSlot.starts,
          ends: found ? found.ends : defaultSlot.ends,
        };
      })
    );
    setModalMode("config");
    setMenuDate(null);
  }

  function handleSaveModal() {
    if (!modalDate) {
      alert("Vui lòng chọn ngày thi.");
      return;
    }
    const enabledSlots = modalSlots.filter(s => s.enabled);
    if (!enabledSlots.length) {
      alert("Vui lòng tích chọn ít nhất 1 ca thi cho ngày này.");
      return;
    }

    if (modalMode === "add") {
      if (dates.includes(modalDate)) {
        alert(`Ngày ${formatDisplayDate(modalDate)} đã có trong lịch thi. Vui lòng chọn ngày khác hoặc cấu hình trực tiếp ngày đó.`);
        return;
      }
    } else if (modalMode === "config" && configSourceDate) {
      if (modalDate !== configSourceDate && dates.includes(modalDate)) {
        alert(`Ngày ${formatDisplayDate(modalDate)} đã tồn tại trong lịch thi. Vui lòng chọn ngày khác để đổi ngày.`);
        return;
      }
    }

    // When configuring an existing date, the source date being modified is configSourceDate
    const targetSourceDate = modalMode === "config" && configSourceDate ? configSourceDate : modalDate;

    // Existing sessions belonging to the day being configured (or modalDate if adding)
    const existingOnDate = value.sessions.filter(s => (s.examDate || value.examDate) === targetSourceDate);
    const sessionsOtherDays = value.sessions.filter(s => (s.examDate || value.examDate) !== targetSourceDate);

    const oldDateDisplay = configSourceDate ? formatDisplayDate(configSourceDate) : "";
    const newDateDisplay = formatDisplayDate(modalDate);

    const updatedDateSessions: ExamSessionPlan[] = enabledSlots.map(slot => {
      const match = existingOnDate.find(s => {
        if (s.sessionNumber) return s.sessionNumber === slot.slotNum;
        if (slot.slotNum === 1 && s.starts <= "08:30") return true;
        if (slot.slotNum === 2 && s.starts > "08:30" && s.starts <= "12:00") return true;
        if (slot.slotNum === 3 && s.starts > "12:00" && s.starts <= "14:30") return true;
        if (slot.slotNum === 4 && s.starts > "14:30") return true;
        return ((s.index - 1) % 4) + 1 === slot.slotNum;
      });
      const defaultTitle = `${examinationTitle || "Thi cuối kỳ"} - Ca ${slot.slotNum} - ${newDateDisplay}`;
      if (match) {
        let updatedTitle = match.title || defaultTitle;
        if (configSourceDate && configSourceDate !== modalDate && oldDateDisplay && updatedTitle.includes(oldDateDisplay)) {
          updatedTitle = updatedTitle.replace(oldDateDisplay, newDateDisplay);
        } else if (!match.title) {
          updatedTitle = defaultTitle;
        }

        return {
          ...match,
          sessionNumber: slot.slotNum,
          examDate: modalDate,
          starts: slot.starts,
          ends: slot.ends,
          title: updatedTitle,
        };
      }
      return {
        id: null,
        index: 0,
        sessionNumber: slot.slotNum,
        examDate: modalDate,
        starts: slot.starts,
        ends: slot.ends,
        title: defaultTitle,
        classIds: [],
        studentEmails: [],
        problems: [],
        proctors: [],
        classProctors: {},
        room: "",
        capacity: null,
      };
    });

    try {
      const allSessions = [...sessionsOtherDays, ...updatedDateSessions];
      // Sort chronological: date then starts
      allSessions.sort((a, b) => {
        const dateA = a.examDate || modalDate;
        const dateB = b.examDate || modalDate;
        if (dateA !== dateB) return dateA.localeCompare(dateB);
        return a.starts.localeCompare(b.starts);
      });

      const reindexedSessions = allSessions.map((s, idx) => ({ ...s, index: idx + 1 }));
      setActiveDate(modalDate);
      const firstAdded = reindexedSessions.find(s => (s.examDate || modalDate) === modalDate);
      if (firstAdded) setActiveSessionIndex(firstAdded.index);

      onChange({
        ...value,
        examDate: reindexedSessions[0]?.examDate || modalDate,
        sessions: reindexedSessions,
      });
    } finally {
      setModalMode(null);
      setConfigSourceDate(null);
    }
  }

  function confirmDeleteDate(date: string) {
    setMenuDate(null);
    setDeleteConfirmDate(date);
  }

  function executeDeleteDate() {
    if (!deleteConfirmDate) return;
    const remaining = value.sessions.filter(s => (s.examDate || value.examDate) !== deleteConfirmDate);
    const reindexed = remaining.map((s, idx) => ({ ...s, index: idx + 1 }));
    onChange({
      ...value,
      examDate: reindexed[0]?.examDate || value.examDate,
      sessions: reindexed,
    });
    setDeleteConfirmDate(null);
  }

  function removeSingleSession(slot: ExamSessionPlan) {
    if (!window.confirm(`Bạn có chắc chắn muốn xóa Ca ${slot.sessionNumber || slot.index} (${slot.starts} - ${slot.ends})?`)) {
      return;
    }
    const remaining = value.sessions.filter(s => s !== slot);
    const reindexed = remaining.map((s, idx) => ({ ...s, index: idx + 1 }));
    onChange({
      ...value,
      sessions: reindexed,
    });
  }

  // Session update helper
  function updateCurrentSession(changes: Partial<ExamSessionPlan>) {
    if (!currentSession) return;
    onClearError?.();
    const updatedSessions = value.sessions.map(s => {
      if (s.index === currentSession.index) {
        return { ...s, ...changes };
      }
      return s;
    });
    onChange({ ...value, sessions: updatedSessions });
  }

  // Problem Library & individual mode for Current Session
  const [individualMode, setIndividualMode] = useState(false);
  const [selectedQuestionsId, setSelectedQuestionsId] = useState<string | null>(null);
  const [activeSettingsId, setActiveSettingsId] = useState<string | null>(null);

  const sessionProblems: BuilderProblem[] = (currentSession?.problems || []).map((p: any) => ({
    ...p,
    points: p.points ?? 10,
    workspaceParts: p.workspaceParts || [...WORKSPACE_PARTS],
  }));

  function updateSessionProblem(problemId: string, patch: Partial<BuilderProblem>) {
    const rawProblems = currentSession?.problems || [];
    const nextProblems = rawProblems.map((p: any) => p.id === problemId ? { ...p, ...patch } : p);
    updateCurrentSession({ problems: nextProblems });
  }

  function removeProblemFromSession(problemId: string) {
    const nextProblems = sessionProblems.filter(p => p.id !== problemId);
    updateCurrentSession({ problems: nextProblems });
  }

  function moveProblemInSession(index: number, direction: -1 | 1) {
    const next = [...sessionProblems];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    updateCurrentSession({ problems: next });
  }

  // Count total questions for equal scoring
  const totalQuestions = useMemo(() => {
    return sessionProblems.reduce((sum, item) => {
      const detail = problemDetails?.[item.id] || null;
      if (detail) {
        const counts = Object.values(selectedQuestions(detail, item.workspaceParts || [], item.questionSelection))
          .reduce((s, q) => s + q.length, 0);
        return sum + counts;
      }
      const p = allProblems.find(row => row.id === item.id);
      return sum + (p?.questions?.length || 1);
    }, 0);
  }, [sessionProblems, allProblems, problemDetails]);

  return (
    <section className="examination-planner-v2">
      {/* Header bar: [+ Thêm ngày thi] on the left, followed by Date tabs */}
      <div className="examination-header-bar">
        <div className="examination-date-tabs" role="tablist">
          <button className="button primary examination-add-date-btn" type="button" onClick={openAddDateModal}>
            <Plus size={13} aria-hidden="true" />
            <span>Thêm ngày thi</span>
          </button>

          {dates.map(date => {
            const isActive = date === activeDate;
            return (
              <div key={date} className={`examination-date-tab${isActive ? " is-active" : ""}`}>
                <button
                  type="button"
                  role="tab"
                  aria-selected={isActive}
                  className="examination-date-tab-btn"
                  onClick={() => setActiveDate(date)}
                >
                  <Calendar size={13} aria-hidden="true" />
                  <span>{formatDisplayDate(date)}</span>
                </button>
                <button
                  type="button"
                  className="examination-date-gear-btn"
                  aria-label={`Cài đặt ca thi ngày ${formatDisplayDate(date)}`}
                  title="Cài đặt ca thi"
                  onClick={(e) => {
                    e.stopPropagation();
                    setMenuDate(menuDate === date ? null : date);
                  }}
                >
                  <Settings size={13} aria-hidden="true" />
                </button>

                {/* Context Menu */}
                {menuDate === date && (
                  <div className="examination-context-menu" ref={contextMenuRef}>
                    <button
                      type="button"
                      className="examination-context-item"
                      onClick={() => openConfigModal(date)}
                    >
                      <Clock size={13} />
                      <span>Cấu hình ca thi</span>
                    </button>
                    <button
                      type="button"
                      className="examination-context-item is-danger"
                      onClick={() => confirmDeleteDate(date)}
                    >
                      <Trash2 size={13} />
                      <span>Xóa ngày này</span>
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Date Tabs content */}
      {dates.length === 0 ? (
        <div className="examination-empty-schedule">
          <Calendar size={28} className="muted" />
          <p>Chưa có ngày thi nào. Nhấn <b>+ Thêm ngày thi</b> để bắt đầu lên lịch.</p>
        </div>
      ) : (
        <>
          {/* Active Session Detail & Slot Tabs wrapped in one form card */}
          {currentSession && (
            <div
              className={`examination-session-detail-card${
                errorTarget?.sessionIndex === currentSession?.index &&
                (!errorTarget?.field || errorTarget?.field === "session")
                  ? " has-error"
                  : ""
              }`}
              data-tooltip={
                errorTarget?.sessionIndex === currentSession?.index &&
                (!errorTarget?.field || errorTarget?.field === "session")
                  ? errorTarget.message
                  : undefined
              }
              title={
                errorTarget?.sessionIndex === currentSession?.index &&
                (!errorTarget?.field || errorTarget?.field === "session")
                  ? errorTarget.message
                  : undefined
              }
            >
              {/* Session Tabs for Active Date */}
              <div className="examination-slot-tabs-bar">
                {daySessions.map(slot => {
                  const isActive = slot.index === currentSession?.index;
                  const isError = errorTarget?.sessionIndex === slot.index;
                  const slotLabel = `Ca ${slot.sessionNumber || ((slot.index - 1) % 4) + 1}`;
                  return (
                    <div
                      key={slot.index}
                      data-session-index={slot.index}
                      data-tooltip={isError ? errorTarget.message : undefined}
                      title={isError ? errorTarget.message : undefined}
                      className={`examination-slot-tab${isActive ? " is-active" : ""}${isError ? " has-error" : ""}`}
                    >
                      <button
                        type="button"
                        className="examination-slot-tab-btn"
                        onClick={() => {
                          onClearError?.();
                          setActiveSessionIndex(slot.index);
                        }}
                      >
                        <span className="examination-slot-tab-title">{slotLabel}</span>
                        <span className="examination-slot-tab-time">{slot.starts}–{slot.ends}</span>
                      </button>
                      <button
                        type="button"
                        className="examination-slot-tab-remove"
                        title={`Xóa ${slotLabel}`}
                        aria-label={`Xóa ${slotLabel}`}
                        onClick={(e) => {
                          e.stopPropagation();
                          removeSingleSession(slot);
                        }}
                      >
                        <X size={12} />
                      </button>
                    </div>
                  );
                })}
              </div>

              {/* Animated session content wrapper on tab switch */}
              <div key={currentSession.index} className="examination-session-content-animated">
                {/* Row 1: Session Title & Times */}
                <div className="examination-session-heading-fields">
                <div className="teacher-field examination-session-title-field">
                  <label htmlFor="exam-session-title">TÊN CA THI</label>
                  <input
                    id="exam-session-title"
                    value={
                      currentSession.title ??
                      `${examinationTitle || "Thi cuối kỳ"} - Ca ${currentSession.sessionNumber || 1} - ${formatDisplayDate(activeDate)}`
                    }
                    onChange={(e) => updateCurrentSession({ title: e.target.value })}
                    placeholder="Nhập tên ca thi"
                  />
                </div>
                <div
                  className={`examination-session-times-group${
                    errorTarget?.sessionIndex === currentSession?.index && errorTarget?.field === "times"
                      ? " has-error"
                      : ""
                  }`}
                  data-tooltip={
                    errorTarget?.sessionIndex === currentSession?.index && errorTarget?.field === "times"
                      ? errorTarget.message
                      : undefined
                  }
                  title={
                    errorTarget?.sessionIndex === currentSession?.index && errorTarget?.field === "times"
                      ? errorTarget.message
                      : undefined
                  }
                >
                  <div className="teacher-field">
                    <label htmlFor="exam-session-starts">GIỜ BẮT ĐẦU</label>
                    <input
                      id="exam-session-starts"
                      type="time"
                      value={currentSession.starts}
                      onChange={(e) => updateCurrentSession({ starts: e.target.value })}
                    />
                  </div>
                  <div className="teacher-field">
                    <label htmlFor="exam-session-ends">GIỜ KẾT THÚC</label>
                    <input
                      id="exam-session-ends"
                      type="time"
                      value={currentSession.ends}
                      onChange={(e) => updateCurrentSession({ ends: e.target.value })}
                    />
                  </div>
                </div>
              </div>

              {/* Row 2: Phân ca thi (Người tham gia) */}
              <div className="examination-session-section">
                <h4 className="examination-section-subtitle">
                  <span>Phân ca thi</span>
                  <small className="muted">Chỉ định các lớp hoặc sinh viên tham gia ca này</small>
                </h4>
                {errorTarget?.sessionIndex === currentSession?.index && errorTarget?.field === "classes" && (
                  <div className="examination-section-error-badge" role="alert">
                    <AlertTriangle size={14} aria-hidden="true" />
                    <span>{errorTarget.message}</span>
                    <button
                      type="button"
                      className="examination-error-dismiss"
                      aria-label="Đóng cảnh báo"
                      onClick={() => onClearError?.()}
                    >
                      <X size={12} />
                    </button>
                  </div>
                )}
                <div className="teacher-builder-class-assignment examination-class-picker-wrapper">
                  <ClassPicker
                    panelId={`exam-class-picker-${currentSession.index}`}
                    selected={currentSession.classIds || []}
                    onChange={(classIds) => {
                      onClearError?.();
                      const kept = Object.fromEntries(Object.entries(currentSession.classProctors || {}).filter(([classId]) => classIds.includes(classId)));
                      updateCurrentSession({ classIds, classProctors: kept });
                    }}
                    classes={availableClasses}
                    loading={classesLoading}
                    error={classesError || ""}
                    expanded={true}
                    hideTrigger={true}
                    individualMode={individualMode}
                    onIndividualModeChange={setIndividualMode}
                    individualCount={currentSession.studentEmails?.length || 0}
                    conflictStudentEmails={
                      errorTarget?.sessionIndex === currentSession?.index
                        ? errorTarget?.conflictStudents?.map(s => s.email.toLowerCase())
                        : undefined
                    }
                    conflictStudentIds={
                      errorTarget?.sessionIndex === currentSession?.index
                        ? errorTarget?.conflictStudents?.flatMap(s => [s.id, s.student_id, s.email.split("@")[0]].filter(Boolean) as string[])
                        : undefined
                    }
                    conflictNotice={errorTarget?.message}
                    selectedIndividualsPanel={
                      <div className="teacher-selected-class-list">
                        {(currentSession.studentEmails || []).map(email => {
                          const isConflict = Boolean(
                            errorTarget?.sessionIndex === currentSession?.index &&
                            errorTarget?.conflictStudents?.some(s => s.email.toLowerCase() === email.toLowerCase() || (s.student_id && email.toLowerCase().startsWith(s.student_id.toLowerCase())))
                          );
                          return (
                            <div key={email} className={`teacher-selected-class-row${isConflict ? " is-conflict-student has-error" : ""}`}>
                              <span className="teacher-selected-class-view">
                                <b>{email}</b>
                                {isConflict && <span className="teacher-conflict-tag" title="Trùng lịch thi">Trùng lịch</span>}
                              </span>
                              <button
                                type="button"
                                className="icon-button"
                                aria-label={`Xóa ${email}`}
                                onClick={() =>
                                  updateCurrentSession({
                                    studentEmails: (currentSession.studentEmails || []).filter(e => e !== email),
                                  })
                                }
                              >
                                <X size={14} />
                              </button>
                            </div>
                          );
                        })}
                        {!(currentSession.studentEmails || []).length && (
                          <p className="teacher-class-browser-empty">
                            <TranslatedText>{t("Select individual students from the list on the left.")}</TranslatedText>
                          </p>
                        )}
                      </div>
                    }
                    individualStudentsPanel={
                      <StudentSearchPicker
                        classIds={currentSession.classIds || []}
                        emails={currentSession.studentEmails || []}
                        onChange={(emails) => updateCurrentSession({ studentEmails: emails })}
                        onClose={() => setIndividualMode(false)}
                      />
                    }
                  />
                </div>
              </div>

              {/* Row 2b: Giám thị cho từng lớp */}
              <div className="examination-session-section">
                <h4 className="examination-section-subtitle">
                  <span><TranslatedText>{t("Class proctors")}</TranslatedText></span>
                  <small className="muted"><TranslatedText>{t("Choose at least one lecturer to proctor each selected class.")}</TranslatedText></small>
                </h4>
                {errorTarget?.sessionIndex === currentSession?.index && errorTarget?.field === "proctors" && (
                  <div className="examination-section-error-badge" role="alert">
                    <AlertTriangle size={14} aria-hidden="true" />
                    <span>{errorTarget.message}</span>
                    <button type="button" className="examination-error-dismiss" aria-label={t("Dismiss notification")} onClick={() => onClearError?.()}>
                      <X size={12} />
                    </button>
                  </div>
                )}
                <ClassProctorPicker
                  classIds={currentSession.classIds || []}
                  classes={availableClasses || []}
                  options={proctorOptions}
                  value={currentSession.classProctors || {}}
                  loading={proctorsLoading}
                  error={proctorsError || ""}
                  onChange={classProctors => { onClearError?.(); updateCurrentSession({ classProctors }); }}
                />
              </div>

              {/* Row 3: Nội dung đề (mỗi ca đề riêng biệt) */}
              <div className="examination-session-section">
                <div className="examination-section-header-row">
                  <div>
                    <h4 className="examination-section-subtitle">
                      <span>Nội dung đề</span>
                      <small className="muted">Mỗi ca thi sử dụng danh sách câu hỏi độc lập</small>
                    </h4>
                  </div>
                  <GlidingTooltipGroup className="builder-scoring-summary" label={t("Equal scoring")} role="group" multiline>
                    <strong><TranslatedText>{t("Total: 10 points")}</TranslatedText></strong>
                    <span><TranslatedText>{t("{value0} selected questions", { value0: totalQuestions })}</TranslatedText></span>
                    <button
                      type="button"
                      className="builder-scoring-help"
                      aria-label={t("Equal scoring")}
                      data-tooltip={t("Each selected question has the same weight, across all problems and SQL types.")}
                    >
                      <CircleHelp size={14} aria-hidden="true" />
                    </button>
                    <button
                      className="button primary teacher-small-button"
                      type="button"
                      disabled={problemsLoading || Boolean(problemsError) || sessionProblems.length >= 1}
                      title={sessionProblems.length >= 1 ? t("Each exam session can have only one exam paper.") : undefined}
                      onClick={() => onOpenProblemLibrary && currentSession && onOpenProblemLibrary(currentSession.index)}
                    >
                      <Plus size={15} aria-hidden="true" />
                      <TranslatedText>{problemsLoading ? t("Loading library…") : t("Add from library")}</TranslatedText>
                    </button>
                  </GlidingTooltipGroup>
                </div>

                {problemsError && (
                  <p className="teacher-state-failed" role="alert">
                    <TranslatedText>{t(problemsError)}</TranslatedText>
                  </p>
                )}

                {sessionProblems.length === 0 ? (
                  <div className="examination-problems-empty">
                    <p className="muted">Ca thi này chưa có bài tập nào. Hãy bấm <b>Thêm từ thư viện</b> để gán đề cho ca này.</p>
                  </div>
                ) : (
                  <ol className="builder-problem-list" aria-label="Nội dung đề ca thi">
                    {sessionProblems.map((item, index) => {
                      const problem = allProblems.find(row => row.id === item.id);
                      if (!problem) return null;
                      return (
                        <li className="builder-problem-card" key={item.id}>
                          <header className="builder-problem-card-heading">
                            <span className="builder-problem-number">{String(index + 1).padStart(2, "0")}</span>
                            <h3 className="builder-problem-title">{problem.title}</h3>
                            <button
                              type="button"
                              className={`button teacher-small-button builder-problem-settings-button${activeSettingsId === item.id ? " is-active" : ""}`}
                              aria-expanded={activeSettingsId === item.id}
                              onClick={() => setActiveSettingsId(curr => curr === item.id ? null : item.id)}
                            >
                              <Settings2 size={13} aria-hidden="true" />
                              <span><TranslatedText>{t("Problem settings")}</TranslatedText></span>
                              <ChevronDown size={13} className={activeSettingsId === item.id ? "is-expanded" : ""} aria-hidden="true" />
                            </button>
                            <div className="builder-points-actions">
                              <button
                                type="button"
                                aria-label={`Di chuyển ${problem.title} lên`}
                                title="Di chuyển lên"
                                disabled={index === 0}
                                onClick={() => moveProblemInSession(index, -1)}
                              >
                                <ArrowUp size={14} aria-hidden="true" />
                              </button>
                              <button
                                type="button"
                                aria-label={`Di chuyển ${problem.title} xuống`}
                                title="Di chuyển xuống"
                                disabled={index === sessionProblems.length - 1}
                                onClick={() => moveProblemInSession(index, 1)}
                              >
                                <ArrowDown size={14} aria-hidden="true" />
                              </button>
                              <button
                                type="button"
                                className="builder-problem-remove"
                                aria-label={`Xóa ${problem.title}`}
                                title="Xóa khỏi ca thi"
                                onClick={() => removeProblemFromSession(item.id)}
                              >
                                <X size={14} aria-hidden="true" />
                              </button>
                            </div>
                          </header>
                          <div className="builder-problem-tools">
                            <BuilderInlineSettings
                              hideTrigger
                              problemId={item.id}
                              title={problem.title}
                              detail={problemDetails?.[item.id] || null}
                              parts={item.workspaceParts || []}
                              questionSelection={item.questionSelection}
                              onQuestionSelectionChange={(selection) => updateSessionProblem(item.id, { questionSelection: selection })}
                              onPartsChange={(parts) => updateSessionProblem(item.id, { workspaceParts: parts })}
                              onBatchChange={(patch) => updateSessionProblem(item.id, patch)}
                              visibility={{
                                databaseStructureVisible: item.databaseStructureVisible !== false,
                                databaseDescriptionVisible: item.databaseDescriptionVisible !== false,
                              }}
                              onVisibilityChange={(vis) => updateSessionProblem(item.id, vis)}
                              datasetVisibility={item.datasetVisibility || {}}
                              onDatasetVisibilityChange={(datasetVisibility) => updateSessionProblem(item.id, { datasetVisibility })}
                              open={activeSettingsId === item.id}
                              onToggle={() => setActiveSettingsId(curr => curr === item.id ? null : item.id)}
                              error={catalogErrors?.[item.id]}
                              onRetry={() => onRetryCatalog && onRetryCatalog()}
                            />
                          </div>
                        </li>
                      );
                    })}
                  </ol>
                )}
              </div>
            </div>
            </div>
          )}
        </>
      )}

      {/* Modal: Thêm ngày thi / Cấu hình ca thi */}
      {modalMode && (
        <div className="examination-modal-backdrop" onClick={() => { setModalMode(null); setConfigSourceDate(null); }}>
          <div className="examination-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
            <header className="examination-modal-header">
              <h3>
                {modalMode === "add" ? "Thêm ngày thi" : `Cấu hình ca thi - ${formatDisplayDate(configSourceDate || modalDate)}`}
              </h3>
              <button
                type="button"
                className="icon-button"
                aria-label="Đóng"
                onClick={() => { setModalMode(null); setConfigSourceDate(null); }}
              >
                <X size={15} />
              </button>
            </header>

            <div className="examination-modal-body">
              <div className="teacher-field examination-modal-date-field">
                <label>NGÀY THI (DD/MM/YYYY)</label>
                <DayDateInput
                  value={modalDate}
                  onChange={(val) => setModalDate(val)}
                />
              </div>

              <div className="examination-modal-slots-heading">
                <span>Chọn các ca thi trong ngày (tối đa 4 ca):</span>
              </div>

              <div className="examination-modal-slots-list">
                {modalSlots.map((slot, idx) => (
                  <div key={slot.slotNum} className={`examination-modal-slot-row${slot.enabled ? " is-checked" : ""}`}>
                    <label className="examination-slot-check-label">
                      <input
                        type="checkbox"
                        checked={slot.enabled}
                        onChange={(e) => {
                          const next = [...modalSlots];
                          next[idx] = { ...next[idx], enabled: e.target.checked };
                          setModalSlots(next);
                        }}
                      />
                      <b>{slot.label}</b>
                    </label>
                    <div className="examination-slot-time-inputs">
                      <input
                        type="time"
                        value={slot.starts}
                        disabled={!slot.enabled}
                        onChange={(e) => {
                          const next = [...modalSlots];
                          next[idx] = { ...next[idx], starts: e.target.value };
                          setModalSlots(next);
                        }}
                        title="Giờ bắt đầu"
                      />
                      <span>–</span>
                      <input
                        type="time"
                        value={slot.ends}
                        disabled={!slot.enabled}
                        onChange={(e) => {
                          const next = [...modalSlots];
                          next[idx] = { ...next[idx], ends: e.target.value };
                          setModalSlots(next);
                        }}
                        title="Giờ kết thúc"
                      />
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <footer className="examination-modal-footer">
              <button type="button" className="button" onClick={() => { setModalMode(null); setConfigSourceDate(null); }}>
                Hủy
              </button>
              <button type="button" className="button primary" onClick={handleSaveModal}>
                {modalMode === "add" ? "Thêm ngày thi" : "Lưu cấu hình"}
              </button>
            </footer>
          </div>
        </div>
      )}

      {/* Delete Confirmation Dialog */}
      {deleteConfirmDate && (
        <div className="examination-modal-backdrop" onClick={() => setDeleteConfirmDate(null)}>
          <div className="examination-modal examination-confirm-modal" onClick={(e) => e.stopPropagation()}>
            <header className="examination-modal-header">
              <h3 className="danger-heading">
                <AlertTriangle size={15} />
                <span>Xác nhận xóa ngày thi</span>
              </h3>
              <button type="button" className="icon-button" onClick={() => setDeleteConfirmDate(null)}>
                <X size={15} />
              </button>
            </header>
            <div className="examination-modal-body">
              <p>
                Bạn có chắc chắn muốn xóa ngày <b>{formatDisplayDate(deleteConfirmDate)}</b>? Tất cả các ca thi và dữ liệu phân lớp/đề thi của ngày này sẽ bị xóa.
              </p>
            </div>
            <footer className="examination-modal-footer">
              <button type="button" className="button" onClick={() => setDeleteConfirmDate(null)}>
                Hủy
              </button>
              <button type="button" className="button primary is-danger-btn" onClick={executeDeleteDate}>
                Xóa ngày này
              </button>
            </footer>
          </div>
        </div>
      )}
    </section>
  );
}
