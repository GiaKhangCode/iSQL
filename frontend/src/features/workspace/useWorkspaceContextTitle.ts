import { useEffect, useState } from "react";
import { studentApi } from "@/shared/api/studentApi";

export function useWorkspaceContextTitle(source: string, context: string, suppliedTitle: string | null, enabled = true) {
  const [resolved, setResolved] = useState<{ context: string; title: string } | null>(null);
  useEffect(() => {
    if (!enabled || suppliedTitle || source === "Practice") return;
    let active = true;
    const request = source === "ExamSession" ? studentApi.getExaminations?.() : studentApi.getAssignments?.().then(value => value.assignments);
    request?.then(items => {
      const item = items.find(item => item.id === context);
      if (active && item) setResolved({ context, title: item.title });
    }).catch(() => {});
    return () => { active = false; };
  }, [source, context, suppliedTitle, enabled]);
  return suppliedTitle || (resolved?.context === context ? resolved.title : "");
}
