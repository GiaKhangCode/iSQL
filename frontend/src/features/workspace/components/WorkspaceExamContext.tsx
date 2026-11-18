import {draftsSynced} from "@/shared/lib/exam/draftSync";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { Link, useBlocker, useNavigate, useSearchParams } from "react-router-dom";
import { apiFetch } from "@/shared/api/apiClient";
import { storage } from "@/shared/api/storage";
import { Dialog, Loading } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useAuth } from "@/app/providers/AuthContext";
import { examClipboard, monitorExamInteractions } from "@/shared/lib/exam/examInteractions";
import { Maximize2, ShieldAlert, CheckCircle2, RefreshCw } from "lucide-react";
import { toast } from "@/shared/ui/StackingToasts";
import "@/styles/workspace-exam.css";

type Scripts = { ddl: string; dml: string; query: string; trigger?: string; revision?: number };
type Attempt = { examViolationLimit?: number; departureCount?: number; showScoresAfterSubmit?: boolean; maxSubmissions?: number | null; submissionCounts?: Record<string, number>; examMode: boolean; problemIds: string[]; status: "not_started" | "active" | "submitted" | "closed"; closesAt?: string | null; serverNow?: string; violationCount: number; drafts: Record<string, Scripts>; finishReason?: string };
type Violation = "focus_lost" | "external_paste" | "leave_attempt" | "focus_returned" | "clipboard_panel";
const violationLimit = (value?: Attempt | null) => value?.examViolationLimit ?? 2;
const isViolation = (kind: Violation) => !["focus_returned","clipboard_panel"].includes(kind);
type ExamContext = { syncStatus: "saved"|"saving"|"unsynced"; showScoresAfterSubmit: boolean; maxSubmissions: number | null; submissionCounts: Record<string, number>; enabled: boolean; closed: boolean; busy: boolean; violations: number; violationLimit: number; departures: number; fullscreen: boolean; enterFullscreen: () => void; draft: (id: string) => Scripts | undefined; update: (id: string, change: Partial<Scripts>) => void; requestSubmit: () => void };
const Context = createContext<ExamContext | null>(null);
const CountdownContext = createContext<number | null>(null);
export const useWorkspaceCountdown = () => useContext(CountdownContext);
export const useWorkspaceExam = () => useContext(Context);

export function WorkspaceExamProvider({ children }: { children: ReactNode }) {
  const { t } = useLanguage("workspace");
  const { session } = useAuth();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const source = params.get("source") || "Practice";
  const activity = params.get("context") || "";
  const eligible = session?.role === "student" && ["Assignments", "ExamSession"].includes(source) && Boolean(activity);
  const endpoint = `/api/student/activity-attempts/${encodeURIComponent(activity)}`;
  const out = session?.examSessionId ? "/examinations" : source === "ExamSession" ? "/examinations" : `/assignments/work/${encodeURIComponent(activity)}`;
  const [attempt, setAttempt] = useState<Attempt | null>(null);
  const [loading, setLoading] = useState(eligible);
  const [error, setError] = useState("");
  const [syncStatus,setSyncStatus]=useState<"saved"|"saving"|"unsynced">("saving");
  const syncStatusRef=useRef(syncStatus);
  syncStatusRef.current=syncStatus;
  const [warning, setWarning] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [departure, setDeparture] = useState<string | null>(null);
  const [fullscreen, setFullscreen] = useState(Boolean(document.fullscreenElement));
  const ownsFullscreen = useRef(false);
  const [fullscreenExitConfirm, setFullscreenExitConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [stopping, setStopping] = useState(false);
  const [remainingMs, setRemainingMs] = useState<number | null>(null);
  const clockOffset = useRef(0);
  const timeoutInFlight = useRef(false);
  const retryTimeoutAt = useRef(0);
  const current = useRef<Attempt | null>(null);
  const drafts = useRef<Record<string, Scripts>>({});
  const activeProblem = useRef("");
  const allowExit = useRef(false);
  const reloading = useRef(false);
  const saveTimer = useRef<ReturnType<typeof setTimeout>>();
  const queue = useRef(Promise.resolve());
  const pendingKey = `sql-practice:exam-events:${session?.id}:${activity}`;
  const clipboard = useMemo(() => examClipboard(pendingKey + ":clipboard"), [pendingKey]);
  const finalSubmitKey = pendingKey + ":submit-on-exit";
  const pending = useRef<{ id: string; kind: Violation; snapshot: object }[]>([]);
  const enabled = Boolean(attempt?.examMode);
  const tracking = eligible && attempt?.status === "active";
  const timeExpired = tracking && remainingMs === 0;
  const active = enabled && tracking && !timeExpired;

  useEffect(() => {
    const locked = () => {
      // A server-enforced incident is not a voluntary departure or exam submission.
      allowExit.current = true;
      clearTimeout(saveTimer.current);
      storage.set(pendingKey + ":drafts", JSON.stringify(drafts.current));
    };
    window.addEventListener("exam-access-locked", locked);
    return () => window.removeEventListener("exam-access-locked", locked);
  }, [pendingKey]);

  function accept(value: Attempt) {
    if (current.current?.status === "submitted" && value.status !== "submitted" && current.current.finishReason !== "violations") return;
    value = { ...value, violationCount: Math.max(current.current?.violationCount || 0, value.violationCount) };
    if (value.serverNow) clockOffset.current = Date.parse(value.serverNow) - Date.now();
    if (value.closesAt) setRemainingMs(Math.max(0, Date.parse(value.closesAt) - Date.now() - clockOffset.current));
    setSyncStatus(draftsSynced(drafts.current,value.drafts||{})?"saved":"saving");
    current.current = value;
    setAttempt(value);
    if (value.status === "submitted") { setWarning(false); setConfirm(false); setStopping(false); storage.remove(finalSubmitKey); clipboard.clear(); }
    else if (value.status === "active") {
      setStopping(value.violationCount + pending.current.filter(event => isViolation(event.kind)).length >= violationLimit(value));
      if (value.violationCount === 0) setWarning(false);
    }
  }
  const snapshot = useCallback(() => {
    const id = activeProblem.current;
    return id ? { problemId: id, ...drafts.current[id] } : {};
  }, []);

  const enterFullscreen = useCallback(() => {
    if (document.fullscreenElement || !document.documentElement.requestFullscreen) return;
    // Navigation may retain a user gesture. Start exam is the explicit fallback
    // when a browser refuses an automatic request after loading or reloading.
    void document.documentElement.requestFullscreen({ navigationUI: "hide" }).then(() => { ownsFullscreen.current = true; setFullscreen(true); }).catch(() => {});
  }, []);
  useEffect(() => {
    const changed = () => {
      const isFs = Boolean(document.fullscreenElement);
      setFullscreen(isFs);
      if (!isFs && ownsFullscreen.current && active && !stopping && !busy) {
        setFullscreenExitConfirm(true);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape" && active && !stopping && !busy) {
        setFullscreenExitConfirm(true);
      }
    };
    document.addEventListener("fullscreenchange", changed);
    window.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("fullscreenchange", changed);
      window.removeEventListener("keydown", onKey);
      if (ownsFullscreen.current && document.fullscreenElement === document.documentElement) void document.exitFullscreen?.().catch(() => {});
    };
  }, [active, stopping, busy]);
  useEffect(() => {
    if (enabled && (attempt?.status === "active" || attempt?.status === "not_started")) enterFullscreen();
    if (attempt?.status === "submitted" && ownsFullscreen.current && document.fullscreenElement === document.documentElement) void document.exitFullscreen?.().catch(() => {});
  }, [enabled, attempt?.status, enterFullscreen]);

  useEffect(() => {
    if (!eligible) { setLoading(false); return; }
    let alive = true;
    apiFetch<Attempt>(`${endpoint}?source=${source}`).then(value => {
      if (!alive) return;
      drafts.current = { ...value.drafts };
      if (value.status === "active") {
        try {
          const local = JSON.parse(storage.get(pendingKey + ":drafts") || "{}");
          for (const [id, script] of Object.entries(local) as [string, Scripts][]) {
            if (value.problemIds.includes(id) && (script.revision || 0) > (drafts.current[id]?.revision || 0)) drafts.current[id] = script;
          }
        } catch { /* Use the server snapshot if local storage is invalid. */ }
      }
      try { pending.current = JSON.parse(storage.get(pendingKey) || "[]"); } catch { pending.current = []; }
      if (value.status === "active" && value.violationCount + pending.current.filter(event => isViolation(event.kind)).length >= violationLimit(value)) setStopping(true);
      if (value.examMode && value.status === "active" && value.violationCount + pending.current.filter(event => isViolation(event.kind)).length > 0 && value.violationCount + pending.current.filter(event => isViolation(event.kind)).length < violationLimit(value)) setWarning(true);
      accept(value);
    }).catch(e => { if (alive) setError(String(e.message)); }).finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; clearTimeout(saveTimer.current); };
  }, [eligible, endpoint, source, pendingKey]);

  // Serialize writes. A late autosave must never replace a newer final snapshot.
  const send = useCallback((action: string, data: object = {}, keepalive = false) => {
    const body = JSON.stringify({ source, ...data });
    const task = async () => {
      if(action === "save")setSyncStatus("saving");
      try {const value=await apiFetch<Attempt>(`${endpoint}/${action}`, { method: "POST", keepalive: keepalive && new Blob([body]).size < 60000, body });
        setSyncStatus(draftsSynced(drafts.current,value.drafts||{})?"saved":"saving");return value;
      } catch(error){setSyncStatus("unsynced");throw error;}
    };
    const result = queue.current.then(task, task);
    queue.current = result.then(() => undefined, () => undefined);
    return result;
  }, [endpoint, source]);

  const flushViolations = useCallback(async () => {
    for (const event of [...pending.current]) {
      // A violation can occur before autosave fires, including just after switching problems.
      for (const [problemId, script] of Object.entries(drafts.current)) await send("save", { problemId, ...script }, true);
      const value = await send(isViolation(event.kind) ? "violation" : "event", { ...event.snapshot, eventId: event.id, kind: event.kind }, true);
      pending.current = pending.current.filter(item => item.id !== event.id);
      storage.set(pendingKey, JSON.stringify(pending.current));
      accept(value);
      if (value.status === "submitted") {
        allowExit.current = true;
        navigate(out, { replace: true });
        return;
      }
    }
  }, [pendingKey, send, out, navigate]);

  const report = useCallback((kind: Violation, durationMs?: number) => {
    if (reloading.current || allowExit.current) return;
    if (!current.current?.examMode || current.current.status !== "active") return;
    if (isViolation(kind) && current.current.violationCount + pending.current.filter(event => isViolation(event.kind)).length >= violationLimit(current.current)) return;
    // Keep idempotent events locally until the server acknowledges them, including offline/reload.
    const event = { id: crypto.randomUUID(), kind, snapshot: {...snapshot(), durationMs} };
    pending.current.push(event);
    storage.set(pendingKey, JSON.stringify(pending.current));
    if (current.current.violationCount + pending.current.filter(event => isViolation(event.kind)).length >= violationLimit(current.current)) { setStopping(true); setConfirm(false); }
    if (isViolation(kind)) setWarning(true);
    void flushViolations().catch(() => setError(t("Connection lost. Keep this page open while your work is saved.")));
  }, [pendingKey, snapshot, flushViolations, t]);

  useEffect(() => {
    if (!tracking) return;
    let retrying=false;
    const retry = () => {
      if(retrying)return;retrying=true;
      void (async () => {
        for (const [problemId, script] of Object.entries(drafts.current)) await send("save", { problemId, ...script });
        if (current.current?.examMode && storage.get(finalSubmitKey) === "1") {
          accept(await send("submit", { drafts: drafts.current, ...snapshot() }, true));
          return;
        }
        await flushViolations();
      })().catch(() => {}).finally(()=>{retrying=false;});
    };
    retry();
    window.addEventListener("online", retry);
    const poll = setInterval(() => {
      if(!draftsSynced(drafts.current,current.current?.drafts||{}))retry();
      void apiFetch<Attempt>(`${endpoint}?source=${source}`).then(accept).catch(() => {});
    }, 5000);
    return () => { window.removeEventListener("online", retry); clearInterval(poll); };
  }, [tracking, flushViolations, send, endpoint, source, finalSubmitKey, snapshot]);

  // Auto-listen for proctor unlock when student is locked for violations
  useEffect(() => {
    if (!eligible || attempt?.status !== "submitted" || attempt?.finishReason !== "violations") return;
    const poll = setInterval(() => {
      void apiFetch<Attempt>(`${endpoint}?source=${source}`).then((value) => {
        if (value.status === "active") {
          toast.show({
            title: "Đã mở khóa bài thi",
            description: "Cán bộ coi thi đã mở khóa bài thi thành công. Bạn có thể tiếp tục làm bài.",
            variant: "success",
          });
          accept(value);
        }
      }).catch(() => {});
    }, 3500);
    return () => clearInterval(poll);
  }, [eligible, attempt?.status, attempt?.finishReason, endpoint, source]);

  const blocker = useBlocker(({ nextLocation }) => {
    if ((!active && !timeExpired) || allowExit.current) return false;
    const next = new URLSearchParams(nextLocation.search);
    const id = decodeURIComponent(nextLocation.pathname.replace(/^\/workspace\//, ""));
    return !(nextLocation.pathname.startsWith("/workspace/") && next.get("source") === source && next.get("context") === activity && attempt?.problemIds.includes(id));
  });
  useEffect(() => {
    if (blocker.state !== "blocked") return;
    setWarning(false);
    setDeparture(blocker.location.pathname + blocker.location.search + blocker.location.hash);
  }, [blocker]);

  useEffect(() => {
    if (!active) return;
    const disposeInteractions = monitorExamInteractions(clipboard, report, (kind,duration) => report(kind,duration));
    const unload = (event: BeforeUnloadEvent) => {
      if (allowExit.current) return;
      if (session?.examSessionId && syncStatusRef.current === 'saved') {reloading.current=true;return;}
      event.preventDefault(); event.returnValue = "";
    };
    const pagehide = () => {
      if (allowExit.current || current.current?.status !== "active") return;
      clearTimeout(saveTimer.current);
      if (session?.examSessionId) {
        storage.set(pendingKey + ':drafts', JSON.stringify(drafts.current));
        // Reload/closed-browser recovery must not turn a temporary exam login into a final submission.
        for (const [problemId,script] of Object.entries(drafts.current)) {
          const body=JSON.stringify({source,problemId,...script});
          if(new Blob([body]).size<60000)void apiFetch(`${endpoint}/save`,{method:'POST',keepalive:true,body}).catch(()=>{});
        }
        return;
      }
      storage.set(finalSubmitKey, "1");
      const complete = JSON.stringify({ source, drafts: drafts.current, ...snapshot() });
      // Browsers cap unload keepalive bodies at 64KiB. Large answers have
      // already been autosaved; the small fallback finalizes that server copy.
      const body = new Blob([complete]).size < 60000 ? complete : JSON.stringify({ source });
      void apiFetch<Attempt>(`${endpoint}/submit`, { method: "POST", keepalive: true, body }).then(accept).catch(() => {});
    };
    const click = (event: MouseEvent) => {
      if ((event.target as Element)?.closest?.("[data-workspace-exit]")) {
        event.preventDefault(); event.stopImmediatePropagation(); setWarning(false); setDeparture(out); return;
      }
      const link = (event.target as Element)?.closest?.("a[href]") as HTMLAnchorElement | null;
      if (!link || link.hash && link.pathname === location.pathname && link.search === location.search) return;
      const url = new URL(link.href);
      const id = decodeURIComponent(url.pathname.replace(/^\/workspace\//, ""));
      if (url.origin === location.origin && url.pathname.startsWith("/workspace/") && url.searchParams.get("context") === activity && url.searchParams.get("source") === source && current.current?.problemIds.includes(id)) return;
      event.preventDefault(); event.stopImmediatePropagation(); setWarning(false); setDeparture(url.href);
    };
    window.addEventListener("beforeunload", unload);
    window.addEventListener("pagehide", pagehide);
    document.addEventListener("click", click, true);
    return () => {
      disposeInteractions(); window.removeEventListener("beforeunload", unload);
      window.removeEventListener("pagehide", pagehide);
      document.removeEventListener("click", click, true);
    };
  }, [active, activity, source, report, endpoint, out, finalSubmitKey, snapshot, clipboard,session?.examSessionId,pendingKey]);

  const update = useCallback((id: string, change: Partial<Scripts>) => {
    if (!eligible || current.current?.status !== "active") return;
    if (activeProblem.current && activeProblem.current !== id) {
      const previousId = activeProblem.current;
      void send("save", { problemId: previousId, ...drafts.current[previousId] }).then(accept)
        .catch(() => setError(t("Connection lost. Keep this page open while your work is saved.")));
    }
    activeProblem.current = id;
    const previous = drafts.current[id] || { ddl: "", dml: "", query: "" };
    setSyncStatus("saving");
    drafts.current[id] = { ...previous, ...change, revision: Math.max(Date.now(), (previous.revision || 0) + 1) };
    if(storage.set(pendingKey + ":drafts", JSON.stringify(drafts.current))===false)
      setError(t("Local backup is unavailable. Keep this page open until your work is saved to the server."));
    clearTimeout(saveTimer.current);
    const data = { problemId: id, ...drafts.current[id] };
    saveTimer.current = setTimeout(() => { void send("save", data).then(accept).catch(() => setError(t("Connection lost. Keep this page open while your work is saved."))); }, 600);
  }, [send, t, pendingKey, eligible]);

  const finishAtDeadline = useCallback(async () => {
    if (timeoutInFlight.current || Date.now() < retryTimeoutAt.current || current.current?.status !== "active") return;
    timeoutInFlight.current = true;
    clearTimeout(saveTimer.current);
    setConfirm(false); setWarning(false); setBusy(true);
    try {
      for (const [problemId, script] of Object.entries(drafts.current)) await send("save", { problemId, ...script });
      accept(await send("timeout", snapshot()));
      setError("");
    } catch {
      retryTimeoutAt.current = Date.now() + 3000;
      setError(t("Time is up. Reconnecting to submit your saved work…"));
    } finally { timeoutInFlight.current = false; setBusy(false); }
  }, [send, snapshot, t]);

  useEffect(() => {
    if (!eligible || !attempt?.closesAt) { setRemainingMs(null); return; }
    const tick = () => {
      const left = Math.max(0, Date.parse(attempt.closesAt!) - Date.now() - clockOffset.current);
      setRemainingMs(left);
      if (left === 0 && current.current?.status === "active") void finishAtDeadline();
    };
    tick();
    const timer = setInterval(tick, 1000);
    document.addEventListener("visibilitychange", tick);
    window.addEventListener("online", tick);
    return () => { clearInterval(timer); document.removeEventListener("visibilitychange", tick); window.removeEventListener("online", tick); };
  }, [eligible, attempt?.closesAt, attempt?.status, finishAtDeadline]);

  async function start() {
    enterFullscreen();
    setBusy(true); setError("");
    try { const value = await send("start"); drafts.current = value.drafts; accept(value); }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  async function submit(destination = out) {
    if (busy) return;
    setBusy(true); setError(""); clearTimeout(saveTimer.current);
    try {
      // Save every visited problem, including one whose debounce was cancelled by switching problems.
      for (const [problemId, script] of Object.entries(drafts.current)) await send("save", { problemId, ...script });
      await flushViolations();
      const value = await send("submit", snapshot());
      accept(value);
      if (value.status === "submitted") {
        allowExit.current = true;
        if (blocker.state === "blocked") blocker.proceed();
        else {
          const target = new URL(destination, location.origin);
          if (target.origin === location.origin) navigate(target.pathname + target.search + target.hash, { replace: true });
          else window.location.assign(target.href);
        }
      }
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  const activityClosed = attempt?.status === "closed";
  const value = useMemo<ExamContext>(() => ({ syncStatus, showScoresAfterSubmit: attempt?.showScoresAfterSubmit !== false, maxSubmissions: attempt?.maxSubmissions ?? null, submissionCounts: attempt?.submissionCounts || {}, enabled, closed: activityClosed, busy, violations: attempt?.violationCount || 0, violationLimit: violationLimit(attempt), departures: attempt?.departureCount || 0,
    fullscreen, enterFullscreen, draft: id => drafts.current[id], update, requestSubmit: () => setConfirm(true) }), [enabled, activityClosed, busy, attempt, update, fullscreen, enterFullscreen, syncStatus]);
  if (loading) return <Loading variant="table" columns={4} />;
  if (eligible && !attempt) return <div className="workspace-exam-gate" role="alert"><h1><TranslatedText>{t("Exam unavailable")}</TranslatedText></h1><p>{error}</p><Link to={out}><TranslatedText>{t("Back")}</TranslatedText></Link></div>;
  if (enabled && attempt?.status === "not_started") return <div className="workspace-exam-gate"><h1><TranslatedText>{t("Exam mode")}</TranslatedText></h1><p><TranslatedText>{t("The exam opens in full screen. Leaving the page submits all problems after confirmation. At {value0} violations, all work is submitted automatically.", {value0:violationLimit(attempt)})}</TranslatedText></p><p><TranslatedText>{t("You can switch between questions and the enabled sections in this examination.")}</TranslatedText></p>{error && <p role="alert">{error}</p>}<button className="button primary" disabled={busy} onClick={() => void start()}><TranslatedText>{t("Start exam")}</TranslatedText></button><Link to={out}><TranslatedText>{t("Back")}</TranslatedText></Link></div>;
  if (activityClosed && (attempt?.examMode || source === "ExamSession")) return <div className="workspace-exam-gate"><h1><TranslatedText>{t("This activity has ended")}</TranslatedText></h1><Link to={out}><TranslatedText>{t("Back")}</TranslatedText></Link></div>;
  if (attempt?.status === "submitted") {
    const isViolationLocked = attempt.finishReason === "violations";
    return (
      <div className="workspace-exam-gate exam-gate-card" style={{ maxWidth: 580, margin: "60px auto", padding: "32px 36px", borderRadius: "16px", boxShadow: "0 10px 30px rgba(0,0,0,0.12)", border: isViolationLocked ? "1px solid rgba(239, 68, 68, 0.3)" : "1px solid var(--border)", background: "var(--surface, #ffffff)" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 14, marginBottom: 16 }}>
          <div style={{ width: 44, height: 44, borderRadius: "50%", background: isViolationLocked ? "rgba(239, 68, 68, 0.12)" : "rgba(34, 197, 94, 0.12)", display: "flex", alignItems: "center", justifyContent: "center", color: isViolationLocked ? "#dc2626" : "#16a34a" }}>
            {isViolationLocked ? <ShieldAlert size={26} /> : <CheckCircle2 size={26} />}
          </div>
          <div>
            <h1 style={{ margin: 0, fontSize: 20, fontWeight: 700, color: isViolationLocked ? "#b91c1c" : "inherit" }}>
              <TranslatedText>{t(isViolationLocked ? "Ca thi đã bị khóa do vi phạm" : attempt.finishReason === "deadline" || attempt.finishReason === "timeout" ? "Hết giờ làm bài" : "Bài thi đã nộp thành công")}</TranslatedText>
            </h1>
            <span style={{ fontSize: 12, color: "var(--muted, #6b7280)" }}>
              {isViolationLocked ? "Ghi nhận vi phạm quy chế thi" : "Ca thi chính thức"}
            </span>
          </div>
        </div>

        {isViolationLocked ? (
          <div style={{ fontSize: 13, lineHeight: 1.6, color: "var(--text, #374151)" }}>
            <p style={{ margin: "0 0 12px" }}>
              Hệ thống đã tự động khóa bài thi của bạn do đạt giới hạn vi phạm (<strong>{attempt.violationCount}/{violationLimit(attempt)} lần</strong> rời màn hình thi hoặc chuyển tab).
            </p>
            <div style={{ background: "rgba(239, 68, 68, 0.05)", borderLeft: "4px solid #ef4444", padding: "10px 14px", borderRadius: "0 8px 8px 0", margin: "14px 0" }}>
              <strong>Hướng dẫn xử lý:</strong> Vui lòng giữ nguyên máy tính và báo ngay cho <strong>Cán bộ coi thi</strong> tại phòng thi. Giám thị sẽ kiểm tra và thực hiện thao tác mở khóa trên hệ thống để bạn tiếp tục làm bài.
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 8, margin: "16px 0 20px", fontSize: 12, color: "#6b7280" }}>
              <span className="exam-pulse-dot" style={{ width: 8, height: 8, borderRadius: "50%", background: "#ef4444", display: "inline-block" }} />
              <span>Đang tự động chờ tín hiệu mở khóa từ Giám thị (kiểm tra mỗi 3s)...</span>
            </div>
          </div>
        ) : (
          <p style={{ fontSize: 13, lineHeight: 1.6, color: "var(--muted, #6b7280)", margin: "0 0 20px" }}>
            <TranslatedText>{t(attempt.finishReason === "deadline" || attempt.finishReason === "timeout" ? "Bài làm của bạn đã được hệ thống tự động lưu và nộp khi hết giờ thi." : "Toàn bộ bài làm của bạn đã được nộp thành công và lưu vết vào hệ thống.")}</TranslatedText>
          </p>
        )}

        <div style={{ display: "flex", gap: 12, marginTop: 12 }}>
          {isViolationLocked && (
            <button
              type="button"
              className="button"
              onClick={() => {
                void apiFetch<Attempt>(`${endpoint}?source=${source}`).then((v) => {
                  if (v.status === "active") accept(v);
                }).catch(() => {});
              }}
            >
              <RefreshCw size={14} /> Kiểm tra mở khóa ngay
            </button>
          )}
          <button
            type="button"
            className="button primary"
            onClick={() => { allowExit.current = true; navigate(out, { replace: true }); }}
          >
            <TranslatedText>{t("Quay lại trang kỳ thi")}</TranslatedText>
          </button>
        </div>
      </div>
    );
  }
  return <Context.Provider value={value}><CountdownContext.Provider value={remainingMs}>
    {children}
    {timeExpired && <Dialog title={t("Time is up")} hideClose closeOnBackdrop={false} closeOnCancel={false} onClose={() => {}}><p><TranslatedText>{t("Submitting your saved work. Keep this page open until it finishes.")}</TranslatedText></p>{error && <p role="alert">{error}</p>}</Dialog>}
    {active && stopping && <Dialog title={t("Submitting…")} hideClose closeOnBackdrop={false} closeOnCancel={false} onClose={() => {}}><p><TranslatedText>{t("Your work is locked after reaching the violation limit. Keep this page open until submission finishes.")}</TranslatedText></p>{error && <p role="alert">{error}</p>}</Dialog>}
    {tracking && !timeExpired && error && <div className="workspace-exam-notice" role="alert">{error}<button onClick={() => { setError(""); for (const [problemId, script] of Object.entries(drafts.current)) void send("save", { problemId, ...script }).then(accept).catch(e => setError(e.message)); }}><TranslatedText>{t("Retry saving")}</TranslatedText></button></div>}
    {warning && active && !stopping && !departure && <Dialog title={t("Exam warning")} onClose={() => setWarning(false)}><p><TranslatedText>{t("Violations: {value0}/{value1}. Reaching the limit automatically submits all your work.", {value0:attempt?.violationCount || 0,value1:violationLimit(attempt)})}</TranslatedText></p><p><TranslatedText>{t("External paste is blocked. Copying within this workspace is allowed.")}</TranslatedText></p><button className="button primary" onClick={() => setWarning(false)}><TranslatedText>{t("Continue exam")}</TranslatedText></button></Dialog>}
    {departure && active && !stopping && <Dialog title={t("Leave exam and submit?")} onClose={() => { if (busy) return; setDeparture(null); setError(""); if (blocker.state === "blocked") blocker.reset(); }}><p><TranslatedText>{t("Leaving this page will submit all problems and lock your exam. Continue?")}</TranslatedText></p>{error && <p role="alert">{error}</p>}<div className="workspace-finish-actions"><button type="button" className="button" disabled={busy} onClick={() => { setDeparture(null); setError(""); if (blocker.state === "blocked") blocker.reset(); }}><TranslatedText>{t("Continue exam")}</TranslatedText></button><button type="button" className="button primary" disabled={busy} onClick={() => void submit(departure)}><TranslatedText>{t(busy ? "Submitting…" : "Submit & Exit")}</TranslatedText></button></div></Dialog>}
    {confirm && <Dialog title={t("Submit & Exit")} onClose={() => { if (!busy) setConfirm(false); }}><p><TranslatedText>{t("Submit all problems in this activity? You cannot edit them after submitting.")}</TranslatedText></p><div className="workspace-finish-actions"><button className="button" disabled={busy} onClick={() => setConfirm(false)}><TranslatedText>{t("Cancel")}</TranslatedText></button><button className="button primary" disabled={busy} onClick={() => void submit()}><TranslatedText>{t(busy ? "Submitting…" : "Submit & Exit")}</TranslatedText></button></div></Dialog>}
    {fullscreenExitConfirm && active && !stopping && <Dialog title={t("Exit full screen?")} closeOnBackdrop={false} closeOnCancel={false} onClose={() => { setFullscreenExitConfirm(false); enterFullscreen(); }}><p><TranslatedText>{t("Exiting full screen will submit your exam. Do you want to submit and exit?")}</TranslatedText></p>{error && <p role="alert">{error}</p>}<div className="workspace-finish-actions"><button type="button" className="button" disabled={busy} onClick={() => { setFullscreenExitConfirm(false); enterFullscreen(); }}><TranslatedText>{t("Stay in full screen")}</TranslatedText></button><button type="button" className="button primary" disabled={busy} onClick={() => { setFullscreenExitConfirm(false); void submit(); }}><TranslatedText>{t(busy ? "Submitting…" : "Confirm submit")}</TranslatedText></button></div></Dialog>}
  </CountdownContext.Provider></Context.Provider>;
}

export function WorkspaceExamFinishButton() {
  const exam = useWorkspaceExam();
  const { t } = useLanguage("workspace");
  if (!exam?.enabled) return null;
  return <div className="workspace-exam-controls">{!exam.fullscreen && <button type="button" className="button" onClick={exam.enterFullscreen}><Maximize2 size={14} /><TranslatedText>{t("Full screen")}</TranslatedText></button>}<button className="button primary" disabled={exam.busy} onClick={exam.requestSubmit}><TranslatedText>{t("Submit & Exit")}</TranslatedText></button></div>;
}
