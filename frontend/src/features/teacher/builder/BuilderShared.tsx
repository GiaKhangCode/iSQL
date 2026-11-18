import {Select} from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState, useEffect, useRef, type ReactNode } from "react";
import { CalendarDays } from "lucide-react";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { ExamSessionDetailView } from "@/shared/components/ExamSessionDetailView";
import type { ExamSession, WorkspacePart } from "@/data/models";
import type { QuestionSelection } from "@/features/teacher/builder/BuilderProblemSettings";
import { WORKSPACE_PARTS } from "@/shared/lib/workspace/workspaceParts";

export type BuilderProblem = { id: string; points: number; workspaceParts?: WorkspacePart[]; questionSelection?: QuestionSelection; datasetVisibility?: Record<string, boolean>; databaseStructureVisible?: boolean; databaseDescriptionVisible?: boolean };

export type BuilderDraft = {
  title: string;
  classIds: string[];
  studentEmails: string[];
  audienceType: "classes";
  shortDescription: string;
  description: string;
  rules: string;
  bannerUrl: string | null;
  bannerSourceUrl: string | null;
  bannerCrop: string | null;
  instructions: string;
  opens: string;
  closes: string;
  problems: BuilderProblem[];
  studentOptions: { comments: boolean; leaderboard: boolean; examMode: boolean; examViolationLimit?: number; showScoresAfterSubmit: boolean; maxSubmissions: number | null; hints?: boolean; aiAllowed?: boolean };
  published: boolean;
};

export const assignmentSeed: BuilderDraft = {
  title: "",
  classIds: [],
  studentEmails: [],
  audienceType: "classes",
  shortDescription: "",
  description: "",
  rules: "",
  bannerUrl: null,
  bannerSourceUrl: null,
  bannerCrop: null,
  instructions: "",
  opens: "",
  closes: "",
  problems: [],
  studentOptions: { comments: true, hints: true, leaderboard: false, aiAllowed: true, examMode: false, examViolationLimit: 2, showScoresAfterSubmit: true, maxSubmissions: null },
  published: false,
};

export const examSessionSeed: BuilderDraft = {
  title: "",
  classIds: [],
  studentEmails: [],
  audienceType: "classes",
  shortDescription: "",
  description: "",
  rules: "",
  bannerUrl: null,
  bannerSourceUrl: null,
  bannerCrop: null,
  instructions: "",
  opens: "",
  closes: "",
  problems: [],
  studentOptions: { comments: false, leaderboard: true, examMode: false, examViolationLimit: 2, showScoresAfterSubmit: true, maxSubmissions: null },
  published: false,
};

export function assignmentWorkspaceParts(value: unknown): WorkspacePart[] {
  if (!Array.isArray(value)) return [...WORKSPACE_PARTS];
  return WORKSPACE_PARTS.filter((part) => value.includes(part));
}

export function previewDate(value: string, locale: string) {
  if (!value) return "Not set";
  const date = new Date(value);
  return Number.isFinite(date.getTime())
    ? date.toLocaleString(locale, { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })
    : "Not set";
}

export function formatDateForInput(isoDate: string) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);
  return match ? `${match[3]}/${match[2]}/${match[1]}` : "";
}

export function parseDateFromInput(displayDate: string) {
  const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(displayDate.trim());
  if (!match) return "";
  const day = Number(match[1]);
  const month = Number(match[2]);
  const year = Number(match[3]);
  const date = new Date(year, month - 1, day);
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return "";
  return `${year.toString().padStart(4, "0")}-${month.toString().padStart(2, "0")}-${day.toString().padStart(2, "0")}`;
}

export function DateTimeInput({ value, onChange, label }: { value: string; onChange: (value: string) => void; label: string }) {
  const { t: tr } = useLanguage("teacher");
  const calendarRef = useRef<HTMLInputElement>(null);
  const lastCommittedValue = useRef(value);
  const [dayText, setDayText] = useState(() => value.slice(8, 10));
  const [monthText, setMonthText] = useState(() => value.slice(5, 7));
  const [yearText, setYearText] = useState(() => value.slice(0, 4));
  const initialHour = Number(value.slice(11, 13));
  const [hourText, setHourText] = useState(value ? String((initialHour + 11) % 12 + 1).padStart(2, "0") : "12");
  const [minuteText, setMinuteText] = useState(value.slice(14, 16) || "00");
  const [period, setPeriod] = useState<"AM" | "PM">(initialHour >= 12 ? "PM" : "AM");

  useEffect(() => {
    if (value === lastCommittedValue.current) return;
    lastCommittedValue.current = value;
    setDayText(value.slice(8, 10));
    setMonthText(value.slice(5, 7));
    setYearText(value.slice(0, 4));
    const hour24 = Number(value.slice(11, 13));
    setHourText(value ? String((hour24 + 11) % 12 + 1).padStart(2, "0") : "12");
    setMinuteText(value.slice(14, 16) || "00");
    setPeriod(hour24 >= 12 ? "PM" : "AM");
  }, [value]);

  const isoDate = value.slice(0, 10);
  const parsedDate = parseDateFromInput(`${dayText}/${monthText}/${yearText}`);

  function commit(next: string) {
    lastCommittedValue.current = next;
    onChange(next);
  }

  function commitDateAndTime(date: string, hour = hourText, minute = minuteText, meridiem = period) {
    if (!date || !/^(?:[1-9]|1[0-2])$/.test(hour) || !/^(?:[0-5]?\d)$/.test(minute)) {
      commit("");
      return;
    }
    const hour12 = Number(hour);
    const hour24 = hour12 % 12 + (meridiem === "PM" ? 12 : 0);
    commit(`${date}T${String(hour24).padStart(2, "0")}:${String(Number(minute)).padStart(2, "0")}`);
  }

  function updateDateSegment(part: "day" | "month" | "year", raw: string) {
    const digits = raw.replace(/\D/g, "").slice(0, part === "year" ? 4 : 2);
    const nextDay = part === "day" ? digits : dayText;
    const nextMonth = part === "month" ? digits : monthText;
    const nextYear = part === "year" ? digits : yearText;
    if (part === "day") setDayText(digits);
    if (part === "month") setMonthText(digits);
    if (part === "year") setYearText(digits);
    const date = parseDateFromInput(`${nextDay}/${nextMonth}/${nextYear}`);
    if (date) commitDateAndTime(date);
    else commit("");
  }

  function normalizeDateSegment(part: "day" | "month") {
    const valueToNormalize = part === "day" ? dayText : monthText;
    if (!valueToNormalize) return;
    const normalized = valueToNormalize.padStart(2, "0");
    if (part === "day") setDayText(normalized);
    else setMonthText(normalized);
    const date = parseDateFromInput(`${part === "day" ? normalized : dayText}/${part === "month" ? normalized : monthText}/${yearText}`);
    if (date) commitDateAndTime(date);
  }

  function updateDate(date: string) {
    const [year, month, day] = date.split("-");
    setDayText(day || "");
    setMonthText(month || "");
    setYearText(year || "");
    commitDateAndTime(date);
  }

  function updateHour(next: string) {
    const digits = next.replace(/\D/g, "").slice(0, 2);
    setHourText(digits);
    if (parsedDate && digits && Number(digits) >= 1 && Number(digits) <= 12) commitDateAndTime(parsedDate, digits);
    else commit("");
  }

  function updateMinute(next: string) {
    const digits = next.replace(/\D/g, "").slice(0, 2);
    setMinuteText(digits);
    if (parsedDate && digits && Number(digits) <= 59) commitDateAndTime(parsedDate, hourText, digits);
    else commit("");
  }

  function updatePeriod(next: "AM" | "PM") {
    setPeriod(next);
    if (parsedDate) commitDateAndTime(parsedDate, hourText, minuteText, next);
  }

  return <div className="teacher-datetime-input">
    <div className="teacher-date-segments" aria-label={tr("{value0} date, day month year", { value0: label })}>
      <input type="text" inputMode="numeric" maxLength={2} placeholder="DD" aria-label={tr("{value0} day", { value0: label })}
        value={dayText} onChange={(event) => updateDateSegment("day", event.target.value)}
        onBlur={() => normalizeDateSegment("day")} />
      <span>/</span>
      <input type="text" inputMode="numeric" maxLength={2} placeholder="MM" aria-label={tr("{value0} month", { value0: label })}
        value={monthText} onChange={(event) => updateDateSegment("month", event.target.value)}
        onBlur={() => normalizeDateSegment("month")} />
      <span>/</span>
      <input type="text" inputMode="numeric" maxLength={4} placeholder="YYYY" aria-label={tr("{value0} year", { value0: label })}
        value={yearText} onChange={(event) => updateDateSegment("year", event.target.value)} />
    </div>
    <div className="teacher-time-fields">
      <input type="text" inputMode="numeric" maxLength={2} placeholder="hh" aria-label={tr("{value0} hour", { value0: label })}
        value={hourText} onChange={(event) => updateHour(event.target.value)}
        onBlur={() => { if (hourText && Number(hourText) >= 1 && Number(hourText) <= 12) setHourText(String(Number(hourText)).padStart(2, "0")); }} />
      <span className="teacher-time-separator">:</span>
      <input type="text" inputMode="numeric" maxLength={2} placeholder="mm" aria-label={tr("{value0} minute", { value0: label })}
        value={minuteText} onChange={(event) => updateMinute(event.target.value)}
        onBlur={() => { if (minuteText && Number(minuteText) <= 59) setMinuteText(String(Number(minuteText)).padStart(2, "0")); }} />
      <div className="teacher-ampm-toggle" role="group" aria-label={tr("{value0} AM or PM", { value0: label })}>
        {(["AM", "PM"] as const).map((item) => <button key={item} type="button" className={period === item ? "is-active" : ""}
          aria-pressed={period === item} onClick={() => updatePeriod(item)}><TranslatedText>{tr(item)}</TranslatedText></button>)}
      </div>
    </div>
    <button type="button" className="teacher-date-picker-button" aria-label={tr("Choose {value0} date", { value0: label.toLowerCase() })} onClick={() => {
      const input = calendarRef.current as (HTMLInputElement & { showPicker?: () => void }) | null;
      if (!input) return;
      if (input.showPicker) input.showPicker();
      else input.click();
    }}><CalendarDays size={16} /></button>
    <input ref={calendarRef} className="teacher-native-date-picker" type="date" tabIndex={-1} aria-hidden="true" value={isoDate}
      onChange={(event) => updateDate(event.target.value)} />
  </div>;
}

export function ActivityPreview({ draft, examSession, classes, problems, bannerActions }: {
  draft: BuilderDraft;
  examSession: boolean;
  classes: { id: string; course?: string }[];
  problems: { id: string; title: string; topic?: string; difficulty?: string }[];
  bannerActions?: ReactNode;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("teacher");
  const [phase, setPhase] = useState<"Upcoming" | "Live" | "Closed">("Upcoming");
  const [previewNow, setPreviewNow] = useState(Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setPreviewNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const [selectedClassId, setSelectedClassId] = useState(draft.classIds[0] || "");
  const classId = draft.classIds.includes(selectedClassId) ? selectedClassId : draft.classIds[0];
  const classLabel = classes.find((item) => item.id === classId)?.course || classId || "No class selected";
  const audience = classLabel;
  const totalPoints = Math.round(draft.problems.reduce((sum, item) => sum + (Number(item.points) || 0), 0) * 100) / 100;
  const title = draft.title.trim() || (examSession ? "Untitled exam session" : "Untitled assignment");

  return <div className="teacher-activity-preview">
    <div className="teacher-preview-mode-bar">
      <span><TranslatedText>{tr("STUDENT PREVIEW · Draft content is not published")}</TranslatedText></span>
      {draft.audienceType === "classes" && draft.classIds.length > 1 && <label><TranslatedText>{tr("View as class")}</TranslatedText>
        <Select value={classId} onChange={(event) => setSelectedClassId(event.target.value)}>
          {draft.classIds.map((id) => <option value={id} key={id}>{classes.find((item) => item.id === id)?.course || id}</option>)}
        </Select>
      </label>}
    </div>
    {examSession ? <>
      <div className="teacher-preview-phase-tabs" role="group" aria-label={tr("Preview exam session state")}>
        {(["Upcoming", "Live", "Closed"] as const).map((item) => <button type="button" key={item} className={phase === item ? "is-active" : ""} aria-pressed={phase === item} onClick={() => setPhase(item)}><TranslatedText>{tr(item)}</TranslatedText></button>)}
      </div>
      <ExamSessionDetailView preview bannerActions={bannerActions} phase={phase} now={previewNow} examSession={{
        id: "preview", title, status: phase, scope: audience, audienceType: draft.audienceType,
        shortDescription: draft.shortDescription, description: draft.description, rules: draft.rules,
        bannerUrl: draft.bannerUrl, opensAt: draft.opens ? new Date(draft.opens).toISOString() : "", closesAt: draft.closes ? new Date(draft.closes).toISOString() : "",
        date: draft.opens ? draft.opens.slice(0, 10) : "Not set",
        time: draft.opens ? draft.opens.slice(11, 16) : "",
        endDate: draft.closes ? draft.closes.slice(0, 10) : "Not set",
        endTime: draft.closes ? draft.closes.slice(11, 16) : "",
        problemCount: draft.problems.length, problemIds: draft.problems.map((item) => item.id),
        problemDetails: draft.problems.map((item) => { const problem = problems.find((row) => row.id === item.id); return {
          id: item.id, title: problem?.title || item.id, difficulty: problem?.difficulty || "SQL problem",
          topic: problem?.topic || "SQL", points: item.points,
        }; }),
        totalPoints, score: draft.studentOptions.showScoresAfterSubmit ? 0 : null, rank: null, leaderboardEnabled: draft.studentOptions.leaderboard && draft.studentOptions.showScoresAfterSubmit,
        showScoresAfterSubmit: draft.studentOptions.showScoresAfterSubmit, maxSubmissions: draft.studentOptions.maxSubmissions,
        aiAllowed: false, leaderboard: [], submitters: 0,
      } satisfies ExamSession} />
    </> : <>
      <header className="assignment-work-heading"><h1>{title}</h1><p><TranslatedText>{tr(audience)}</TranslatedText></p></header>
      <dl className="assignment-summary">
        <div><dt><TranslatedText>{tr("DUE DATE")}</TranslatedText></dt><dd><TranslatedText>{tr(previewDate(draft.closes, uiLocale))}</TranslatedText></dd></div>
        <div><dt><TranslatedText>{tr("PROBLEMS")}</TranslatedText></dt><dd>{draft.problems.length} <TranslatedText>{tr("SQL problem")}</TranslatedText><TranslatedText>{draft.problems.length === 1 ? "" : tr("s")}</TranslatedText></dd></div>
        <div><dt><TranslatedText>{tr("POINTS")}</TranslatedText></dt><dd>{totalPoints}</dd></div>
        <div><dt><TranslatedText>{tr("STATUS")}</TranslatedText></dt><dd><TranslatedText>{tr("Not started")}</TranslatedText></dd></div>
      </dl>
      <section className="assignment-instructions"><h2><TranslatedText>{tr("Instructions")}</TranslatedText></h2><ProblemMarkdown className="exam-session-markdown">{draft.instructions || tr("No instructions yet.")}</ProblemMarkdown></section>
      <section className="assignment-problems-card"><div className="section-heading"><h2><TranslatedText>{tr("Problems")}</TranslatedText></h2><small className="muted">{draft.problems.length} <TranslatedText>{tr("problem")}</TranslatedText><TranslatedText>{draft.problems.length === 1 ? "" : tr("s")}</TranslatedText></small></div>
        {draft.problems.map((item, index) => {
          const problem = problems.find((row) => row.id === item.id);
          return <div className="assignment-problem-row" key={item.id}><span className="assignment-problem-number">{String(index + 1).padStart(2, "0")}</span><div><h3>{problem?.title || item.id}</h3><p>{problem?.topic || "SQL"}</p></div><span className="assignment-problem-status">{item.points} <TranslatedText>{tr("points")}</TranslatedText></span></div>;
        })}
        {!draft.problems.length && <p className="muted"><TranslatedText>{tr("No problems selected yet.")}</TranslatedText></p>}
      </section>
    </>}
  </div>;
}
