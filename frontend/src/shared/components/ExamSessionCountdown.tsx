import { useEffect, useState } from "react";
import { type Contest } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";

export function ContestCountdown({ contest }: { contest: Contest }) {
  const { t: tr } = useLanguage("student");
  const [now, setNow] = useState(Date.now);
  const active = contest.status === "Live" || contest.status === "Upcoming";
  const value = contest.status === "Live" ? contest.closesAt : contest.opensAt;
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
    ? contest.status === "Live" ? tr("Ended") : tr("Starting now")
    : `${tr(contest.status === "Live" ? "Ends in" : "Starts in")} ${duration}`;
  return <span className={`contest-countdown ${contest.status.toLowerCase()}`} role="timer" aria-live="off"><TranslatedText>{tr(label)}</TranslatedText></span>;
}
