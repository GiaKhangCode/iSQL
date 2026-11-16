import {useEffect, useMemo, useState} from "react";
import {Link, useLocation} from "react-router-dom";
import {useLanguage} from "@/shared/context/LanguageContext";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {Select} from "@/shared/ui/Select";
import {Table} from "@/shared/ui/Table";
import {ErrorState, Loading} from "@/shared/ui/ui";
import {toast} from "@/shared/ui/StackingToasts";
import {teacherService} from "@/shared/api/teacherService";
import {regradeAssignments, type RegradeActivityOption, type RegradeCandidate, type RegradeReviewer} from "@/shared/api/regradeAssignments";
import type {Examination} from "@/data/examinations";
import {parseServerDateTime} from "@/shared/lib/datetime/serverDateTime";
import {formatPoints} from "@/shared/lib/sql/formatPoints";
import "@/styles/regrade-assignments.css";

const SEARCH_DELAY_MS = 300;
const ACTIVITY_FILTER_PREFIX = "activity:";

/** Faculty office / Admin / Dept head: choose a student's exam submission and the lecturer who regrades it. */
export function RegradeAssignmentsPage() {
  const {t, locale} = useLanguage("teacher");
  const location = useLocation();
  const reviewBase = location.pathname.startsWith("/admin/")
    ? "/admin"
    : location.pathname.startsWith("/teacher/")
      ? "/teacher"
      : "/office";
  const [examinations, setExaminations] = useState<Examination[]>([]);
  const [standaloneActivities, setStandaloneActivities] = useState<RegradeActivityOption[]>([]);
  const [reviewers, setReviewers] = useState<RegradeReviewer[]>([]);
  const [rows, setRows] = useState<RegradeCandidate[]>([]);
  const [truncated, setTruncated] = useState(false);
  const [examinationId, setExaminationId] = useState("");
  const [sessionId, setSessionId] = useState("");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [appealOnly, setAppealOnly] = useState(false);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [noteDrafts, setNoteDrafts] = useState<Record<string, string>>({});
  const [busyId, setBusyId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let active = true;
    Promise.all([teacherService.getExaminations(), regradeAssignments.reviewers()])
      .then(([exams, people]) => { if (active) { setExaminations(exams); setReviewers(people); } })
      .catch(err => { if (active) setError(err instanceof Error ? err.message : t("Could not load regrade assignment data.")); });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search), SEARCH_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  const isStandaloneActivity = examinationId.startsWith(ACTIVITY_FILTER_PREFIX);
  const effectiveExaminationId = isStandaloneActivity ? "" : examinationId;
  const effectiveSessionId = isStandaloneActivity ? examinationId.slice(ACTIVITY_FILTER_PREFIX.length) : sessionId;

  useEffect(() => {
    let active = true;
    setLoading(true);
    regradeAssignments.candidates({
      examinationId: effectiveExaminationId,
      sessionId: effectiveSessionId,
      q: query,
      appealOnly,
    })
      .then(result => {
        if (active) {
          setRows(result.rows);
          setTruncated(result.truncated);
          if (result.activities) setStandaloneActivities(result.activities);
          setError("");
        }
      })
      .catch(err => { if (active) setError(err instanceof Error ? err.message : t("Could not load exam submissions.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [effectiveExaminationId, effectiveSessionId, query, appealOnly, version]);

  const sessions = useMemo(
    () => (effectiveExaminationId ? examinations.find(exam => exam.id === effectiveExaminationId)?.sessions ?? [] : []),
    [examinations, effectiveExaminationId],
  );
  const date = (value: string | null) => (value ? parseServerDateTime(value).toLocaleString(locale) : "—");
  const withoutDraft = (id: string) => {
    setDrafts(current => {
      const {[id]: _removed, ...rest} = current;
      return rest;
    });
    setNoteDrafts(current => {
      const {[id]: _removed, ...rest} = current;
      return rest;
    });
  };

  async function assign(row: RegradeCandidate) {
    const reviewerId = drafts[row.submissionId] ?? row.reviewerId ?? "";
    const note = noteDrafts[row.submissionId] ?? row.assignmentNote ?? row.note ?? "";
    if (!reviewerId || busyId) return;
    setBusyId(row.submissionId);
    try {
      const result = await regradeAssignments.assign(row.submissionId, reviewerId, note);
      toast.show({variant: "success", title: t("Regrade assigned"), description: t("{name} will be notified.", {name: result.reviewerName})});
      withoutDraft(row.submissionId);
      setVersion(v => v + 1);
    } catch (err) {
      toast.show({variant: "error", title: t("Could not assign"), description: err instanceof Error ? err.message : ""});
    } finally { setBusyId(""); }
  }

  async function cancel(row: RegradeCandidate) {
    if (busyId) return;
    setBusyId(row.submissionId);
    try {
      await regradeAssignments.unassign(row.submissionId);
      withoutDraft(row.submissionId);
      setVersion(v => v + 1);
    } catch (err) {
      toast.show({variant: "error", title: t("Could not cancel the assignment"), description: err instanceof Error ? err.message : ""});
    } finally { setBusyId(""); }
  }

  const appealLabel = (row: RegradeCandidate) =>
    row.completedAt || row.appealReviewed ? t("Regraded") : row.appealRequested ? t("Appeal requested") : "—";

  return <section className="teacher-page regrade-page">
    <div className="regrade-filters">
      <label><span><TranslatedText>{t("Examination")}</TranslatedText></span>
        <Select value={examinationId} onChange={event => { setExaminationId(event.target.value); setSessionId(""); }}>
          <option value="">{t("All examinations")}</option>
          {examinations.map(exam => <option key={exam.id} value={exam.id}>{exam.title}</option>)}
          {standaloneActivities.map(act => (
            <option key={`${ACTIVITY_FILTER_PREFIX}${act.id}`} value={`${ACTIVITY_FILTER_PREFIX}${act.id}`}>
              {act.title}
            </option>
          ))}
        </Select>
      </label>
      <label><span><TranslatedText>{t("Exam session")}</TranslatedText></span>
        <Select value={sessionId} disabled={!effectiveExaminationId} onChange={event => setSessionId(event.target.value)}>
          <option value="">{t("All exam sessions")}</option>
          {sessions.map(session => session.id ? <option key={session.id} value={session.id}>{session.title || t("Session {number}", {number: session.index})}</option> : null)}
        </Select>
      </label>
      <label className="regrade-search"><span><TranslatedText>{t("Search")}</TranslatedText></span>
        <input value={search} placeholder={t("Student ID, name or problem…")} onChange={event => setSearch(event.target.value)} />
      </label>
      <label className="regrade-check"><input type="checkbox" checked={appealOnly} onChange={event => setAppealOnly(event.target.checked)} />
        <span><TranslatedText>{t("Only submissions with an appeal")}</TranslatedText></span></label>
    </div>

    {error && <ErrorState title={t("Could not load data")} message={error} onRetry={() => setVersion(v => v + 1)} />}
    {!error && loading && <Loading variant="table" columns={7} rows={6} label={t("Loading submissions…")} />}
    {!error && !loading && <>
      <div className="regrade-table-wrap">
        <Table className="regrade-table">
          <thead><tr>
            <th><TranslatedText>{t("Student ID")}</TranslatedText></th><th><TranslatedText>{t("Student")}</TranslatedText></th><th><TranslatedText>{t("Class")}</TranslatedText></th>
            <th><TranslatedText>{t("Exam submission")}</TranslatedText></th><th><TranslatedText>{t("Score")}</TranslatedText></th>
            <th><TranslatedText>{t("Appeal")}</TranslatedText></th><th><TranslatedText>{t("Regrading lecturer")}</TranslatedText></th>
          </tr></thead>
          <tbody>
            {rows.map(row => {
              const draft = drafts[row.submissionId] ?? row.reviewerId ?? "";
              const savedNote = row.assignmentNote ?? row.note ?? "";
              const noteDraft = noteDrafts[row.submissionId] ?? savedNote;
              const reviewerChanged = draft !== (row.reviewerId ?? "");
              const noteChanged = noteDraft.trim() !== savedNote.trim();
              const changed = reviewerChanged || (Boolean(row.reviewerId) && noteChanged);
              const locked = Boolean(row.completedAt);
              const busy = busyId === row.submissionId;
              const pending = row.appealRequested && !row.appealReviewed && !row.completedAt;
              const eligibleReviewers = reviewers.filter(person => !row.excludedReviewerIds?.includes(person.id));
              const currentExcluded = Boolean(row.reviewerId && !eligibleReviewers.some(person => person.id === row.reviewerId));
              return <tr key={row.submissionId}>
                <td data-label={t("Student ID")}>{row.studentCode ?? "—"}</td>
                <td data-label={t("Student")}>{row.studentName}</td>
                <td data-label={t("Class")}>{row.className ?? "—"}</td>
                <td data-label={t("Exam submission")}>
                  <strong>{row.problemTitle}</strong>
                  <small className="muted">{row.sessionTitle} · {date(row.submittedAt)}</small>
                  <small>
                    <Link className="regrade-review-link" state={{returnTo: location.pathname}} to={`${reviewBase}/results/review/${encodeURIComponent(row.submissionId)}`}>
                      <TranslatedText>{t("View submission")}</TranslatedText>
                    </Link>
                  </small>
                </td>
                <td data-label={t("Score")}>{row.score != null ? formatPoints(row.score) : "—"}{row.maxScore != null && row.score != null ? ` / ${formatPoints(row.maxScore)}` : ""}</td>
                <td data-label={t("Appeal")}>
                  <span className={`regrade-badge${pending ? " is-pending" : ""}${row.completedAt || row.appealReviewed ? " is-done" : ""}`}>{appealLabel(row)}</span>
                  {row.appealReason && <small className="muted regrade-reason-text" title={row.appealReason}>{row.appealReason}</small>}
                </td>
                <td data-label={t("Regrading lecturer")}>
                  <div className="regrade-assign">
                    <Select value={draft} disabled={busy || locked} aria-label={t("Regrading lecturer")} onChange={event => setDrafts(current => ({...current, [row.submissionId]: event.target.value}))}>
                      <option value="">{t("Not assigned")}</option>
                      {currentExcluded && row.reviewerId && (
                        <option value={row.reviewerId} disabled>
                          {row.reviewerName ?? row.reviewerId} ({t("Excluded")})
                        </option>
                      )}
                      {eligibleReviewers.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </Select>
                    {!locked && <button type="button" className="button primary" disabled={busy || !draft || !changed || (currentExcluded && !reviewerChanged)} onClick={() => void assign(row)}>
                      <TranslatedText>{row.reviewerId ? t("Change grader") : t("Assign")}</TranslatedText></button>}
                    {!locked && row.reviewerId && !changed && <button type="button" className="button" disabled={busy} onClick={() => void cancel(row)}>
                      <TranslatedText>{t("Unassign")}</TranslatedText></button>}
                  </div>
                  {!locked && (
                    <input
                      type="text"
                      className="regrade-note-input"
                      value={noteDraft}
                      disabled={busy}
                      placeholder={t("Note for regrading lecturer…")}
                      aria-label={t("Note for regrading lecturer…")}
                      onChange={event => setNoteDrafts(current => ({...current, [row.submissionId]: event.target.value}))}
                    />
                  )}
                  {locked && savedNote && <small className="muted">{t("Note")}: {savedNote}</small>}
                  {row.assignedAt && <small className="muted"><TranslatedText>{locked ? t("Completed") : t("Assigned")}</TranslatedText> · {date(row.completedAt ?? row.assignedAt)}</small>}
                </td>
              </tr>;
            })}
            {!rows.length && <tr><td colSpan={7} className="teacher-empty-row"><TranslatedText>{t("No matching submissions.")}</TranslatedText></td></tr>}
          </tbody>
        </Table>
      </div>
      {truncated && <p className="muted regrade-note"><TranslatedText>{t("Showing the first 500 submissions. Filter by examination, session or keyword to narrow the list.")}</TranslatedText></p>}
    </>}
  </section>;
}
