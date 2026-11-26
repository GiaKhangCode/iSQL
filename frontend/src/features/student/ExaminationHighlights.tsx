import { TranslatedText } from "@/shared/ui/TranslatedText";
import type { Translator } from "@/i18n/index";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useLayoutEffect, useRef, useState, type TransitionEvent } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { type ExamSession } from "@/data/models";
import { LayeredExamSessionBanner } from "@/shared/components/LayeredExamSessionBanner";
import { ExamSessionCountdown } from "@/shared/components/ExamSessionCountdown";
import { useReducedMotion } from "@/shared/hooks/useReducedMotion";




function examSessionTimestamp(examSession: ExamSession) {
  const timestamp = new Date(examSession.opensAt).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
}

function featureMeta(examSession: ExamSession, tr: Translator) {
  const problemLabel = tr("{count} problems", { count: examSession.problemCount });
  const participants = examSession.submitters
    ? ` · ${tr("{count} participants", { count: examSession.submitters })}`
    : "";
  if (examSession.status === "Live") return `${tr("Live now")} · ${problemLabel}${participants}`;
  return `${examSession.date} ${examSession.time} · ${problemLabel}${participants}`;
}

export function ordinal(value: number) {
  if (value % 100 >= 11 && value % 100 <= 13) return `${value}th`;
  return `${value}${["th", "st", "nd", "rd"][value % 10] || "th"}`;
}

export function completionMonth(examSession: ExamSession, locale: string) {
  const date = new Date(examSession.closesAt);
  return Number.isFinite(date.getTime())
    ? date.toLocaleDateString(locale, { month: "short", year: "numeric" })
    : examSession.endDate || examSession.date;
}

export function selectHallOfFame(examSessionList: ExamSession[]) {
  return examSessionList
    .filter((examSession) => examSession.status === "Closed" && examSession.leaderboardEnabled)
    .sort((left, right) => examSessionTimestamp(right) - examSessionTimestamp(left))
    .flatMap((examSession) => examSession.leaderboard.map((entry) => ({ examSession, ...entry })))
    .slice(0, 3);
}

function useFeaturedCardsInView() {
  const [cardsInView, setCardsInView] = useState(2);
  useEffect(() => {
    const query = window.matchMedia("(max-width: 960px)");
    const update = () => setCardsInView(query.matches ? 1 : 2);
    update();
    query.addEventListener("change", update);
    return () => query.removeEventListener("change", update);
  }, []);
  return cardsInView;
}

function FeaturedExamSessionCard({ examSession, onOpen, sentinel = false }: {
  examSession: ExamSession;
  onOpen: (examSession: ExamSession) => void;
  sentinel?: boolean;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("student");
  return <LayeredExamSessionBanner
    id={examSession.id} bannerUrl={examSession.bannerUrl} className="featured-exam-session"
    onOpen={() => onOpen(examSession)} sentinel={sentinel}
    label={tr("Open {value0} exam session details", { value0: examSession.title })}
  >
    <div className="u049-top"><span className="u049-tag"><TranslatedText>{tr(examSession.status).toLocaleUpperCase(uiLocale)}</TranslatedText></span></div>
    <div className="u049-body">
      <div className="u049-k">{examSession.scope}</div>
      <h3 className="u049-t">{examSession.title}</h3>
      <div className="u049-reveal"><div className="u049-reveal-inner">
        <p className="u049-sum"><TranslatedText>{examSession.shortDescription || tr("SQL challenges for {value0}.", { value0: examSession.scope.toLowerCase() })}</TranslatedText></p>
        <div className="u049-foot">
          <small className="featured-exam-session-meta"><ExamSessionCountdown examSession={examSession} /><span>{featureMeta(examSession, tr)}</span></small>
          <span className="u049-cta"><TranslatedText>{tr("View details →")}</TranslatedText></span>
        </div>
      </div></div>
    </div>
  </LayeredExamSessionBanner>;
}

export function FeaturedExamSessions({ examSessionList, onOpen }: { examSessionList: ExamSession[]; onOpen: (examSession: ExamSession) => void }) {
  const { t: tr } = useLanguage("student");
  const viewport = useRef<HTMLDivElement>(null);
  const resumeTimer = useRef<number>();
  const gestureStartX = useRef<number | null>(null);
  const suppressCardOpen = useRef(false);
  const reducedMotion = useReducedMotion();
  const preferredCardsInView = useFeaturedCardsInView();
  const cardsInView = Math.min(preferredCardsInView, examSessionList.length);
  const canNavigate = examSessionList.length > cardsInView;
  const [displayIndex, setDisplayIndex] = useState(canNavigate ? 1 : 0);
  const [slideStep, setSlideStep] = useState(0);
  const [isSliding, setIsSliding] = useState(false);
  const [isPaused, setIsPaused] = useState(false);
  const [rotationVersion, setRotationVersion] = useState(0);
  const examSessionKey = examSessionList.map((examSession) => examSession.id).join("|");
  const carouselExamSessions = canNavigate ? [examSessionList[examSessionList.length - 1], ...examSessionList, examSessionList[0]] : examSessionList;

  useLayoutEffect(() => {
    setDisplayIndex(canNavigate ? 1 : 0);
    setIsSliding(false);
  }, [canNavigate, examSessionKey]);

  useLayoutEffect(() => {
    const element = viewport.current;
    if (!element || !cardsInView) return;
    const updateStep = () => {
      const gap = Number.parseFloat(getComputedStyle(element).getPropertyValue("--featured-carousel-gap")) || 16;
      setSlideStep((element.clientWidth - gap * (cardsInView - 1)) / cardsInView + gap);
    };
    updateStep();
    const observer = new ResizeObserver(updateStep);
    observer.observe(element);
    return () => observer.disconnect();
  }, [cardsInView]);

  function clearResumeTimer() {
    if (resumeTimer.current !== undefined) {
      window.clearTimeout(resumeTimer.current);
      resumeTimer.current = undefined;
    }
  }

  function pauseRotation() {
    clearResumeTimer();
    setIsPaused(true);
  }

  function resumeRotation() {
    clearResumeTimer();
    resumeTimer.current = window.setTimeout(() => {
      resumeTimer.current = undefined;
      setIsPaused(false);
    }, 1200);
  }

  function move(direction: -1 | 1, manual = false) {
    if (!canNavigate || isSliding) return;
    if (manual) setRotationVersion((version) => version + 1);
    if (reducedMotion) {
      setDisplayIndex((current) => {
        const next = current + direction;
        if (next === 0) return examSessionList.length;
        if (next === examSessionList.length + 1) return 1;
        return next;
      });
      return;
    }
    if (!slideStep) return;
    setIsSliding(true);
    setDisplayIndex((current) => current + direction);
  }

  useEffect(() => {
    const shouldRotate = examSessionList.length >= 3 && canNavigate && !reducedMotion && !isPaused && !isSliding;
    if (!shouldRotate) return;
    const timer = window.setTimeout(() => move(1), 4000);
    return () => window.clearTimeout(timer);
  }, [canNavigate, examSessionList.length, displayIndex, isPaused, isSliding, reducedMotion, rotationVersion]);

  useEffect(() => () => clearResumeTimer(), []);

  function finishSlide(event: TransitionEvent<HTMLDivElement>) {
    if (event.target !== event.currentTarget || event.propertyName !== "transform") return;
    setIsSliding(false);
    setDisplayIndex((current) => {
      if (current === 0) return examSessionList.length;
      if (current === examSessionList.length + 1) return 1;
      return current;
    });
  }

  function finishGesture(clientX: number) {
    if (gestureStartX.current === null) return;
    const movement = clientX - gestureStartX.current;
    gestureStartX.current = null;
    if (Math.abs(movement) >= 36) {
      suppressCardOpen.current = true;
      move(movement < 0 ? 1 : -1, true);
      window.setTimeout(() => { suppressCardOpen.current = false; }, 0);
    }
    resumeRotation();
  }

  if (!examSessionList.length) return null;
  return <section className="featured-exam-session-section" aria-labelledby="featured-exam-sessions-heading">
    <div className="featured-exam-session-heading-row">
      <h2 id="featured-exam-sessions-heading" className="featured-heading"><TranslatedText>{tr("Featured exam sessions")}</TranslatedText></h2>
      {canNavigate && <div className="featured-carousel-controls" aria-label={tr("Featured exam session navigation")}>
        <button className="featured-carousel-control" type="button" onClick={() => move(-1, true)} aria-label={tr("Previous featured exam sessions")}><ChevronLeft size={16} aria-hidden="true" /></button>
        <button className="featured-carousel-control" type="button" onClick={() => move(1, true)} aria-label={tr("Next featured exam sessions")}><ChevronRight size={16} aria-hidden="true" /></button>
      </div>}
    </div>
    <div
      ref={viewport}
      className="featured-carousel"
      data-visible={cardsInView}
      onPointerEnter={pauseRotation}
      onPointerLeave={resumeRotation}
      onFocusCapture={pauseRotation}
      onBlurCapture={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) resumeRotation();
      }}
      onPointerDown={(event) => {
        if (event.pointerType !== "mouse") {
          pauseRotation();
          gestureStartX.current = event.clientX;
        }
      }}
      onPointerUp={(event) => finishGesture(event.clientX)}
      onPointerCancel={() => { gestureStartX.current = null; resumeRotation(); }}
    >
      <div
        className="featured-carousel-track"
        style={{ transform: `translate3d(-${displayIndex * slideStep}px, 0, 0)`, transition: isSliding && !reducedMotion ? "transform 420ms cubic-bezier(.2, .7, .2, 1)" : "none" }}
        onTransitionEnd={finishSlide}
      >
        {carouselExamSessions.map((examSession, index) => <FeaturedExamSessionCard
          examSession={examSession}
          key={`${examSession.id}-${index}`}
          onOpen={(selectedExamSession) => {
            if (suppressCardOpen.current) {
              suppressCardOpen.current = false;
              return;
            }
            onOpen(selectedExamSession);
          }}
          sentinel={canNavigate && (index === 0 || index === carouselExamSessions.length - 1)}
        />)}
      </div>
    </div>
  </section>;
}
