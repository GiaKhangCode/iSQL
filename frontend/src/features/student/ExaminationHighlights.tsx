import { TranslatedText } from "@/shared/ui/TranslatedText";
import type { Translator } from "@/i18n/index";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useLayoutEffect, useRef, useState, type TransitionEvent } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { type Contest } from "@/data/models";
import { LayeredContestBanner } from "@/shared/components/LayeredContestBanner";
import { ContestCountdown } from "@/shared/components/ContestCountdown";
import { useReducedMotion } from "@/shared/hooks/useReducedMotion";




function contestTimestamp(contest: Contest) {
  const timestamp = new Date(contest.opensAt).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
}

function featureMeta(contest: Contest, tr: Translator) {
  const problemLabel = tr("{count} problems", { count: contest.problemCount });
  const participants = contest.submitters
    ? ` · ${tr("{count} participants", { count: contest.submitters })}`
    : "";
  if (contest.status === "Live") return `${tr("Live now")} · ${problemLabel}${participants}`;
  return `${contest.date} ${contest.time} · ${problemLabel}${participants}`;
}

export function ordinal(value: number) {
  if (value % 100 >= 11 && value % 100 <= 13) return `${value}th`;
  return `${value}${["th", "st", "nd", "rd"][value % 10] || "th"}`;
}

export function completionMonth(contest: Contest, locale: string) {
  const date = new Date(contest.closesAt);
  return Number.isFinite(date.getTime())
    ? date.toLocaleDateString(locale, { month: "short", year: "numeric" })
    : contest.endDate || contest.date;
}

export function selectHallOfFame(contests: Contest[]) {
  return contests
    .filter((contest) => contest.status === "Closed" && contest.leaderboardEnabled)
    .sort((left, right) => contestTimestamp(right) - contestTimestamp(left))
    .flatMap((contest) => contest.leaderboard.map((entry) => ({ contest, ...entry })))
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

function FeaturedContestCard({ contest, onOpen, sentinel = false }: {
  contest: Contest;
  onOpen: (contest: Contest) => void;
  sentinel?: boolean;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("student");
  return <LayeredContestBanner
    id={contest.id} bannerUrl={contest.bannerUrl} className="featured-contest"
    onOpen={() => onOpen(contest)} sentinel={sentinel}
    label={tr("Open {value0} contest details", { value0: contest.title })}
  >
    <div className="u049-top"><span className="u049-tag"><TranslatedText>{tr(contest.status).toLocaleUpperCase(uiLocale)}</TranslatedText> · SQL</span></div>
    <div className="u049-body">
      <div className="u049-k">{contest.scope}</div>
      <h3 className="u049-t">{contest.title}</h3>
      <div className="u049-reveal"><div className="u049-reveal-inner">
        <p className="u049-sum"><TranslatedText>{contest.shortDescription || tr("SQL challenges for {value0}.", { value0: contest.scope.toLowerCase() })}</TranslatedText></p>
        <div className="u049-foot">
          <small className="featured-contest-meta"><ContestCountdown contest={contest} /><span>{featureMeta(contest, tr)}</span></small>
          <span className="u049-cta"><TranslatedText>{tr("View details →")}</TranslatedText></span>
        </div>
      </div></div>
    </div>
  </LayeredContestBanner>;
}

export function FeaturedContests({ contests, onOpen }: { contests: Contest[]; onOpen: (contest: Contest) => void }) {
  const { t: tr } = useLanguage("student");
  const viewport = useRef<HTMLDivElement>(null);
  const resumeTimer = useRef<number>();
  const gestureStartX = useRef<number | null>(null);
  const suppressCardOpen = useRef(false);
  const reducedMotion = useReducedMotion();
  const preferredCardsInView = useFeaturedCardsInView();
  const cardsInView = Math.min(preferredCardsInView, contests.length);
  const canNavigate = contests.length > cardsInView;
  const [displayIndex, setDisplayIndex] = useState(canNavigate ? 1 : 0);
  const [slideStep, setSlideStep] = useState(0);
  const [isSliding, setIsSliding] = useState(false);
  const [isPaused, setIsPaused] = useState(false);
  const [rotationVersion, setRotationVersion] = useState(0);
  const contestKey = contests.map((contest) => contest.id).join("|");
  const carouselContests = canNavigate ? [contests[contests.length - 1], ...contests, contests[0]] : contests;

  useLayoutEffect(() => {
    setDisplayIndex(canNavigate ? 1 : 0);
    setIsSliding(false);
  }, [canNavigate, contestKey]);

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
        if (next === 0) return contests.length;
        if (next === contests.length + 1) return 1;
        return next;
      });
      return;
    }
    if (!slideStep) return;
    setIsSliding(true);
    setDisplayIndex((current) => current + direction);
  }

  useEffect(() => {
    const shouldRotate = contests.length >= 3 && canNavigate && !reducedMotion && !isPaused && !isSliding;
    if (!shouldRotate) return;
    const timer = window.setTimeout(() => move(1), 4000);
    return () => window.clearTimeout(timer);
  }, [canNavigate, contests.length, displayIndex, isPaused, isSliding, reducedMotion, rotationVersion]);

  useEffect(() => () => clearResumeTimer(), []);

  function finishSlide(event: TransitionEvent<HTMLDivElement>) {
    if (event.target !== event.currentTarget || event.propertyName !== "transform") return;
    setIsSliding(false);
    setDisplayIndex((current) => {
      if (current === 0) return contests.length;
      if (current === contests.length + 1) return 1;
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

  if (!contests.length) return null;
  return <section className="featured-contest-section" aria-labelledby="featured-contests-heading">
    <div className="featured-contest-heading-row">
      <h2 id="featured-contests-heading" className="featured-heading"><TranslatedText>{tr("Featured contests")}</TranslatedText></h2>
      {canNavigate && <div className="featured-carousel-controls" aria-label={tr("Featured contest navigation")}>
        <button className="featured-carousel-control" type="button" onClick={() => move(-1, true)} aria-label={tr("Previous featured contests")}><ChevronLeft size={16} aria-hidden="true" /></button>
        <button className="featured-carousel-control" type="button" onClick={() => move(1, true)} aria-label={tr("Next featured contests")}><ChevronRight size={16} aria-hidden="true" /></button>
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
        {carouselContests.map((contest, index) => <FeaturedContestCard
          contest={contest}
          key={`${contest.id}-${index}`}
          onOpen={(selectedContest) => {
            if (suppressCardOpen.current) {
              suppressCardOpen.current = false;
              return;
            }
            onOpen(selectedContest);
          }}
          sentinel={canNavigate && (index === 0 || index === carouselContests.length - 1)}
        />)}
      </div>
    </div>
  </section>;
}
