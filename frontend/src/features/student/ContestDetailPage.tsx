import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useRef, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { studentApi } from "@/features/student/studentApi";
import { useLoad } from "@/shared/hooks/useLoad";
import { Empty, ErrorState, Loading } from "@/shared/ui/ui";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { ContestDetailView } from "@/shared/components/ContestDetailView";
import { ExamAdmissionForm } from "@/features/student/ExamAdmissionForm";

export function ContestDetailPage() {
  const { t: tr } = useLanguage("student");
  const { contestId } = useParams();
  const { data, loading, error, mutate } = useLoad(studentApi.getContests);
  const [now, setNow] = useState(Date.now());
  const refreshedBoundary = useRef("");
  const clock=useRef({server:Date.now(),received:performance.now()});
  useEffect(()=>{
    const current=data?.find(item=>item.id===contestId);
    const server=current?.serverTime ? Date.parse(current.serverTime) : NaN;
    if(Number.isFinite(server)){clock.current={server,received:performance.now()};setNow(server);}
  },[data,contestId]);
  useEffect(() => {
    const timer = window.setInterval(() => setNow(clock.current.server + performance.now()-clock.current.received), 1000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    const current = data?.find((item) => item.id === contestId);
    if (!current) return;
    const boundary = current.status === "Upcoming" && now >= parseServerDateTime(current.opensAt).getTime()
      ? `${contestId}:start` : current.status === "Live" && now >= parseServerDateTime(current.closesAt).getTime()
        ? `${contestId}:end` : "";
    if (boundary && refreshedBoundary.current !== boundary) {
      refreshedBoundary.current = boundary;
      void mutate();
    }
  }, [data, contestId, now, mutate]);

  if (loading) return <section className="page contest-detail-page compact-contest-detail"><Loading variant="detail" label={tr("Loading contest…")} /></section>;
  if (!data || error) return <section className="page contest-detail-page compact-contest-detail"><ErrorState title={tr("Contest unavailable")} message={tr(error) || tr("Could not load contest.")} onRetry={() => window.location.reload()} /></section>;
  const contest = data.find((item) => item.id === contestId);
  if (!contest) return <Empty title={tr("Contest unavailable")}><Link to="/contests"><TranslatedText>{tr("Back to contests")}</TranslatedText></Link></Empty>;

  const starts = parseServerDateTime(contest.opensAt).getTime();
  const ends = parseServerDateTime(contest.closesAt).getTime();
  const phase = now < starts ? "Upcoming" : now >= ends ? "Closed" : "Live";
  return <section className="page contest-detail-page compact-contest-detail">
    <ContestDetailView contest={contest} phase={phase} now={now} onUnlocked={()=>{void mutate();}}
      getSubmissions={studentApi.getSubmissions} getProblem={studentApi.getProblem} AdmissionForm={ExamAdmissionForm} />
  </section>;
}
