import { useEffect, useState } from "react";
import { type ExamSession } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";

export function ExamSessionCountdown({ examSession }: { examSession: ExamSession }) {
  const { t: tr } = useLanguage("student");
  const [now, setNow] = useState(Date.now);
  const active = examSession.status === "Live" || examSession.status === "Upcoming";
  const value = examSession.status === "Live" ? examSession.closesAt : examSession.opensAt;
  // The API stores UTC timestamps without a timezone suffix.
  const target = new Date(value && !/(Z|[+-]\d{2}:?\d{2})$/i.test(value) ? value + "Z" : value).getTime();
  useEffect(() => {
    if (!active || !Number.isFinite(target)) return;
    setNow(Date.now());
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [active, target]);
  if (!active || !Number.isFinite(target)) return null;
  const remaining = Math.max(0, Math.ceil((target - now) / 1000));
  const days = Math.floor(remaining / 86400);
  const hours = Math.floor((remaining % 86400) / 3600);
  const minutes = Math.floor((remaining % 3600) / 60);
  const seconds = remaining % 60;
  const duration = `${days ? days + "d " : ""}${hours}h ${minutes}m ${seconds}s`;
  const label = remaining === 0
    ? examSession.status === "Live" ? tr("Ended") : tr("Starting now")
    : `${tr(examSession.status === "Live" ? "Ends in" : "Starts in")} ${duration}`;
  return <span className={`exam-session-countdown ${examSession.status.toLowerCase()}`} role="timer" aria-live="off"><TranslatedText>{tr(label)}</TranslatedText></span>;
}
