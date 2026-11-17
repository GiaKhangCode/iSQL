import { useAuth } from "@/app/providers/AuthContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useRef, useState } from "react";
import { Link, useParams, Navigate } from "react-router-dom";
import { studentApi } from "@/features/student/studentApi";
import { useLoad } from "@/shared/hooks/useLoad";
import { Empty, ErrorState, Loading } from "@/shared/ui/ui";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { ContestDetailView } from "@/shared/components/ContestDetailView";
import { toast } from "@/shared/ui/StackingToasts";

export function StudentExaminationDetailPage() {
  const { session } = useAuth();
  if (session && !session.examSessionId && session.role === "student") {
    return <Navigate to="/dashboard" replace />;
  }
  const { t: tr } = useLanguage("student");
  const { examinationId } = useParams();
  const { data, loading, error, mutate } = useLoad(studentApi.getExaminations);
  const [now, setNow] = useState(Date.now());
  const refreshedBoundary = useRef("");
  const clock = useRef({ server: Date.now(), received: performance.now() });

  useEffect(() => {
    const current = data?.find((item) => item.id === examinationId);
    const server = current?.serverTime ? Date.parse(current.serverTime) : NaN;
    if (Number.isFinite(server)) {
      clock.current = { server, received: performance.now() };
      setNow(server);
    }
  }, [data, examinationId]);

  useEffect(() => {
    const timer = window.setInterval(
      () => setNow(clock.current.server + performance.now() - clock.current.received),
      1000
    );
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const current = data?.find((item) => item.id === examinationId);
    if (!current) return;
    const boundary =
      current.status === "Upcoming" && now >= parseServerDateTime(current.opensAt).getTime()
        ? `${examinationId}:start`
        : current.status === "Live" && now >= parseServerDateTime(current.closesAt).getTime()
        ? `${examinationId}:end`
        : "";
    if (boundary && refreshedBoundary.current !== boundary) {
      refreshedBoundary.current = boundary;
      void mutate();
    }
  }, [data, examinationId, now, mutate]);

  const previousFinishReason = useRef<string | null | undefined>(undefined);
  useEffect(() => {
    const current = data?.find((item) => item.id === examinationId);
    if (!current) return;
    if (previousFinishReason.current === "violations" && current.finishReason !== "violations") {
      toast.show({
        title: "Đã mở khóa bài thi",
        description: "Cán bộ coi thi đã mở khóa bài thi thành công. Bạn có thể tiếp tục làm bài.",
        variant: "success",
      });
    }
    previousFinishReason.current = current.finishReason;
    if (current.status === "Live" && current.finishReason === "violations") {
      const poll = window.setInterval(() => {
        void mutate();
      }, 3500);
      return () => window.clearInterval(poll);
    }
  }, [data, examinationId, mutate]);

  if (loading) {
    return (
      <section className="page contest-detail-page compact-contest-detail">
        <Loading variant="detail" label={tr("Loading examination…")} />
      </section>
    );
  }

  if (!data || error) {
    return (
      <section className="page contest-detail-page compact-contest-detail">
        <ErrorState
          title={tr("Examination unavailable")}
          message={tr(error) || tr("Could not load examination.")}
          onRetry={() => window.location.reload()}
        />
      </section>
    );
  }

  const exam = data.find((item) => item.id === examinationId);
  if (!exam) {
    return (
      <Empty title={tr("Examination unavailable")}>
        <Link to="/examinations">
          <TranslatedText>{tr("Back to examinations")}</TranslatedText>
        </Link>
      </Empty>
    );
  }

  const starts = parseServerDateTime(exam.opensAt).getTime();
  const ends = parseServerDateTime(exam.closesAt).getTime();
  const phase = now < starts ? "Upcoming" : now >= ends ? "Closed" : "Live";

  return (
    <section className="page contest-detail-page compact-contest-detail">
      <ContestDetailView
        contest={exam}
        isExamination={true}
        phase={phase}
        now={now}
        onUnlocked={() => {
          void mutate();
        }}
        getSubmissions={studentApi.getSubmissions}
        getProblem={studentApi.getProblem}
      />
    </section>
  );
}
