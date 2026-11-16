import { useEffect, useMemo, useState } from "react";
import type { QueryResult } from "@/shared/types/queryResult";
import type { WorkspaceDatabaseState } from "@/shared/types/workspaceDatabase";

/**
 * Keeps the schema/data the student's own sandbox had after the last run that returned one.
 * A later failed run keeps showing it but flags it as stale; changing question (key) drops it.
 */
export function useCurrentDatabase(result: QueryResult | null | undefined, key: string): WorkspaceDatabaseState | null {
  const [saved, setSaved] = useState<{ key: string; data: WorkspaceDatabaseState }>();
  useEffect(() => {
    if (result?.schemaTables) setSaved({ key, data: { schemaTables: result.schemaTables, tables: result.tables || [] } });
  }, [result, key]);
  const failed = result?.status === "Error";
  const stale = Boolean(failed && !result?.schemaTables);
  return useMemo(() => saved?.key === key ? { ...saved.data, stale } : null, [saved, key, stale]);
}
