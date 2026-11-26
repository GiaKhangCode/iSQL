import { useEffect, useMemo, useState } from "react";
import type { QueryResult } from "@/shared/types/queryResult";
import type { WorkspaceDatabaseState } from "@/shared/types/workspaceDatabase";

type Options = {
  /** Đọc lại schema của sinh viên từ máy chủ (không chạy script). */
  load?: () => Promise<WorkspaceDatabaseState>;
  /** Đang xem "Hiện tại": chỉ khi đó mới đọc lại (mỗi lần mở và sau mỗi lần chạy). */
  active?: boolean;
  /** Sinh viên đã chủ động bấm "Hiện tại": hiện cả khi schema còn trống. */
  explicit?: boolean;
};

/**
 * Cơ sở dữ liệu hiện có trong schema riêng của sinh viên.
 * Khi `active`, đọc lại từ máy chủ mỗi lần mở và sau mỗi lần chạy. Trước khi đọc xong (hoặc nếu đọc lỗi) dùng lại
 * dữ liệu của lần chạy gần nhất, đánh dấu `stale` khi lần chạy sau đó lỗi; đổi câu hỏi (key) thì bỏ dữ liệu cũ.
 */
export function useCurrentDatabase(result: QueryResult | null | undefined, key: string, options: Options = {}) {
  const { load, active = false, explicit = false } = options;
  const [saved, setSaved] = useState<{ key: string; data: WorkspaceDatabaseState }>();
  const [fetched, setFetched] = useState<{ key: string; data: WorkspaceDatabaseState }>();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (result?.schemaTables) setSaved({ key, data: { schemaTables: result.schemaTables, tables: result.tables || [] } });
  }, [result, key]);

  useEffect(() => {
    if (!active || !load) return;
    let cancelled = false;
    setLoading(true);
    setError("");
    load()
      .then(data => { if (!cancelled) setFetched({ key, data: { schemaTables: data.schemaTables || [], tables: data.tables || [] } }); })
      .catch(err => { if (!cancelled) setError(err instanceof Error ? err.message : "Could not read your database."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, key, result, tick]);

  const failed = result?.status === "Error";
  const stale = Boolean(failed && !result?.schemaTables);
  const current = useMemo<WorkspaceDatabaseState | null>(() => {
    if (fetched?.key === key && !error && (explicit || fetched.data.schemaTables.length > 0)) return { ...fetched.data, stale: false };
    return saved?.key === key ? { ...saved.data, stale } : null;
  }, [fetched, saved, key, stale, error, explicit]);

  /** Sau khi làm lại từ đầu: bỏ dữ liệu cũ và đọc lại. */
  const reset = () => { setSaved(undefined); setFetched(undefined); setTick(value => value + 1); };

  return { current, loading, error, reset };
}
