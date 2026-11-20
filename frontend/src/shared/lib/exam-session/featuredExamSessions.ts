import type { ExamSession } from "@/data/models";

const featuredStatusOrder: Record<Extract<ExamSession["status"], "Live" | "Upcoming">, number> = {
  Live: 0,
  Upcoming: 1,
};

function examSessionTimestamp(examSession: ExamSession) {
  const timestamp = new Date(examSession.opensAt).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
}

/** Live events first, followed by the nearest upcoming events. */
export function selectFeaturedExamSessions(examSessionList: ExamSession[]) {
  return examSessionList
    .filter((examSession): examSession is ExamSession & { status: "Live" | "Upcoming" } =>
      examSession.status === "Live" || examSession.status === "Upcoming")
    .sort((left, right) => {
      const statusDifference = featuredStatusOrder[left.status] - featuredStatusOrder[right.status];
      if (statusDifference) return statusDifference;
      const timeDifference = examSessionTimestamp(left) - examSessionTimestamp(right);
      return timeDifference || left.title.localeCompare(right.title) || left.id.localeCompare(right.id);
    })
    .slice(0, 6);
}
