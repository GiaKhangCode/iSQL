import { useState } from "react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { Dialog, Loading } from "@/shared/ui/ui";
import { notify } from "@/shared/ui/notify";
import { adminService, type SandboxSummary } from "@/features/admin/adminService";

/** Từ phải gõ để dọn schema mồ côi (khớp SandboxCleanupService::ORPHAN_CONFIRMATION). */
const ORPHAN_CONFIRMATION = "DON";

type Target = { target: "class"; classId: string; label: string; count: number } | { target: "orphans"; label: string; count: number };

export function SandboxCleanupPanel({ summary, error, onReload, onClose }: {
  summary: SandboxSummary | null;
  error: string;
  onReload: () => Promise<void>;
  onClose: () => void;
}) {
  const { t: tr } = useLanguage("admin");
  const [target, setTarget] = useState<Target | null>(null);
  const [confirm, setConfirm] = useState("");
  const [progress, setProgress] = useState<{ deleted: number; remaining: number } | null>(null);
  const expected = target?.target === "class" ? target.classId : ORPHAN_CONFIRMATION;

  function open(next: Target) {
    setConfirm("");
    setProgress(null);
    setTarget(next);
  }

  async function run() {
    if (!target || confirm !== expected || progress) return;
    let deleted = 0;
    setProgress({ deleted: 0, remaining: target.count });
    try {
      // Máy chủ xóa theo từng đợt; gọi lặp đến khi hết hoặc không xóa thêm được cái nào.
      for (;;) {
        const result = await adminService.cleanSandbox(target.target === "class"
          ? { target: "class", classId: target.classId, confirm }
          : { target: "orphans", confirm });
        deleted += result.deleted;
        setProgress({ deleted, remaining: result.remaining });
        if (result.remaining === 0) { notify.success(tr("Removed {count} schemas.", { count: deleted })); break; }
        if (result.deleted === 0) { notify.error(tr("Removed {count} schemas; {failed} could not be removed.", { count: deleted, failed: result.remaining })); break; }
      }
      setTarget(null);
    } catch (err) {
      notify.fail(tr("Could not clean the schemas."), err, tr);
    } finally {
      setProgress(null);
      await onReload();
    }
  }

  const orphanCount = summary ? summary.totals.orphan + summary.totals.temporary : 0;

  return <div className="admin-overview-details">
    <div className="admin-overview-panel-header"><h2><TranslatedText>{tr("Clean student schemas")}</TranslatedText></h2><button type="button" className="text-button" onClick={onClose}><TranslatedText>{tr("Close")}</TranslatedText></button></div>
    {error ? <><p className="admin-warning" role="alert">{error}</p><button type="button" className="button admin-button" onClick={() => void onReload()}><TranslatedText>{tr("Retry")}</TranslatedText></button></> : !summary ? <Loading variant="table" columns={2} label={tr("Reading schemas from SQL Server…")} /> : <>
      <p className="admin-muted">{tr("Use this when a course has ended. Student work in these schemas is removed for good; submissions and scores are kept.")}</p>

      <h3 className="admin-subheading"><TranslatedText>{tr("By class")}</TranslatedText></h3>
      {summary.classes.length ? <ul className="admin-overview-list">{summary.classes.map(item => <li key={item.id}>
        <div><strong>{item.id}</strong><span>{item.course} · {item.term}</span><span>{tr("{count} schemas", { count: item.schemas })}</span>
          {item.openActivities.length > 0 && <span className="admin-warning">{tr("Still open: {names}", { names: item.openActivities.join("; ") })}</span>}</div>
        <button type="button" className="button admin-button" disabled={item.openActivities.length > 0} onClick={() => open({ target: "class", classId: item.id, label: item.id, count: item.schemas })}><TranslatedText>{tr("Clean")}</TranslatedText></button>
      </li>)}</ul> : <p className="admin-muted"><TranslatedText>{tr("No class has student schemas.")}</TranslatedText></p>}

      <div className="admin-detail-divider" />
      <h3 className="admin-subheading"><TranslatedText>{tr("Orphaned and temporary schemas")}</TranslatedText></h3>
      <p className="admin-muted">{tr("{orphan} schemas no longer belong to any account, activity or problem; {temporary} are temporary grading schemas.", { orphan: summary.totals.orphan, temporary: summary.totals.temporary })}</p>
      {summary.openActivities.length > 0 && <p className="admin-warning">{tr("Still open: {names}", { names: summary.openActivities.join("; ") })}</p>}
      <button type="button" className="button admin-button" disabled={!orphanCount || summary.openActivities.length > 0} onClick={() => open({ target: "orphans", label: tr("Orphaned and temporary schemas"), count: orphanCount })}><TranslatedText>{tr("Clean orphaned schemas")}</TranslatedText></button>
    </>}

    {target && <Dialog title={tr("Remove {count} schemas?", { count: target.count })} onClose={() => { if (!progress) setTarget(null); }}>
      <p><strong>{target.label}</strong></p>
      <p>{tr("This cannot be undone.")}</p>
      <label className="admin-overview-confirm"><span>{tr("Type {word} to confirm", { word: expected })}</span><input value={confirm} disabled={Boolean(progress)} onChange={event => setConfirm(event.target.value)} autoComplete="off" /></label>
      {progress && <p role="status">{tr("Removed {deleted}, {remaining} left…", { deleted: progress.deleted, remaining: progress.remaining })}</p>}
      <div className="admin-overview-confirm-actions">
        <button type="button" className="button" disabled={Boolean(progress)} onClick={() => setTarget(null)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
        <button type="button" className="button primary" disabled={confirm !== expected || Boolean(progress)} onClick={() => void run()}><TranslatedText>{tr(progress ? "Cleaning…" : "Remove schemas")}</TranslatedText></button>
      </div>
    </Dialog>}
  </div>;
}
