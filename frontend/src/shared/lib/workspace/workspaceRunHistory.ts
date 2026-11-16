import type { WorkspaceAttempt } from "@/shared/types/workspaceAttempt";
import { storage } from "@/shared/api/storage";

export function readWorkspaceSelection(key: string): Record<string,string> {
  try { const value=JSON.parse(storage.get(key) || "{}"); return value && typeof value === "object" ? Object.fromEntries(Object.entries(value).filter(([,item]) => typeof item === "string")) as Record<string,string> : {}; }
  catch { return {}; }
}

export function readRunHistory(key: string): WorkspaceAttempt[] {
  try {
    const rows = JSON.parse(storage.get(key) || "[]");
    return Array.isArray(rows) ? rows.filter(row => row?.kind === "run" && Number.isFinite(row.id) &&
      typeof row.result?.status === "string" && typeof row.result?.message === "string").slice(0,20) : [];
  } catch { return []; }
}

export function saveRunHistory(key: string, attempts: WorkspaceAttempt[]) {
  const runs = attempts.filter(row => row.kind === "run").slice(0,20).map(row => {
    const result = {...row.result};
    // Change snapshots already contain the displayed tables. Avoid storing the full database again.
    if (result.schemaChanges) { delete result.actualSchema; delete result.schemaTables; delete result.tables; delete result.expectedSchema; }
    if (result.dataChanges) { delete result.actualTables; delete result.tables; delete result.expectedTables; }
    return {...row,result};
  });
  let value = JSON.stringify(runs);
  while (value.length > 300000 && runs.length > 1) { runs.pop(); value = JSON.stringify(runs); }
  if (value.length > 300000 && runs[0]) {
    const {status,message,questionId,part,executionTimeMs}=runs[0].result;
    runs[0] = {...runs[0],result:{status,message,questionId,part,executionTimeMs}};
    value = JSON.stringify(runs);
  }
  storage.set(key,value);
}
