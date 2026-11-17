import { useCallback, useEffect, useState } from "react";
import type { WorkspacePane } from "@/features/workspace/components/WorkspacePaneTabs";
import { storage } from "@/shared/api/storage";

function readPane(key: string): WorkspacePane {
  const pane=storage.get(key);
  return pane === "Database" || pane === "Run" || pane === "Submission" ? pane : "Description";
}
export function useWorkspacePane(key: string) {
  const [state,setState]=useState(() => ({key,pane:readPane(key)}));
  const pane=state.key === key ? state.pane : readPane(key);
  const setPane=useCallback((value: WorkspacePane) => setState({key,pane:value}),[key]);
  useEffect(() => {storage.set(key,pane);},[key,pane]);
  return [pane,setPane] as const;
}
