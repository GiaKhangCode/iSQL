import { useAuth } from "@/app/providers/AuthContext";
import { useListOrder, ListSortSelect } from "@/shared/components/ListSortSelect";
import { ListSearchInput } from "@/shared/components/ListSearchInput";
import { useListDateFilters, ListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useRef, useState } from "react";
import { useNavigate, Navigate } from "react-router-dom";
import { studentApi } from "@/features/student/studentApi";
import { type Contest } from "@/data/models";
import { useLoad } from "@/shared/hooks/useLoad";
import { Empty, ErrorState, Loading, Status } from "@/shared/ui/ui";
import { ContestSwipeTabs } from "@/shared/components/ContestSwipeTabs";
import { ContestCountdown } from "@/shared/components/ContestCountdown";
import { selectFeaturedContests } from "@/shared/lib/contest/featuredContests";
import {
  FeaturedContests,
  selectHallOfFame,
  completionMonth,
  ordinal,
} from "@/features/student/ContestsPage";
import { DoorOpen } from "lucide-react";

const EXAM_PAGE_SIZE = 10;

export function StudentExaminationsPage() {
  const { session } = useAuth();
  if (session && !session.examSessionId && session.role === "student") {
    return <Navigate to="/dashboard" replace />;
  }
  const { t: tr, locale: uiLocale } = useLanguage("student");
  const navigate = useNavigate();
  const { data = [], loading, error, mutate } = useLoad<Contest[]>(studentApi.getExaminations);
  const { data: contestsData = [] } = useLoad<Contest[]>(studentApi.getContests);

  const [tab, setTab] = useState("All");
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const dates = useListDateFilters();
  const order = useListOrder();
  const feedRef = useRef<HTMLDivElement>(null);

  useEffect(() => setPage(1), [order.value]);

  if (loading) {
    return (
      <section className="page contests-page u048-page">
        <Loading variant="cards" columns={3} rows={6} label={tr("Loading examinations…")} />
      </section>
    );
  }

  if (error) {
    return (
      <section className="page contests-page u048-page">
        <ErrorState
          title={tr("Examinations unavailable")}
          message={tr(error) || tr("Could not load examinations.")}
          onRetry={() => void mutate()}
        />
      </section>
    );
  }

  const examCounts = {
    All: data.length,
    Upcoming: data.filter((exam) => exam.status === "Upcoming").length,
    Live: data.filter((exam) => exam.status === "Live").length,
    Past: data.filter((exam) => exam.status === "Closed").length,
  };

  const featured = selectFeaturedContests(data);
  const hallOfFame = selectHallOfFame([...data, ...contestsData]);

  function openExam(exam: Contest) {
    navigate(`/examinations/${exam.id}`);
  }

  function changePage(nextPage: number) {
    setPage(nextPage);
    requestAnimationFrame(() => feedRef.current?.scrollTo({ top: 0 }));
  }

  return (
    <section className="page contests-page u048-page">
      <h1 className="sr-only"><TranslatedText>{tr("Examinations")}</TranslatedText></h1>

      <FeaturedContests contests={featured} onOpen={openExam} />

      <ContestSwipeTabs
        tabs={["All", "Upcoming", "Live", "Past"].map((id) => ({
          id,
          label: <TranslatedText>{tr(id)}</TranslatedText>,
          count: examCounts[id as keyof typeof examCounts],
        }))}
        value={tab}
        onChange={(next) => {
          setTab(next);
          setPage(1);
          requestAnimationFrame(() => feedRef.current?.scrollTo({ top: 0 }));
        }}
        label={tr("Examination status")}
        toolbar={
          <>
            <label className="sr-only" htmlFor="exam-search">
              <TranslatedText>{tr("Search examinations")}</TranslatedText>
            </label>
            <ListSearchInput
              value={search}
              onChange={(value) => {
                setSearch(value);
                setPage(1);
              }}
              label={tr("Search examinations")}
              items={data
                .filter((c) => dates.matches(c))
                .map((c) => ({ id: c.id, title: c.title, description: `${c.room || ""} ${c.scope || ""}` }))}
            />
            <ListSortSelect order={order} />
            <ListDateFilters dates={dates} />
          </>
        }
        aside={
          <aside className="hall-of-fame" aria-label={tr("Hall of Fame")}>
            <h2>
              <TranslatedText>{tr("Hall of Fame")}</TranslatedText>
            </h2>
            <p className="fame-description">
              <TranslatedText>{tr("Recent public achievements")}</TranslatedText>
            </p>
            {hallOfFame.length > 0 ? (
              <ol className="fame-list">
                {hallOfFame.map((entry, index) => (
                  <li className="fame-row" key={`${entry.contest.id}-${entry.rank}-${entry.student}`}>
                    <b>{String(index + 1).padStart(2, "0")}</b>
                    <div>
                      <strong>{entry.student}</strong>
                      <small className="fame-place">
                        <TranslatedText>
                          {uiLocale === "vi-VN" ? tr("Rank {rank}", { rank: entry.rank }) : `${ordinal(entry.rank)} place`}
                        </TranslatedText>
                      </small>
                      <small className="fame-contest">{entry.contest.title}</small>
                      <time className="fame-date">{completionMonth(entry.contest, uiLocale)}</time>
                    </div>
                  </li>
                ))}
              </ol>
            ) : (
              <p className="fame-empty">
                <TranslatedText>{tr("Published results will appear here after a leaderboard contest closes.")}</TranslatedText>
              </p>
            )}
          </aside>
        }
      >
        {(panelTab, active) => {
          const filteredRows = data.filter(
            (exam) =>
              (panelTab === "All" ||
                exam.status ===
                  (panelTab === "Live" ? "Live" : panelTab === "Past" ? "Closed" : "Upcoming")) &&
              dates.matches(exam) &&
              matchesListSearch(search, exam.title, exam.room, exam.shortDescription, exam.scope)
          );
          const filtered = order.apply(filteredRows, (item) => item.id);
          const pageCount = Math.max(1, Math.ceil(filtered.length / EXAM_PAGE_SIZE));
          const currentPage = Math.min(active ? page : 1, pageCount);
          const firstIndex = (currentPage - 1) * EXAM_PAGE_SIZE;
          const pageExams = filtered.slice(firstIndex, firstIndex + EXAM_PAGE_SIZE);

          return (
            <div id={active ? "contest-feed" : undefined} className="contest-feed-panel">
              <div className="section-heading">
                <h2>
                  <TranslatedText>
                    {panelTab === "All"
                      ? tr("All examinations")
                      : tr("{value0} examinations", { value0: panelTab })}
                  </TranslatedText>
                </h2>
                <div className="contest-list-controls">
                  <span className="tiny muted" aria-live="polite">
                    <TranslatedText>{tr("Showing")}</TranslatedText>{" "}
                    {filtered.length ? firstIndex + 1 : 0}–{Math.min(firstIndex + EXAM_PAGE_SIZE, filtered.length)}{" "}
                    <TranslatedText>{tr("of")}</TranslatedText> {filtered.length}
                  </span>
                  <nav className="contest-list-pagination" aria-label={tr("Examination pages")}>
                    <button
                      type="button"
                      className="button"
                      disabled={currentPage === 1}
                      onClick={() => changePage(currentPage - 1)}
                    >
                      <TranslatedText>{tr("← Previous")}</TranslatedText>
                    </button>
                    <span>
                      <TranslatedText>{tr("Page")}</TranslatedText> {currentPage}{" "}
                      <TranslatedText>{tr("of")}</TranslatedText> {pageCount}
                    </span>
                    <button
                      type="button"
                      className="button"
                      disabled={currentPage === pageCount}
                      onClick={() => changePage(currentPage + 1)}
                    >
                      <TranslatedText>{tr("Next →")}</TranslatedText>
                    </button>
                  </nav>
                </div>
              </div>

              <div
                className="contest-feed-scroll"
                ref={active ? feedRef : undefined}
                tabIndex={0}
                aria-label={tr("Examination list")}
              >
                {pageExams.map((exam) => (
                  <article
                    className="contest-item exam-item"
                    key={exam.id}
                    id={active ? `student-exam-${exam.id}` : undefined}
                  >
                    <div>
                      <div className="contest-title">
                        <h3>{exam.title}</h3>
                        <Status
                          value={exam.finishReason === "violations" ? "Bị cấm thi" : exam.attemptStatus === "submitted" ? "Đã nộp bài" : exam.status}
                        />
                        {exam.room && (
                          <span
                            className="exam-room-tag"
                            style={{
                              fontSize: "11px",
                              fontWeight: 700,
                              background: "rgba(99, 102, 241, 0.1)",
                              color: "#4f46e5",
                              padding: "2px 8px",
                              borderRadius: "6px",
                              display: "inline-flex",
                              alignItems: "center",
                              gap: "4px",
                            }}
                          >
                            <DoorOpen size={13} /> Phòng {exam.room}
                          </span>
                        )}
                        <ContestCountdown contest={exam} />
                      </div>
                      <small>{exam.scope}</small>
                      <p>{exam.shortDescription || exam.description || tr("Official examination session")}</p>
                      <span className="tiny">
                        {exam.date} {exam.time} → {exam.endDate || exam.date} {exam.endTime} ·{" "}
                        {exam.problemCount} <TranslatedText>{tr("problem")}</TranslatedText>
                        <TranslatedText>{exam.problemCount === 1 ? "" : tr("s")}</TranslatedText>
                        {exam.room ? ` · Phòng ${exam.room}` : ""}
                        {exam.proctors && exam.proctors.length > 0 ? ` · CBCT: ${exam.proctors.join(", ")}` : ""}
                        {exam.examViolationLimit ? ` · Giới hạn vi phạm: ${exam.examViolationLimit}` : ""}
                      </span>
                    </div>
                    <button className="text-button" onClick={() => openExam(exam)}>
                      <TranslatedText>{tr("View details →")}</TranslatedText>
                    </button>
                  </article>
                ))}
                {!filtered.length && <Empty title={tr("No matching examinations")} />}
              </div>
            </div>
          );
        }}
      </ContestSwipeTabs>
    </section>
  );
}
