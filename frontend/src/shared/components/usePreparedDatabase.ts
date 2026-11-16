import { useEffect, useRef, useState } from "react";
import type { WorkspaceDatabaseState } from "@/shared/components/WorkspaceDatabasePanel";

/** Retain successful baselines while moving between questions and information tabs. */
export function usePreparedDatabase(key: string | null, enabled: boolean, load: () => Promise<WorkspaceDatabaseState>) {
  const cache = useRef(new Map<string, WorkspaceDatabaseState>());
  const pending = useRef(new Map<string, Promise<WorkspaceDatabaseState>>());
  const loader = useRef(load);
  loader.current = load;
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<{key:string; data?:WorkspaceDatabaseState; error?:string}>();
  useEffect(() => {
    if (!key || !enabled || cache.current.has(key)) return;
    let active = true;
    setState({key});
    let request = pending.current.get(key);
    if (!request) {
      const currentLoader = loader.current;
      request = Promise.resolve().then(currentLoader);
      pending.current.set(key, request);
    }
    request.then(data => { cache.current.set(key,data); if (active) setState({key,data}); }, error => {
      if (active) setState({key,error:error instanceof Error ? error.message : "Unable to prepare the question database."});
    }).finally(() => { pending.current.delete(key); });
    return () => { active = false; };
  }, [key, enabled, revision]);
  const data = key ? cache.current.get(key) : undefined;
  const error = state?.key === key ? state.error : undefined;
  return { data, error, loading:Boolean(key && enabled && !data && !error), retry:() => {
    if (key) cache.current.delete(key);
    setState(undefined); setRevision(value => value + 1);
  }};
}
