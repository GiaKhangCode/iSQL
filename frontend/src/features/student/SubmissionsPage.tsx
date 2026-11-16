import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { studentApi } from "@/features/student/studentApi";
import { type Submission } from "@/data/models";
import { SubmissionDetails } from "@/shared/components/SubmissionDetails";
import { useLoad } from "@/shared/hooks/useLoad";
import { Empty, ErrorState, Loading, Status } from "@/shared/ui/ui";
import { submissionTimeLabel } from "@/shared/lib/datetime/serverDateTime";
import { Layers, ListFilter } from "lucide-react";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";

const SUBMISSION_PAGE_SIZE = 15;

export function SubmissionsPage() {
  const order=useListOrder();
  const dates = useListDateFilters();
  const { t: tr, locale: uiLocale } = useLanguage("student");
  const [selected, setSelected] = useState<Submission | null>(null);
  const [search, setSearch] = useState("");
  const [result, setResult] = useState("");
  const [source, setSource] = useState("");
  const [page, setPage] = useState(1);
  useEffect(()=>setPage(1),[dates.key,order.value]);
  const [params, setParams] = useSearchParams();
  const requestedSubmission = useRef(params.get("submission"));
  const desktopListRef = useRef<HTMLDivElement>(null);
  const mobileListRef = useRef<HTMLDivElement>(null);
  const { data: response, loading, error, mutate } = useLoad(
    () => studentApi.getSubmissions({ search, result, source }),
    [search, result, source],
  );
  useEffect(() => {
    const refresh = () => { void mutate(); };
    window.addEventListener("focus", refresh);
    return () => window.removeEventListener("focus", refresh);
  }, [mutate]);
  const filteredRows: Submission[] | undefined = response?.filter((item: Submission) => !item.questionOnly && (!params.get("context") || item.context===params.get("context")) && dates.matches(item));
  
  const data=filteredRows ? order.apply(filteredRows,item=>item.id) : undefined;
  const { data: problemsList } = useLoad(() => studentApi.getProblems({ includePrivate: true }), []);
  const pageCount = Math.max(1, Math.ceil((data?.length || 0) / SUBMISSION_PAGE_SIZE));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * SUBMISSION_PAGE_SIZE;
  const pageSubmissions = (data || []).slice(firstIndex, firstIndex + SUBMISSION_PAGE_SIZE);
  useEffect(() => {
    setPage(1);
  }, [search, source, result]);
  useEffect(() => {
    desktopListRef.current?.scrollTo({ top: 0 });
    mobileListRef.current?.scrollTo({ top: 0 });
  }, [currentPage, search, source, result, order.value]);
  useEffect(() => {
    if (requestedSubmission.current && data?.length) {
      const index = data.findIndex(item => item.id === requestedSubmission.current);
      requestedSubmission.current = null;
      if (index >= 0) {setPage(Math.floor(index / SUBMISSION_PAGE_SIZE) + 1);setSelected(data[index]);return;}
    }
    const visible = (data || []).slice(firstIndex, firstIndex + SUBMISSION_PAGE_SIZE);
    setSelected((previous) => visible.find((submission: Submission) => submission.id === previous?.id) || visible[0] || null);
  }, [response, firstIndex, order.value, dates.key]);
  function selectSubmission(submission: Submission) {
    setSelected(submission);
    setParams({submission:submission.id},{replace:true});
  }
  return (
    <section className="page submissions-page compact-tables-page compact-student-submissions">
      <h1 className="sr-only"><TranslatedText>{tr("Submissions")}</TranslatedText></h1>
      <div className="submissions-split">
      <div className="submissions-list-column">
      <div className="submissions-results-frame">
      <p className="submission-history-caption"><TranslatedText>{tr("Each attempt contains all answers sent using Submit at the top of the workspace.")}</TranslatedText></p>
      <TeacherListFilters order={order} suggestions={(data||[]).map(item=>({id:item.id,title:item.contextTitle || item.context || item.problemId,description:item.result}))} dates={dates} collapsible={false} search={search} onSearchChange={setSearch} searchLabel="Tìm kỳ thi…"
        filters={[
          { key: "source", label: tr("Source"), value: source, defaultValue: "", onChange: setSource,
            icon: <Layers size={17} aria-hidden="true" />, options: [{ value: "", label: tr("All sources") },
              ...["Practice", "Assignments", "Contests"].map(value => ({ value, label: tr(value) }))] },
          { key: "result", label: tr("Result"), value: result, defaultValue: "", onChange: setResult,
            icon: <ListFilter size={17} aria-hidden="true" />, options: [{ value: "", label: tr("All results") },
              ...["Accepted", "Partial", "Wrong Answer", "Runtime Error", "Time Limit Exceeded", "Rejected"].map(value => ({ value, label: tr(value) }))] },
        ]} />
      {loading && !data ? (
        <Loading variant="table" columns={4} />
      ) : error ? (
        <ErrorState title={tr("Could not load submissions")} message={tr(error)} onRetry={() => window.location.reload()} />
      ) : !data?.length ? (
        <Empty title={tr("No submissions found")}>
          <TranslatedText>{tr("Your attempts will appear here. Try changing the filters or")}</TranslatedText>{" "}
          <Link to="/assignments"><TranslatedText>{tr("view assignments")}</TranslatedText></Link>.
        </Empty>
      ) : (
        <>
          <div
            className="table-scroll submissions-desktop"
            ref={desktopListRef}
            aria-busy={loading}
            tabIndex={0}
            aria-label={tr("Submission history")}
          >
            <Table className="submissions-table">
              <colgroup><col style={{ width: "40%" }} /><col style={{ width: "23%" }} /><col style={{ width: "15%" }} /><col style={{ width: "22%" }} /></colgroup>
              <thead>
                <tr>
                  <th><TranslatedText>{tr("Examination")}</TranslatedText></th>
                  <th><TranslatedText>{tr("Source")}</TranslatedText></th>
                  <th><TranslatedText>{tr("Result")}</TranslatedText></th>
                  <th><TranslatedText>{tr("Submitted ↓")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {pageSubmissions.map((s: Submission) => {
                  const p =
                    problemsList?.find((p: any) => p.id === s.problemId) || {
                      number: "?",
                      title: "Unknown problem",
                    };
                  return (
                    <TableRow selected={selected?.id === s.id} key={s.id} className={selected?.id === s.id ? "selected" : undefined} onClick={() => selectSubmission(s)}>
                      <td>
                        <button type="button" className="submission-select-button" onClick={() => selectSubmission(s)} aria-pressed={selected?.id === s.id}>
                          {s.contextTitle || s.context || p.title}
                        </button>
                      </td>
                      <td>
                        <TranslatedText>{tr(s.source)}</TranslatedText>
                        <small>{s.contextTitle || s.context}</small>
                      </td>
                      <td>
                        <Status value={s.appealStatus || s.result} />
                      </td>
                      <td className="muted">
                        <TranslatedText>{tr(submissionTimeLabel(s.submittedAt, uiLocale))}</TranslatedText>
                      </td>
                    </TableRow>
                  );
                })}
              </tbody>
            </Table>
          </div>
          <div
            className="submission-mobile-list"
            ref={mobileListRef}
            aria-busy={loading}
            aria-label={tr("Submission history")}
          >
            {pageSubmissions.map((s: Submission) => {
              const p =
                problemsList?.find((p: any) => p.id === s.problemId) || {
                  number: "?",
                  title: "Unknown problem",
                };
              return (
                <button
                  className={`submission-mobile-row${selected?.id === s.id ? " selected" : ""}`}
                  aria-pressed={selected?.id === s.id}
                  key={s.id}
                  onClick={() => selectSubmission(s)}
                >
                  <span className="submission-mobile-main">
                    <span className="submission-mobile-problem">
                      <b>
                        {s.contextTitle || s.context || p.title}
                      </b>
                      <small><TranslatedText>{tr(s.source)}</TranslatedText> · {s.contextTitle || s.context}</small>
                    </span>
                    <Status value={s.appealStatus || s.result} />
                  </span>
                  <span className="submission-mobile-meta">
                    <small><TranslatedText>{tr(submissionTimeLabel(s.submittedAt, uiLocale))}</TranslatedText></small>
                  </span>
                </button>
              );
            })}
          </div>
          <div className="submissions-pagination-footer">
            <p aria-live="polite"><TranslatedText>{tr("Showing")}</TranslatedText> {firstIndex + 1}–{Math.min(firstIndex + SUBMISSION_PAGE_SIZE, data.length)} <TranslatedText>{tr("of")}</TranslatedText> {data.length} <TranslatedText>{tr("submissions · Newest first")}</TranslatedText></p>
            <nav className="submissions-pagination" aria-label={tr("Submission pages")}>
              <button type="button" className="button" disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)}><TranslatedText>{tr("← Previous")}</TranslatedText></button>
              <span><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</span>
              <button type="button" className="button" disabled={currentPage === pageCount} onClick={() => setPage(currentPage + 1)}><TranslatedText>{tr("Next →")}</TranslatedText></button>
            </nav>
          </div>
        </>
      )}
      </div>
      </div>
      <aside className="submission-inline-panel" aria-label={tr("Selected submission details")}>
      {selected ? (
        <SubmissionDetails
          key={selected.id}
          inline
          submission={selected}
          problem={
            problemsList?.find((p: any) => p.id === selected.problemId) || {
              number: "?",
              title: "Unknown problem",
              id: selected.problemId,
            }
          }
          onClose={() => setSelected(null)}
        />
      ) : (
        <Empty title={tr("Submission details")}><TranslatedText>{tr("Select a submission to view its result and submitted SQL.")}</TranslatedText></Empty>
      )}
      </aside>
      </div>
    </section>
  );
}
