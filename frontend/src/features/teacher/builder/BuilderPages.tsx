
import { formatErrorMessage, ApiError } from "@/shared/api/apiClient";
import {Select} from "@/shared/ui/Select";
import { BuilderProblemLibrary } from "@/features/teacher/builder/BuilderProblemLibrary";
import {authService} from "@/features/auth/authService";
import type { AudienceFileData } from "@/shared/lib/exam-session/audienceFiles";
import { AudienceImportDialog } from "@/features/teacher/components/AudienceImportDialog";
import { ExaminationSessionPlanner } from "@/shared/components/ExaminationSessionPlanner";
import { StudentSearchPicker } from "@/shared/components/StudentSearchPicker";
import { initialExaminationPlan, examinationSchedule, sessionNumber, validStudentIdentifier, type ExaminationPlan } from "@/data/examinations";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { toast } from "@/shared/ui/StackingToasts";
import { UnsavedChangesBadge } from "@/shared/ui/UnsavedChangesBadge";
import { useSectionNavigation, useNavigationIndicator } from "@/shared/hooks/useSectionNavigation";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { currentAcademicTerm, normalizeAcademicTerm, formatAcademicTerm } from "@/shared/lib/datetime/academicTerms";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState, useEffect, useLayoutEffect, useRef, useMemo, type CSSProperties } from "react";
import { ArrowDown, ArrowUp, CheckCircle2, ChevronDown, CircleHelp, ImagePlus, Plus, Settings2, X } from "lucide-react";
import { useParams, useLocation, useNavigate } from "react-router-dom";
import { Dialog, Loading } from "@/shared/ui/ui";
import { ProblemRichTextEditor } from "@/features/teacher/problem-editor/ProblemRichTextEditor";
import { teacherService } from "@/shared/api/teacherService";
import { ClassPicker, TeacherField, TeacherSectionTitle } from "@/features/teacher/components/TeacherPageParts";
import { toDateTimeLocal } from "@/shared/lib/datetime/serverDateTime";
import type { WorkspacePart } from "@/data/models";
import { ExamSessionBannerCrop, defaultBannerCrop, type BannerCrop } from "@/shared/components/ExamSessionBannerCrop";
import { resolveExamSessionBannerUrl } from "@/shared/lib/exam-session/examSessionBanner";
import { WORKSPACE_PARTS } from "@/shared/lib/workspace/workspaceParts";
import "@/styles/problem-workspace.css";
import "@/styles/session-management.css";
import { BuilderProblemSettings, type BuilderProblemDetail, type QuestionSelection } from "@/features/teacher/builder/BuilderProblemSettings";
import { BuilderInlineSettings } from "@/features/teacher/builder/BuilderInlineSettings";
import { ExaminationPublishModal } from "@/features/teacher/builder/ExaminationPublishModal";
import { examSessions, type ProctorOption } from "@/shared/api/examSessions";
import { BuilderSelectedQuestions, selectedQuestions } from "@/features/teacher/builder/BuilderSelectedQuestions";
import { DEFAULT_SUBMISSION_LIMIT, SubmissionLimitControl } from "@/features/teacher/submissions/SubmissionLimitControl";
import { assignmentSeed, examSessionSeed, assignmentWorkspaceParts, DateTimeInput, ActivityPreview, type BuilderDraft, type BuilderProblem } from "@/features/teacher/builder/BuilderShared";

export function AssignmentBuilderPage() {
  return <BuilderPage examSession={false} />;
}

export function ExamSessionBuilderPage() {
  return <BuilderPage examSession />;
}

export function ExaminationBuilderPage() { return <BuilderPage examSession examination/>; }

function BuilderPage({ examSession, examination=false }: { examSession: boolean; examination?:boolean }) {
  const { t: tr } = useLanguage("teacher");


  const { id } = useParams();
  const location = useLocation();
  const requestedSession=Number(new URLSearchParams(location.search).get("session")) || undefined;
  const navigate = useNavigate();
  const builderRoot=location.pathname.startsWith("/admin/")?"/admin":location.pathname.startsWith("/office/")?"/office":"/teacher";
  const activityKind=examination?"examinations":examSession?(location.pathname.includes("/sessions/")?"sessions":"exam-sessions"):"assignments";
  const builderListPath=`${builderRoot}/${activityKind}`;
  const [refreshingSessions,setRefreshingSessions]=useState(false);
  const [examinationPlan,setExaminationPlan]=useState<ExaminationPlan>(initialExaminationPlan);
  const isNew = !id || id === "new";
  const draftKey=`sqluit:builder-draft:v1:${authService.restoreSession()?.id || "anonymous"}:${activityKind}:${isNew?"new":id}`;
  const parentBase=useRef<string|null>(null);
  const activeDraftKey=useRef(draftKey);activeDraftKey.current=draftKey;
  const loadedDraftKey=useRef<string|null>(null);
  useLayoutEffect(()=>{loadedDraftKey.current=null;},[draftKey]);
  const parentSignature=(value:{title:string;template:any})=>JSON.stringify({title:value.title,template:{...value.template,classIds:[]}});
  function readLocalDraft():{draft:BuilderDraft;plan:ExaminationPlan;base?:string}|null {
    try {
      const saved=JSON.parse(sessionStorage.getItem(draftKey) || "null");
      return saved && typeof saved.draft?.title==="string" && Array.isArray(saved.draft?.problems) && saved.draft?.studentOptions && (!examination || (Array.isArray(saved.plan?.sessions) && saved.plan.sessions.length<=400)) ? saved : null;
    }catch{return null;}
  }
  
  const [draft, setDraft] = useState<BuilderDraft | null>(null);
  const [parentExaminationId,setParentExaminationId]=useState<string|null>(null);
  const [siblingSessions,setSiblingSessions]=useState<{id:string;title:string}[]>([]);
  const [classAllocations,setClassAllocations]=useState<Record<string,{sourceId:string;label:string}>>({});
  const [classTransfers,setClassTransfers]=useState<Record<string,string>>({});
  const [allocationError,setAllocationError]=useState("");
  const [allocationVersion,setAllocationVersion]=useState(0);
  const [pendingClassTransfer,setPendingClassTransfer]=useState<{classId:string;sourceId:string;sourceLabel:string;next:string[]}|null>(null);
  useEffect(()=>{
    if(!parentExaminationId){setClassAllocations({});setSiblingSessions([]);return;}
    let active=true;
    teacherService.getAssignments().then(rows=>{
      if(!active)return;
      setSiblingSessions(rows.filter(row=>row.examinationId===parentExaminationId).sort((a,b)=>a.sessionIndex-b.sessionIndex).map(row=>({id:row.id,title:row.title || tr("Session {value0}",{value0:sessionNumber(row.sessionIndex)})})));
      const allocations:Record<string,{sourceId:string;label:string}>={};
      for(const row of rows)if(row.examinationId===parentExaminationId && row.id!==id)
        for(const classId of row.classIds || [])allocations[classId]={sourceId:row.id,label:row.title || tr("Session {value0}",{value0:sessionNumber(row.sessionIndex)})};
      setClassAllocations(allocations);setAllocationError("");
    }).catch(error=>{if(active)setAllocationError(error instanceof Error?error.message:tr("Could not load assignments."));});
    return ()=>{active=false;};
  },[parentExaminationId,id,allocationVersion]);
  useLayoutEffect(()=>{
    window.scrollTo({top:0,left:0,behavior:"instant"});
  },[location.pathname,Boolean(draft)]);
  const [csvMode,setCsvMode]=useState<"classes"|"students"|null>(null);
  const [csvBusy,setCsvBusy]=useState(false);
  const latestDraft=useRef(draft);latestDraft.current=draft;
  const [availableClasses, setAvailableClasses] = useState<any[]>([]);
  const [allTeacherClasses, setAllTeacherClasses] = useState<any[]>([]);
  const [allProblems, setAllProblems] = useState<any[]>([]);
  const [proctorOptions, setProctorOptions] = useState<ProctorOption[]>([]);
  const [proctorsLoading, setProctorsLoading] = useState(false);
  const [proctorsError, setProctorsError] = useState("");
  const [classesLoading, setClassesLoading] = useState(true);
  const [classesError, setClassesError] = useState("");
  const [problemsLoading, setProblemsLoading] = useState(true);
  const [problemsError, setProblemsError] = useState("");
  const [draftError, setDraftError] = useState("");
  const [saveBusy, setSaveBusy] = useState(false);
  const retrySave = useRef<(published: boolean, destination?: string) => Promise<void>>(async () => {});
  const [saveState, setSaveState] = useState("");
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  const hasUnsavedChangesRef = useRef(false);
  useEffect(()=>{
    if(loadedDraftKey.current===draftKey && draft && hasUnsavedChanges){
      try{sessionStorage.setItem(draftKey,JSON.stringify({draft,plan:examinationPlan,base:parentBase.current}));}catch{/* The editor remains usable when browser storage is unavailable. */}
    }
  },[examination,draft,examinationPlan,hasUnsavedChanges,draftKey]);
  const [pendingNavigation, setPendingNavigation] = useState<string | null>(null);
  const [alertText, setAlertText] = useState("");
  const [showPublishIssues, setShowPublishIssues] = useState(false);
  const [showPublishModal, setShowPublishModal] = useState(false);
  const [showPublishedOverviewModal, setShowPublishedOverviewModal] = useState(false);
  const [lockedProblemsSnapshot, setLockedProblemsSnapshot] = useState<Record<string, { workspaceParts: WorkspacePart[]; questionSelection?: QuestionSelection }>>({});
  const [highlightError, setHighlightError] = useState<{
    targetId?: string;
    sessionIndex?: number;
    field?: "classes" | "room" | "proctors" | "times" | "session";
    message: string;
    conflictStudents?: Array<{ id: string; name: string; email: string; student_id?: string }>;
  } | null>(null);

  function triggerErrorHighlight(rawMessage: string, conflictStudents?: any[]) {
    if (!rawMessage) return;
    const msg = rawMessage.trim();

    if (examination) {
      const caMatch = msg.match(/(?:ca|ca thi)\s*(\d+)/i);
      const sessionIndex = caMatch ? parseInt(caMatch[1], 10) : undefined;

      let field: "classes" | "room" | "proctors" | "times" | "session" = "session";
      if (/giám thị|proctor/i.test(msg)) {
        field = "proctors";
      } else if (/sinh viên|lớp|student|class|audience/i.test(msg)) {
        field = "classes";
      } else if (/phòng|room/i.test(msg)) {
        field = "room";
      } else if (/giờ|thời gian|starts|ends|chronological|overlap|trùng giờ/i.test(msg)) {
        field = "times";
      }

      if (/tiêu đề|title/i.test(msg)) {
        setHighlightError({ targetId: "builder-title-input", message: msg });
        setTimeout(() => {
          const el = document.getElementById("builder-title-input");
          el?.focus();
          el?.scrollIntoView({ behavior: "smooth", block: "center" });
        }, 50);
        return;
      }

      setHighlightError({
        sessionIndex: sessionIndex ?? (examinationPlan.sessions[0]?.index ?? 1),
        field,
        message: msg,
        conflictStudents,
      });
      return;
    }

    let targetId = "";
    if (/tiêu đề|title/i.test(msg)) {
      targetId = "builder-title-input";
    } else if (/mô tả|description/i.test(msg)) {
      targetId = "exam-session-description";
    } else if (/lớp|sinh viên|class|student|audience/i.test(msg)) {
      targetId = examSession ? "exam-session-audience" : "assignment-audience";
    } else if (/ngày|giờ|starts|ends|opens|closes|due date/i.test(msg)) {
      targetId = examSession ? "exam-session-schedule" : "assignment-schedule";
    } else if (/bài toán|câu hỏi|problem|question/i.test(msg)) {
      targetId = examSession ? "exam-session-problems" : "assignment-problems";
    }

    if (targetId) {
      setHighlightError({ targetId, message: msg });
      setTimeout(() => {
        const el = document.getElementById(targetId);
        el?.scrollIntoView({ behavior: "smooth", block: "center" });
      }, 50);
    }
  }
  const {activeSection:activeExamSessionSection,navigateToSection:navigateToExamSessionSection}=useSectionNavigation(
    examSession ? [...(parentExaminationId?["exam-session-setup","exam-session-schedule","exam-session-problems"]: ["exam-session-setup",...(!examination?["exam-session-audience"]:[]),...(examination?["examination-settings"]:[]),"exam-session-schedule",...(!examination?["exam-session-problems"]:[])])] : ["assignment-setup","assignment-audience","assignment-schedule","assignment-problems"], Boolean(draft));
  const {navigationRef:examSessionNavigationRef,activeItemRef:activeExamSessionNavigationButtonRef,indicator:examSessionNavigationIndicator}=useNavigationIndicator(activeExamSessionSection,`${Boolean(draft)}|${hasUnsavedChanges}`);
  const [addProblemOpen, setAddProblemOpen] = useState(false);
  const [examSessionTargetIndex, setExamSessionTargetIndex] = useState<number | null>(null);
  const [libraryClosing, setLibraryClosing] = useState(false);
  const libraryTrigger = useRef<HTMLButtonElement>(null);
  const libraryScrollAnchor = useRef<number | null>(null);
  useLayoutEffect(() => {
    const top = libraryScrollAnchor.current;
    libraryScrollAnchor.current = null;
    const button = libraryTrigger.current;
    if (top === null || !button) return;
    // The narrower form can reflow sections above the library button.
    const page = button.closest<HTMLElement>(".teacher-builder-page");
    const previousAnchor = page?.style.overflowAnchor || "";
    if (page) page.style.overflowAnchor = "none";
    let frame = 0;
    const start = performance.now();
    const stop = () => {
      cancelAnimationFrame(frame);
      if (page) page.style.overflowAnchor = previousAnchor;
      window.removeEventListener("wheel", stop);
      window.removeEventListener("touchstart", stop);
      window.removeEventListener("keydown", stop);
    };
    const stabilize = () => {
      const delta = button.getBoundingClientRect().top - top;
      if (Math.abs(delta) > 1) window.scrollBy({top:delta,behavior:"instant"});
      if (performance.now() - start < 450) frame = requestAnimationFrame(stabilize);
      else stop();
    };
    stabilize();
    window.addEventListener("wheel", stop, {passive:true});
    window.addEventListener("touchstart", stop, {passive:true});
    window.addEventListener("keydown", stop);
    return stop;
  }, [addProblemOpen, libraryClosing]);
  const [problemDetails, setProblemDetails] = useState<Record<string, BuilderProblemDetail>>({});
  const [catalogErrors, setCatalogErrors] = useState<Record<string, string>>({});
  const [catalogRetry, setCatalogRetry] = useState(0);
  const [settingsProblemId, setSettingsProblemId] = useState<string | null>(null);
  const [settingsClosing, setSettingsClosing] = useState(false);
  const [selectedQuestionsId, setSelectedQuestionsId] = useState<string | null>(null);
  const [pendingDatasetVisibility, setPendingDatasetVisibility] = useState<Record<string, boolean>>({});
  const [pendingQuestionSelection, setPendingQuestionSelection] = useState<QuestionSelection>({});
  const [pendingVisibility, setPendingVisibility] = useState({ databaseStructureVisible: true, databaseDescriptionVisible: true });
  const [pendingParts, setPendingParts] = useState<WorkspacePart[]>([...WORKSPACE_PARTS]);
  const settingsTrigger = useRef<HTMLElement | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [audiencePanel,setAudiencePanel]=useState<"classes"|"students"|null>("classes");
  const [audiencePanelClosing,setAudiencePanelClosing]=useState(false);
  const nextAudiencePanel=useRef<"classes"|"students"|null>(null);
  const [individualMode,setIndividualMode]=useState(false);
  const [previewClosing, setPreviewClosing] = useState(false);
  const [previewWidth, setPreviewWidth] = useState(420);
  const [expandedTextField, setExpandedTextField] = useState<"description" | "instructions" | null>(null);
  const [eligibleStudents, setEligibleStudents] = useState<number | null>(null);
  const [individualStudents, setIndividualStudents] = useState<number | null>(null);
  const [overlappingStudents, setOverlappingStudents] = useState(0);
  const [audienceError, setAudienceError] = useState("");
  const examSessionSplitLayoutRef = useRef<HTMLDivElement>(null);
  const bannerFileInput = useRef<HTMLInputElement>(null);
  const [cropSource, setCropSource] = useState<string | null>(null);
  const [cropInitial, setCropInitial] = useState<BannerCrop>(defaultBannerCrop);

  useEffect(() => {
    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      if (!hasUnsavedChangesRef.current) return;
      event.preventDefault();
      event.returnValue = "";
    };
    const handleNavigationClick = (event: MouseEvent) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const target = event.target;
      if (!(target instanceof Element)) return;
      const anchor = target.closest<HTMLAnchorElement>("a[href]");
      if (!anchor || anchor.target === "_blank" || anchor.hasAttribute("download")) return;
      if (anchor.closest(".role-header")) return;
      const destination = new URL(anchor.href, window.location.href);
      if (destination.origin !== window.location.origin || !hasUnsavedChangesRef.current) return;
      if(examination && anchor.closest(".examination-session-links"))return;
      if (destination.pathname === location.pathname && destination.search === location.search) return;
      event.preventDefault();
      event.stopPropagation();
      setPendingNavigation(`${destination.pathname}${destination.search}${destination.hash}`);
    };
    window.addEventListener("beforeunload", handleBeforeUnload);
    document.addEventListener("click", handleNavigationClick, true);
    return () => {
      window.removeEventListener("beforeunload", handleBeforeUnload);
      document.removeEventListener("click", handleNavigationClick, true);
    };
  }, [location.pathname, location.search]);

  useEffect(() => {
    setDraft(null);setDraftError("");
    hasUnsavedChangesRef.current=false;setHasUnsavedChanges(false);
    setClassTransfers({});setPendingClassTransfer(null);
    async function fetchClasses() {
      try {
        const data = await teacherService.getClasses();
        setAllTeacherClasses(data);
        const activeClasses = data.filter((c: any) => c.status === "Active" && (examSession || normalizeAcademicTerm(c.term || "")===currentAcademicTerm()));
        setAvailableClasses(activeClasses);
        const validIds = new Set(activeClasses.map((c: any) => c.id));
        setExaminationPlan(current => {
          if (!current || !current.sessions) return current;
          let changed = false;
          const sanitizedSessions = current.sessions.map(s => {
            const filtered = (s.classIds || []).filter(id => validIds.has(id));
            if (filtered.length !== (s.classIds || []).length) {
              changed = true;
              return { ...s, classIds: filtered };
            }
            return s;
          });
          return changed ? { ...current, sessions: sanitizedSessions } : current;
        });
      } catch (e) {
        setClassesError(e instanceof Error ? e.message : "Could not load classes.");
      } finally {
        setClassesLoading(false);
      }
    }
    fetchClasses();

    async function fetchProblems() {
      try {
        const data = await teacherService.getAllProblems();
        setAllProblems(data);
      } catch (e) {
        setProblemsError(e instanceof Error ? e.message : "Could not load problems.");
      } finally {
        setProblemsLoading(false);
      }
    }
    fetchProblems();

    if (isNew) {
      loadedDraftKey.current=draftKey;
      const saved=readLocalDraft();
      if(saved){
        const allSavedClassIds = (examination && saved.plan)
          ? [...new Set(saved.plan.sessions.flatMap((slot: any) => slot.classIds || []))]
          : (saved.draft.classIds || []);
        setDraft({...saved.draft, classIds: allSavedClassIds});
        if(examination&&saved.plan)setExaminationPlan(saved.plan);
        markUnsaved();setSaveState("Unsaved changes");return;
      }
      const emptyPlan=initialExaminationPlan();
      if(examination)setExaminationPlan(emptyPlan);
      setDraft(examination ? {...examSessionSeed,...examinationSchedule(emptyPlan),studentOptions:{...examSessionSeed.studentOptions,examMode:true,maxSubmissions:DEFAULT_SUBMISSION_LIMIT}} : examSession ? examSessionSeed : assignmentSeed);
      return;
    }
    
    async function fetchAssignment() {
      try {
        const loaded=examination ? await teacherService.getExamination(id!) : await teacherService.getAssignment(id!);
        if(activeDraftKey.current!==draftKey)return;
        loadedDraftKey.current=draftKey;
        if(examination)parentBase.current=parentSignature(loaded);
        setParentExaminationId(!examination ? loaded.examinationId || null : null);
        const data=examination ? {...loaded.template,title:loaded.title,problemList:loaded.template.problems,classIds:[...new Set(loaded.sessions.flatMap((slot:any)=>slot.classIds))]} : loaded;
        if(examination) setExaminationPlan({examDate:loaded.examDate,sessions:loaded.sessions,expectedRevision:loaded.revision});
        setDraft({
          title: data.title,
          classIds: data.classIds || [],
          studentEmails: data.studentEmails || [],
          audienceType: data.audienceType || "classes",
          shortDescription: data.shortDescription || "",
          description: data.description || "",
          rules: data.rules || (examSession ? data.instructions || "" : ""),
          bannerUrl: examSession ? data.bannerUrl || null : null,
          bannerSourceUrl: examSession ? data.bannerSourceUrl || null : null,
          bannerCrop: examSession ? data.bannerCrop || null : null,
          instructions: data.instructions || "",
          opens: examination ? examinationSchedule(loaded).opens : toDateTimeLocal(data.opens),
          closes: examination ? examinationSchedule(loaded).closes : toDateTimeLocal(data.closes),
          problems: (examination ? [] : data.problemList || []).map((item: BuilderProblem) => ({
            ...item,
            workspaceParts: assignmentWorkspaceParts(item.workspaceParts),
          })),
          studentOptions: { ...(examSession ? examSessionSeed.studentOptions : assignmentSeed.studentOptions), ...data.studentOptions, ...(examination?{examMode:true}:{}) },
          published: data.published
        });
        if (!examination && !examSession && data.published) {
          const snapshot: Record<string, { workspaceParts: WorkspacePart[]; questionSelection?: QuestionSelection }> = {};
          (data.problemList || []).forEach((item: BuilderProblem) => {
            snapshot[item.id] = {
              workspaceParts: assignmentWorkspaceParts(item.workspaceParts),
              questionSelection: item.questionSelection,
            };
          });
          setLockedProblemsSnapshot(snapshot);
        } else {
          setLockedProblemsSnapshot({});
        }
        const saved=readLocalDraft();
        if(saved){
          const serverIds = new Set((data.problemList || []).map((item: BuilderProblem) => item.id));
          const savedIds = new Set((saved.draft.problems || []).map((item: BuilderProblem) => item.id));
          const compatibleWithPublished = !data.published || [...serverIds].every((pid) => savedIds.has(pid));
          if (!compatibleWithPublished) {
            try { sessionStorage.removeItem(draftKey); } catch { /* Ignore storage error */ }
          } else {
            setDraft(examination ? {...saved.draft, published: data.published, classIds:[...new Set(loaded.sessions.flatMap((slot:any)=>slot.classIds))] as string[]} : {...saved.draft, published: data.published});
            if(examination && saved.plan){
              const unchangedParent=saved.base===parentBase.current;
              parentBase.current=saved.base || null;
              setExaminationPlan({...saved.plan,sessions:unchangedParent?saved.plan.sessions:[...loaded.sessions,...saved.plan.sessions.filter((slot:any)=>!slot.id && !loaded.sessions.some((current:any)=>current.index===slot.index))],expectedRevision:unchangedParent?loaded.revision:saved.plan.expectedRevision});
            }
            markUnsaved();setSaveState("Unsaved changes");
          }
        }
      } catch (e) {
        setDraftError(e instanceof Error ? e.message : "Could not load this activity.");
      }
    }
    fetchAssignment();
  }, [id, isNew, examSession, examination]);

  const audienceType = draft?.audienceType;
  const selectedIds = draft?.classIds.join("|") || "";
  const selectedEmails=examination ? [...new Set(examinationPlan.sessions.flatMap(slot=>slot.studentEmails))].join("|") : (draft?.studentEmails || []).join("|");
  // Lecturer accounts the office can choose as proctors of each class (examinations only).
  useEffect(() => {
    if (!examination) return;
    let active = true;
    setProctorsLoading(true);
    setProctorsError("");
    examSessions.proctors()
      .then(rows => { if (active) setProctorOptions(rows); })
      .catch(error => { if (active) setProctorsError(error instanceof Error ? error.message : "Could not load lecturers."); })
      .finally(() => { if (active) setProctorsLoading(false); });
    return () => { active = false; };
  }, [examination]);

  useEffect(() => {
    setOverlappingStudents(0);
    setIndividualStudents(null);
    setAudienceError("");
    if (!audienceType || (audienceType === "classes" && !selectedIds && !selectedEmails)) {
      setEligibleStudents(0);
      return;
    }
    let active = true;
    setEligibleStudents(null);
    const emails=selectedEmails?selectedEmails.split("|"):[];
    if(emails.some(email=>!validStudentIdentifier(email))) return;
    const timer=window.setTimeout(()=>teacherService.getAudienceCount(audienceType, selectedIds ? selectedIds.split("|") : [], emails)
      .then((result) => { if (active) { setEligibleStudents(result.eligibleStudents); setIndividualStudents(result.individualStudents ?? emails.length); setOverlappingStudents(result.overlappingStudents ?? 0);
        if(!examination && result.coveredIndividuals?.length){
          const covered=result.coveredIndividuals;
          const remaining=emails.filter(value=>!covered.some(student=>[student.email.toLowerCase(),student.id.toLowerCase(),student.studentNumber.toLowerCase()].includes(value.toLowerCase())));
          if(remaining.length!==emails.length){
            if (hasUnsavedChangesRef.current) {
              update("studentEmails",remaining);
            } else {
              setDraft(current => current ? { ...current, studentEmails: remaining } : null);
            }
            toast.show({variant:"info",title:tr("Removed students already included in selected classes"),description:covered.map(student=>tr("{value0} is already in {value1}; individual selection removed.",{value0:student.name,value1:student.classes.join(", ")})).join(" ")});
          }
        } } })
      .catch(error => { if (active) { setEligibleStudents(null); setAudienceError(error instanceof Error ? error.message : tr("Student count unavailable")); } }),350);
    return () => { active = false; window.clearTimeout(timer); };
  }, [audienceType, selectedIds,selectedEmails]);

  const examProblemIds = examination ? examinationPlan.sessions.flatMap(s => (s.problems || []).map((p: any) => p.id)) : [];
  const catalogIds = [...new Set([...(draft?.problems || []).map(item => item.id), ...examProblemIds])].join("|");
  useEffect(() => {
    let active = true;
    for (const problemId of catalogIds.split("|").filter(Boolean)) {
      Promise.resolve().then(() => teacherService.getProblem(problemId)).then(detail => {
        if (active) {setProblemDetails(current => ({...current, [problemId]: detail})); setCatalogErrors(current => ({...current, [problemId]: ""}));}
      }).catch(error => {if (active) setCatalogErrors(current => ({...current, [problemId]: error instanceof Error ? error.message : tr("Could not load problem details.")}));});
    }
    return () => {active = false;};
  }, [catalogIds, catalogRetry]);

  async function refreshSessionTimes() {
    if (isNew || refreshingSessions) return;
    setRefreshingSessions(true);
    const key=draftKey;
    try {
      const latest=await teacherService.getExamination(id!);
      if(activeDraftKey.current!==key)return;
      const metadata=(slot:any)=>{const {examDate,starts,ends,...rest}=slot;return JSON.stringify(rest);};
      setExaminationPlan(plan=>{
        const saved=plan.sessions.filter(slot=>slot.id);
        const unchanged=parentSignature(latest)===parentBase.current && saved.length===latest.sessions.length && saved.every(slot=>metadata(slot)===metadata(latest.sessions.find((row:any)=>row.id===slot.id) || {}));
        return {...plan,expectedRevision:unchanged?latest.revision:plan.expectedRevision,sessions:plan.sessions.map(slot=>{
          const fresh=latest.sessions.find((row:any)=>row.id===slot.id);
          return fresh?{...slot,examDate:fresh.examDate || latest.examDate,starts:fresh.starts,ends:fresh.ends}:slot;
        })};
      });
    } catch {
      setAlertText("Could not refresh session times.");
    } finally {setRefreshingSessions(false);}
  }

  if (!draft) {
    return <div className="teacher-page">{draftError ? <div className="empty-state" role="alert"><TranslatedText>{tr(draftError)}</TranslatedText></div> : <Loading variant="form" label={tr("Loading builder…")} />}</div>;
  }

  async function importAudienceCsv(parsed:AudienceFileData) {
    if(!draft || csvBusy)return;
    setCsvBusy(true);
    const snapshot=draft;
    try{
      const classIds=[...new Set([...snapshot.classIds,...parsed.classIds])];
      const studentEmails=[...new Set([...snapshot.studentEmails,...parsed.studentEmails])];
      const result=await teacherService.getAudienceCount("classes",classIds,studentEmails);
      const current=latestDraft.current;
      if(!current || current.audienceType!==snapshot.audienceType || current.classIds.join("|")!==snapshot.classIds.join("|") || current.studentEmails.join("|")!==snapshot.studentEmails.join("|"))throw new Error(tr("Selection changed. Import the CSV again."));
      setDraft(value=>value?{...value,classIds,studentEmails:result.resolvedStudentEmails ?? studentEmails}:value);
      markUnsaved();setSaveState("Unsaved changes");
      toast.show({variant:"success",title:tr("CSV imported"),description:tr("{value0} eligible students",{value0:result.eligibleStudents})});
      if(result.coveredIndividuals?.length)toast.show({variant:"info",title:tr("Removed students already included in selected classes"),description:result.coveredIndividuals.map(student=>tr("{value0} is already in {value1}; individual selection removed.",{value0:student.name,value1:student.classes.join(", ")})).join(" ")});
    }finally{setCsvBusy(false);}
  }

  function markUnsaved() {
    hasUnsavedChangesRef.current = true;
    setHasUnsavedChanges(true);
  }

  function markSaved() {
    try{sessionStorage.removeItem(draftKey);}catch{/* Saving to the server still succeeds without browser storage. */}
    hasUnsavedChangesRef.current = false;
    setHasUnsavedChanges(false);
  }

  function requestNavigation(destination: string) {

    if (hasUnsavedChangesRef.current) setPendingNavigation(destination);
    else navigate(destination);
  }

  function update<K extends keyof BuilderDraft>(field: K, value: BuilderDraft[K]) {
    if(examination && field === "classIds") setExaminationPlan(plan=>({...plan,sessions:plan.sessions.map(slot=>({...slot,classIds:slot.classIds.filter(id=>(value as string[]).includes(id))}))}));
    setDraft((current) => current ? ({ ...current, [field]: value }) : null);
    markUnsaved();
    setSaveState("Unsaved changes");
    setAlertText("");
    setShowPublishIssues(false);
  }

  function updateSessionClasses(next:string[]) {
    const added=next.find(classId=>!draft?.classIds.includes(classId));
    const allocation=added?classAllocations[added]:undefined;
    if(added && allocation){setPendingClassTransfer({classId:added,sourceId:allocation.sourceId,sourceLabel:allocation.label,next});return;}
    setClassTransfers(current=>Object.fromEntries(Object.entries(current).filter(([classId])=>next.includes(classId))));
    update("classIds",next);
  }

  function updateProblemSettings(problemId: string, patch: Partial<BuilderProblem>) {
    if (examination) {
      setExaminationPlan(current => ({
        ...current,
        sessions: current.sessions.map(s => {
          if (!s.problems?.some((p: any) => p.id === problemId)) return s;
          return {
            ...s,
            problems: (s.problems || []).map((p: any) => (p.id === problemId ? { ...p, ...patch } : p)),
          };
        }),
      }));
      setDraft(current => current ? { ...current, problems: current.problems.map(row => row.id === problemId ? { ...row, ...patch } : row) } : null);
      markUnsaved();
      setSaveState("Unsaved changes");
      setAlertText("");
      setShowPublishIssues(false);
      return;
    }
    setDraft(current => current ? { ...current, problems: current.problems.map(row => row.id === problemId ? { ...row, ...patch } : row) } : null);
    markUnsaved();
    setSaveState("Unsaved changes");
    setAlertText("");
    setShowPublishIssues(false);
  }

  function toggleAudiencePanel(panel:"classes"|"students") {
    nextAudiencePanel.current=audiencePanel===panel && !audiencePanelClosing?null:panel;
    if(!audiencePanel){setAudiencePanel(panel);setAudiencePanelClosing(false);}
    else setAudiencePanelClosing(true);
  }
  function finishAudiencePanelClose() {
    setAudiencePanel(nextAudiencePanel.current);setAudiencePanelClosing(false);
    if(!nextAudiencePanel.current)document.querySelector<HTMLButtonElement>(".teacher-audience-collapse, .teacher-add-class")?.focus({preventScroll:true});
  }

  function closeCrop() {
    if (cropSource?.startsWith("blob:")) URL.revokeObjectURL(cropSource);
    setCropSource(null);
  }

  function chooseBanner(file?: File) {
    if (!file) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
      setAlertText("Choose a JPEG, PNG, or WebP image.");
      return;
    }
    if (file.size > 12 * 1024 * 1024) {
      setAlertText("Choose an image smaller than 12 MB.");
      return;
    }
    closeCrop();
    setAlertText("");
    setCropInitial(defaultBannerCrop);
    setCropSource(URL.createObjectURL(file));
  }

  function adjustBanner() {
    if (!draft?.bannerSourceUrl) return;
    try {
      const saved = JSON.parse(draft.bannerCrop || "null") as BannerCrop | null;
      setCropInitial(saved && Number.isFinite(saved.x) && Number.isFinite(saved.y) && Number.isFinite(saved.zoom) ? saved : defaultBannerCrop);
    } catch {
      setCropInitial(defaultBannerCrop);
    }
    setCropSource(resolveExamSessionBannerUrl(draft.bannerSourceUrl) || draft.bannerSourceUrl);
  }

  async function applyBanner(banner: Blob, source: Blob, crop: BannerCrop) {
    const urls = await teacherService.uploadExamSessionBanner(banner, source);
    setDraft((current) => current ? { ...current, ...urls, bannerCrop: JSON.stringify(crop) } : null);
    markUnsaved();
    setSaveState("Unsaved changes");
    setAlertText("");
    closeCrop();
  }

  const selectedCounts = draft.problems.map(item => Object.values(selectedQuestions(problemDetails[item.id] || null, item.workspaceParts || [], item.questionSelection)).reduce((sum, questions) => sum + questions.length, 0));
  const totalQuestions = selectedCounts.reduce((sum, count) => sum + count, 0);
  const weightedProblems = draft.problems.map((item, index) => ({...item, points: totalQuestions ? 10 * selectedCounts[index] / totalQuestions : 0}));
  const scheduleValid =
    !!draft.opens && !!draft.closes && new Date(draft.opens) < new Date(draft.closes);
  const durationMinutes = scheduleValid ? Math.round((new Date(draft.closes).getTime() - new Date(draft.opens).getTime()) / 60000) : 0;
  function getPublishIssues() {
    if (!draft) return ["Wait for the builder to load."];
    const issues: string[] = [];
    const examinationClassIds = examination
      ? [...new Set(examinationPlan.sessions.flatMap(slot => slot.classIds || []))]
      : draft.classIds;

    if (!draft.title.trim()) issues.push("Enter a title.");
    if(selectedEmails.split("|").filter(Boolean).some(email=>!validStudentIdentifier(email)))issues.push("Check the student IDs or email addresses.");
    if(examination && examinationClassIds.some(id=>!examinationPlan.sessions.some(slot=>(slot.classIds || []).includes(id))))issues.push("Assign all selected classes to sessions.");
    if(examination && examinationPlan.sessions.some((slot,index)=>!slot.starts || !slot.ends || slot.ends<=slot.starts || index>0 && `${examinationPlan.sessions[index-1].examDate || examinationPlan.examDate}T${examinationPlan.sessions[index-1].ends}`>`${slot.examDate || examinationPlan.examDate}T${slot.starts}`))issues.push("Session times must be chronological and must not overlap.");
    if (!examSession && draft.classIds.length === 0 && !draft.studentEmails.length) issues.push("Select at least one class.");
    if (examSession && !examination && draft.audienceType === "classes" && draft.classIds.length === 0 && !selectedEmails) issues.push("Select at least one class.");
    if (examination && examinationClassIds.length === 0 && !selectedEmails) issues.push("Select at least one class.");
    if (examSession && !examination && !draft.shortDescription.trim()) issues.push("Add a short description.");
    if (examSession && !examination && !draft.description.trim()) issues.push("Add an exam session description.");
    if (!examination && draft.problems.length === 0) issues.push("Add at least one problem.");
    if (examination) {
      if (examinationPlan.sessions.length === 0) {
        issues.push("Add at least one exam session.");
      } else {
        const missingSessionProblem = examinationPlan.sessions.some((slot) => !(slot.problems && slot.problems.length > 0));
        if (missingSessionProblem) {
          issues.push("Add at least one problem to every session.");
        }
        const invalidSessionSelection = examinationPlan.sessions.some((slot) =>
          (slot.problems || []).some((sp: any) => {
            const detail = problemDetails[sp.id];
            if (!detail) return true;
            const parts = sp.workspaceParts || [];
            if (!parts.length) return true;
            const count = Object.values(selectedQuestions(detail, parts, sp.questionSelection)).reduce((sum, list) => sum + list.length, 0);
            return count === 0;
          }),
        );
        if (invalidSessionSelection) {
          issues.push("Select at least one question and section for each session problem.");
        }
      }
    } else {
      if (draft.problems.some((item, index) => !problemDetails[item.id] || !selectedCounts[index])) issues.push("Select at least one question for each problem.");
      if (draft.problems.some((item) => !(item.workspaceParts?.length))) issues.push("Select at least one section for each problem.");
    }
    if (!draft.opens || !Number.isFinite(new Date(draft.opens).getTime())) issues.push(examSession ? "Enter a valid start date and time." : "Enter a valid opening date and time.");
    if (!draft.closes || !Number.isFinite(new Date(draft.closes).getTime())) issues.push(examSession ? "Enter a valid end date and time." : "Enter a valid due date and time.");
    if (draft.opens && draft.closes && Number.isFinite(new Date(draft.opens).getTime()) && Number.isFinite(new Date(draft.closes).getTime()) && new Date(draft.opens) >= new Date(draft.closes)) {
      issues.push("The end date and time must be after the start date and time.");
    }
    return issues;
  }
  const publishIssues = getPublishIssues();
  const readyToPublish = publishIssues.length === 0;

  async function saveToServer(isPublished: boolean, exitDestination?: string): Promise<boolean> {
    if (saveBusy) return false;
    if (!draft) return false;
    if (isPublished) {
      setAlertText("");
      setShowPublishIssues(true);
      if (!readyToPublish) {
        triggerErrorHighlight(publishIssues[0]);
        return false;
      }
    }
    
    setSaveBusy(true);
    setSaveState("Saving to server...");
    const notification = toast.show({ variant: "loading", title: "Saving…", description: draft.title });
    try {
      const payload = {
        title: draft!.title,
        classIds: draft!.classIds || [],
        ...(parentExaminationId?{classTransfers:Object.fromEntries(Object.entries(classTransfers).filter(([classId])=>draft!.classIds.includes(classId)))}:{}),
        studentEmails: examination ? [] : draft!.studentEmails,
        audienceType: "classes",
        shortDescription: examSession ? draft!.shortDescription.trim() : "",
        description: examSession ? draft!.description : "",
        rules: examSession ? draft!.rules : "",
        ...(examSession ? { bannerUrl: draft!.bannerUrl, bannerSourceUrl: draft!.bannerSourceUrl, bannerCrop: draft!.bannerCrop } : {}),
        instructions: examSession ? "" : draft!.instructions,
        opens: draft!.opens ? new Date(draft!.opens).toISOString() : null,
        closes: draft!.closes ? new Date(draft!.closes).toISOString() : null,
        problems: examination ? [] : weightedProblems,
        scoringMode: "equal_questions",
        studentOptions: examSession
          ? { leaderboard: draft!.studentOptions.leaderboard, hints: draft!.studentOptions.hints ?? true, aiAllowed: draft!.studentOptions.aiAllowed ?? true, examMode: draft!.studentOptions.examMode, examViolationLimit: draft!.studentOptions.examViolationLimit ?? 2, showScoresAfterSubmit: draft!.studentOptions.showScoresAfterSubmit, maxSubmissions: draft!.studentOptions.maxSubmissions }
          : { comments: draft!.studentOptions.comments, hints: draft!.studentOptions.hints ?? true, aiAllowed: draft!.studentOptions.aiAllowed ?? true, examMode: draft!.studentOptions.examMode, examViolationLimit: draft!.studentOptions.examViolationLimit ?? 2, showScoresAfterSubmit: draft!.studentOptions.showScoresAfterSubmit, maxSubmissions: draft!.studentOptions.maxSubmissions },
        published: isPublished
      };
      
      let savedActivity: any = null;
      if(examination) {
        const sanitizedPlan = {
          ...examinationPlan,
          title: payload.title,
          template: {
            ...payload,
            problems: weightedProblems
          },
          problems: weightedProblems,
          sessions: examinationPlan.sessions.map(s => {
            const sessionProblems = (s.problems && s.problems.length > 0)
              ? s.problems.map((sp: any) => {
                  const match = weightedProblems.find((wp: any) => wp.id === sp.id);
                  return match ? { ...sp, ...match, questionSelection: match.questionSelection } : sp;
                })
              : weightedProblems;
            return {
              ...s,
              problems: sessionProblems,
              classIds: availableClasses.length > 0 ? (s.classIds || []).filter(id => availableClasses.some(c => c.id === id)) : (s.classIds || []),
              classProctors: Object.fromEntries(Object.entries(s.classProctors || {}).filter(([classId]) => (s.classIds || []).includes(classId)))
            };
          })
        };
        savedActivity=await teacherService.saveExamination(sanitizedPlan,isNew?undefined:id);
        parentBase.current=parentSignature(savedActivity);
        setExaminationPlan({examDate:savedActivity.examDate,sessions:savedActivity.sessions,expectedRevision:savedActivity.revision});
      } else if (isNew) {
        savedActivity = await teacherService.createAssignment(payload);
      } else {
        await teacherService.updateAssignment(id!, payload);
      }
      
      setDraft({ ...draft!, published: isPublished });
      if (!examination && !examSession && isPublished) {
        const snapshot: Record<string, { workspaceParts: WorkspacePart[]; questionSelection?: QuestionSelection }> = {};
        (draft?.problems || []).forEach((item) => {
          snapshot[item.id] = {
            workspaceParts: assignmentWorkspaceParts(item.workspaceParts),
            questionSelection: item.questionSelection,
          };
        });
        setLockedProblemsSnapshot(snapshot);
      }
      markSaved();
      setClassTransfers({});setAllocationVersion(version=>version+1);
      setSaveState("All changes saved");
      toast.update(notification, { variant: "success", title: isPublished ? "Successfully published." : "All changes saved" });
      setAlertText("");
      setShowPublishIssues(false);
      
      const listPath = builderListPath;
      if (exitDestination) {
        navigate(exitDestination);
      } else if (examination && isPublished) {
        setShowPublishedOverviewModal(true);
        if (isNew && savedActivity?.id) {
          navigate(`${builderListPath}/${savedActivity.id}/edit`, { replace: true });
        }
      } else if (isNew && isPublished) {
        navigate(listPath);
      } else if (isNew) {
        const savedId = savedActivity?.id;
        navigate(savedId ? `${builderListPath}/${savedId}/edit` : listPath, { replace: true });
      }
      return true;
    } catch (e) {
      const message = e instanceof Error ? e.message : "Could not save to server.";
      const formattedMsg = formatErrorMessage(message);
      const apiData = e instanceof ApiError ? e.data : undefined;
      const detail = apiData?.detail ?? apiData;
      const conflictStudents = detail?.conflict_students || apiData?.conflict_students || [];
      setSaveState(formattedMsg);
      toast.update(notification, { variant: "error", title: formattedMsg, duration: 0, action: { label: "Retry", onClick: () => void retrySave.current(isPublished, exitDestination) } });
      setAlertText("");
      triggerErrorHighlight(formattedMsg, conflictStudents);
      return false;
    } finally {
      setSaveBusy(false);
    }
  }

  retrySave.current = saveToServer;

  function saveDraft() {
    void saveToServer(Boolean(draft?.published));
  }

  async function publishDraft() {
    if (examination) {
      const issues = getPublishIssues();
      if (issues.length > 0) {
        setShowPublishIssues(true);
        triggerErrorHighlight(issues[0]);
        return;
      }
      setShowPublishIssues(false);
      if (hasUnsavedChangesRef.current) {
        const saved = await saveToServer(Boolean(draft?.published));
        if (!saved) return;
      }
      setShowPublishModal(true);
      return;
    }
    void saveToServer(true);
  }

  function getMaxPreviewWidth() {
    const availableWidth = examSessionSplitLayoutRef.current?.clientWidth ?? 1200;
    return window.innerWidth <= 900
      ? Math.max(320, Math.min(680, availableWidth - 332, window.innerWidth * 0.42))
      : Math.max(320, Math.min(680, availableWidth - 584));
  }

  function resizePreviewTo(width: number) {
    setPreviewWidth(Math.max(320, Math.min(getMaxPreviewWidth(), width)));
  }

  function closeExamSessionPreview() {
    if (!previewOpen || previewClosing) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setPreviewOpen(false);
      return;
    }
    setPreviewClosing(true);
  }

  function toggleExamSessionPreview() {
    setAddProblemOpen(false);
    setLibraryClosing(false);
    setSettingsProblemId(null);
    setSettingsClosing(false);
    if (previewOpen && !previewClosing) {
      closeExamSessionPreview();
      return;
    }
    setPreviewClosing(false);
    setPreviewOpen(true);
  }

  function openProblemSettings(problemId: string) {
    if (settingsProblemId === problemId && !settingsClosing) {
      closeProblemSettings();
      return;
    }
    settingsTrigger.current = document.activeElement as HTMLElement;
    setPreviewOpen(false);
    setPreviewClosing(false);
    setAddProblemOpen(false);
    setLibraryClosing(false);
    setPendingParts([...WORKSPACE_PARTS]);
    setPendingVisibility({ databaseStructureVisible: true, databaseDescriptionVisible: true });
    setPendingDatasetVisibility({});
    setPendingQuestionSelection({});
    setSettingsClosing(false);
    setSettingsProblemId(problemId);
  }

  function closeProblemSettings() {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) setSettingsProblemId(null);
    else setSettingsClosing(true);
    if (settingsTrigger.current?.isConnected) settingsTrigger.current.focus();
  }

  function closeProblemLibrary() {
    libraryScrollAnchor.current = libraryTrigger.current?.getBoundingClientRect().top ?? null;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) setAddProblemOpen(false);
    else setLibraryClosing(true);
    libraryTrigger.current?.focus({preventScroll:true});
  }

  function toggleProblemLibrary() {
    if (addProblemOpen && !libraryClosing) { closeProblemLibrary(); return; }
    libraryScrollAnchor.current = libraryTrigger.current?.getBoundingClientRect().top ?? null;
    setPreviewOpen(false);
    setPreviewClosing(false);
    setSettingsProblemId(null);
    setSettingsClosing(false);
    setLibraryClosing(false);
    setAddProblemOpen(true);
  }

  function addProblem(problemId: string, parts: WorkspacePart[] = [...WORKSPACE_PARTS], visibility = { databaseStructureVisible: true, databaseDescriptionVisible: true }, datasetVisibility: Record<string, boolean> = {}, questionSelection?: QuestionSelection) {
    if (!draft!.problems.some((item) => item.id === problemId)) {
      update("problems", [...draft!.problems, {
        id: problemId,
        points: 10,
        workspaceParts: parts,
        ...visibility,
        datasetVisibility,
        questionSelection,
      }]);
    }
  }

  const sessionSettingsItem = examination
    ? examinationPlan.sessions.flatMap(s => s.problems || []).find((p: any) => p.id === settingsProblemId)
    : null;
  const settingsItem = sessionSettingsItem || draft.problems.find(item => item.id === settingsProblemId);
  const settingsSummary = allProblems.find(item => item.id === settingsProblemId);

  const availableProblems = allProblems.filter(
    (problem) => !draft!.problems.some((item) => item.id === problem.id),
  );

  const currentExamTargetSession = examination && examSessionTargetIndex !== null
    ? examinationPlan.sessions.find(s => s.index === examSessionTargetIndex)
    : null;
  const currentExamSessionProblemIds = new Set((currentExamTargetSession?.problems || []).map((p: any) => p.id));
  const examSessionAvailableProblems = allProblems.filter(p => !currentExamSessionProblemIds.has(p.id));

  function addProblemToExamSession(problemId: string) {
    if (!examSessionTargetIndex) return;
    setExaminationPlan(current => {
      const updatedSessions = current.sessions.map(s => {
        if (s.index === examSessionTargetIndex) {
          // Mỗi ca thi chỉ được thêm 1 đề (các biến thể/mã đề nằm trong cấu hình câu hỏi của đề đó).
          if ((s.problems || []).length >= 1) return s;
          return {
            ...s,
            problems: [
              ...(s.problems || []),
              {
                id: problemId,
                points: 10,
                workspaceParts: [...WORKSPACE_PARTS],
                databaseStructureVisible: true,
                databaseDescriptionVisible: true,
                datasetVisibility: {},
              },
            ],
          };
        }
        return s;
      });
      return { ...current, sessions: updatedSessions };
    });
    markUnsaved();
    setSaveState("Unsaved changes");
    setAlertText("");
  }

  function openExamSessionProblemLibrary(sessionIndex: number) {
    setExamSessionTargetIndex(sessionIndex);
    setPreviewOpen(false);
    setPreviewClosing(false);
    setSettingsProblemId(null);
    setSettingsClosing(false);
    setLibraryClosing(false);
    setAddProblemOpen(true);
  }

  function openExamSessionProblemSettings(problemId: string, sessionIndex: number) {
    setExamSessionTargetIndex(sessionIndex);
    openProblemSettings(problemId);
  }

  const csvAction=(mode:"classes"|"students")=><GlidingTooltipGroup className="teacher-search-csv" label={tr("CSV")} role="group" multiline>
    <button type="button" className="teacher-search-csv-button" disabled={csvBusy} data-tooltip={tr(mode==="classes"?"CSV: one class ID per line. No header required.":"CSV: one student ID or email per line. No header required. Students are looked up automatically.")} onClick={()=>setCsvMode(mode)}><TranslatedText>{csvBusy?"…":tr("Use CSV")}</TranslatedText></button>
  </GlidingTooltipGroup>;
  const bannerActions = examSession ? (
        <div className="teacher-exam-session-banner-editor" onClick={event => event.stopPropagation()} onKeyDown={event => event.stopPropagation()}>
          <div className="teacher-exam-session-banner-editor-actions">
            <button className="button" type="button" onClick={() => bannerFileInput.current?.click()}><ImagePlus size={14} aria-hidden="true" /><TranslatedText>{tr("Click to change image")}</TranslatedText></button>
            {draft.bannerSourceUrl && <button className="button" type="button" onClick={adjustBanner}><TranslatedText>{tr("Adjust crop")}</TranslatedText></button>}
            {draft.bannerUrl && <button className="button" type="button" onClick={() => {
              setDraft(current => current ? { ...current, bannerUrl: null, bannerSourceUrl: null, bannerCrop: null } : null);
              markUnsaved();
              setSaveState("Unsaved changes");
              setAlertText("");
            }}><TranslatedText>{tr("Remove image")}</TranslatedText></button>}
          </div>
        </div>
  ) : undefined;
  const audienceFeedback = <>
    {overlappingStudents > 0 && <p className="teacher-audience-overlap" role="status"><CircleHelp size={14} aria-hidden="true" /><TranslatedText>{tr("{value0} students already belong to the selected classes. Each student is counted once.", {value0: overlappingStudents})}</TranslatedText></p>}
    {audienceError && <p className="teacher-state-failed" role="alert">{audienceError}</p>}
  </>;

  const audienceSummary=(
                <div className="teacher-assignment-summary">
                  <span><TranslatedText>{tr(examSession ? "OPEN TO" : "ASSIGNED TO")}</TranslatedText></span>
                  <strong>
                    <span><TranslatedText>{tr("Classes: {value0}",{value0:draft.classIds.length})}</TranslatedText></span>
                    <span className="teacher-assignment-summary-separator" aria-hidden="true">·</span>
                    <span><TranslatedText>{eligibleStudents !== null ? tr("{value0} eligible student{value1}", { value0: eligibleStudents, value1: eligibleStudents === 1 ? "" : "s" }) : tr("Student count unavailable")}</TranslatedText></span>
                  </strong>
                </div>
  );
  return (
    <section className={`teacher-page teacher-builder-page teacher-authoring-editor-page${parentExaminationId ? " session-management-builder" : ""}${examSession ? " teacher-exam-session-editor-page" : " teacher-assignment-editor-page"}`}>
      {examSession && <input ref={bannerFileInput} className="teacher-banner-file-input" type="file" accept="image/jpeg,image/png,image/webp"
        onChange={(event) => { chooseBanner(event.target.files?.[0]); event.target.value = ""; }} />}
      <div
        ref={examSessionSplitLayoutRef}
        className={`teacher-problem-workspace-layout${previewOpen || addProblemOpen ? " has-live-preview" : ""}${previewClosing || libraryClosing ? " is-closing-preview" : ""}`}
        style={{ "--teacher-preview-width": `${addProblemOpen ? 420 : previewWidth}px` } as CSSProperties}
      >
      <div className={`teacher-builder-layout${examSession ? " teacher-exam-session-builder-layout" : " teacher-assignment-builder-layout"}`}>
        <aside className={`teacher-exam-session-navigation${examSession ? "" : " teacher-assignment-navigation"}`}>
          <div className="teacher-exam-session-navigation-heading">
            <h2><TranslatedText>{tr(parentExaminationId?"Edit examination session":examination ? isNew?"New examination":"Edit examination" : isNew ? (examSession ? "New exam session" : "New assignment") : (examSession ? "Edit exam session" : "Edit assignment"))}</TranslatedText></h2>
            <UnsavedChangesBadge visible={hasUnsavedChanges} />
            <div className="teacher-exam-session-save-exit-actions">
              <button className="button" type="button" onClick={() => requestNavigation(builderListPath)}><TranslatedText>{tr("Exit")}</TranslatedText></button>
              <button className="button primary" type="button" onClick={()=>saveDraft()} disabled={saveBusy || !draft.title.trim() || Boolean(parentExaminationId&&!hasUnsavedChanges)}>
                <TranslatedText>{saveBusy ? tr("Saving…") : tr("Save")}</TranslatedText>
              </button>
            </div>
          </div>
          <nav ref={examSessionNavigationRef} className="teacher-exam-session-page-nav" aria-label={tr(examSession ? "Exam session sections" : "Assignment sections")}>
            <span
              className="teacher-exam-session-page-nav-indicator"
              aria-hidden="true"
              style={{
                width: `${examSessionNavigationIndicator.width}px`,
                height: `${examSessionNavigationIndicator.height}px`,
                transform: `translate(${examSessionNavigationIndicator.x}px, ${examSessionNavigationIndicator.y}px)`,
              }}
            />
            <h3><TranslatedText>{tr("On this page")}</TranslatedText></h3>
            {(parentExaminationId
              ? [["exam-session-setup","Builder general information"],["exam-session-schedule","Exam schedule & submissions"],["exam-session-problems","Exam content"]]
              : examSession
                ? [["exam-session-setup","Builder general information"],...(!examination?[["exam-session-audience","Builder participants"]]:[]),...(examination?[["examination-settings","Common settings"]]:[]),["exam-session-schedule","Exam schedule & submissions"],...(!examination?[["exam-session-problems","Exam content"]]:[])]
                : [["assignment-setup","Builder general information"],["assignment-audience","Builder participants"],["assignment-schedule","Schedule & submissions"],["assignment-problems","Assignment content"]]
            ).map(([sectionId,label])=><button key={sectionId} ref={activeExamSessionSection===sectionId?activeExamSessionNavigationButtonRef:undefined} type="button" className={activeExamSessionSection===sectionId?"is-active":""} aria-current={activeExamSessionSection===sectionId?"location":undefined} onClick={()=>navigateToExamSessionSection(sectionId)}><TranslatedText>{tr(label)}</TranslatedText></button>)}
          </nav>
          {alertText && <p className="teacher-form-message" role="status"><TranslatedText>{tr(alertText)}</TranslatedText></p>}
          {showPublishIssues && publishIssues.length > 0 && <div className="teacher-publish-issues" role="alert" aria-live="polite">
            <strong><TranslatedText>{tr("Cannot publish yet. Please fix:")}</TranslatedText></strong>
            <ul>{publishIssues.map((issue) => <li key={issue}><TranslatedText>{tr(issue)}</TranslatedText></li>)}</ul>
          </div>}
          <div className="teacher-exam-session-navigation-actions">
            <button className="button" type="button" onClick={toggleExamSessionPreview}><TranslatedText>{tr("Preview")}</TranslatedText> <TranslatedText>{tr(examSession ? "examSession" : "assignment")}</TranslatedText></button>
            {parentExaminationId
              ? <button className="button primary" type="button" onClick={()=>requestNavigation(`${builderRoot}/examinations/${parentExaminationId}/edit`)} disabled={saveBusy}><TranslatedText>{tr("Back to examination")}</TranslatedText></button>
              : examination && draft?.published
                ? <button className="button primary examination-published-status-btn" type="button" onClick={async () => {
                    if (hasUnsavedChangesRef.current) {
                      const saved = await saveToServer(true);
                      if (!saved) return;
                    }
                    setShowPublishedOverviewModal(true);
                  }} disabled={saveBusy}><CheckCircle2 size={15} /> <span>Đã công bố</span></button>
                : <button className="button primary" type="button" onClick={publishDraft} disabled={saveBusy}><TranslatedText>{tr("Publish")}</TranslatedText></button>}
          </div>
        </aside>
        <section className="teacher-builder-main">
          <div className="teacher-builder-setup" id={examSession ? "exam-session-setup" : "assignment-setup"}>
            <TeacherSectionTitle title={tr("Builder general information")}>
              {parentExaminationId && <Select className="teacher-session-switch" aria-label={tr("Switch exam session")} value={id} disabled={saveBusy || !siblingSessions.length} onChange={event=>{if(event.target.value!==id)requestNavigation(`${builderRoot}/sessions/${encodeURIComponent(event.target.value)}/edit`);}}>
                {!siblingSessions.some(row=>row.id===id) && <option value={id}>{draft.title}</option>}
                {siblingSessions.map(row=><option key={row.id} value={row.id}>{row.title}</option>)}
              </Select>}
            </TeacherSectionTitle>
            <TeacherField label={tr("TITLE")}>
              <input
                id="builder-title-input"
                className={highlightError?.targetId === "builder-title-input" ? "has-error" : ""}
                data-tooltip={highlightError?.targetId === "builder-title-input" ? highlightError.message : undefined}
                title={highlightError?.targetId === "builder-title-input" ? highlightError.message : undefined}
                value={draft.title}
                onChange={(event) => {
                  setHighlightError(null);
                  update("title", event.target.value);
                }}
              />
            </TeacherField>
            {examSession && !parentExaminationId && <>
              <TeacherField label={tr("SHORT DESCRIPTION")}>
                <input value={draft.shortDescription} maxLength={180} onChange={(event) => update("shortDescription", event.target.value)} placeholder={tr("One-line exam session summary")} />
              </TeacherField>
              <div id="exam-session-description" className="teacher-field teacher-long-text-field teacher-exam-session-long-text-field">
                <div className="teacher-long-text-heading">
                  <span><TranslatedText>{tr("DESCRIPTION")}</TranslatedText></span>
                </div>
                <ProblemRichTextEditor
                  value={draft.description}
                  label={tr("DESCRIPTION")}
                  onChange={(value) => update("description", value)}
                  onOpenExpanded={() => setExpandedTextField("description")}
                />
                <small className="muted"><TranslatedText>{tr("Markdown text is saved as the exam session description.")}</TranslatedText></small>
              </div>
            </>}
            {!examSession && <div id="assignment-instructions" className="teacher-field teacher-long-text-field teacher-exam-session-long-text-field">
              <div className="teacher-long-text-heading">
                <span><TranslatedText>{tr("INSTRUCTIONS")}</TranslatedText></span>
              </div>
              <ProblemRichTextEditor
                value={draft.instructions}
                label={tr("INSTRUCTIONS")}
                onChange={(value) => update("instructions", value)}
                onOpenExpanded={() => setExpandedTextField("instructions")}
              />
            </div>}
          </div>

          <div>
          {!examination && !parentExaminationId && <div
            className={`teacher-builder-audience${
              highlightError?.targetId === (examSession ? "exam-session-audience" : "assignment-audience") ? " has-error" : ""
            }`}
            id={examSession ? "exam-session-audience" : "assignment-audience"}
          >
            {!parentExaminationId&&<TeacherSectionTitle title={tr("Builder participants")}/>}
            <div className="teacher-builder-class-assignment">
              {(!examSession || examination) && <div className="teacher-class-assignment-heading">
                {!examSession && <span className="teacher-current-term">{formatAcademicTerm(currentAcademicTerm())}</span>}
                {audienceSummary}
                <button type="button" className="teacher-audience-collapse teacher-audience-disclosure-button" aria-controls="builder-class-picker" aria-expanded={audiencePanel==="classes" && !audiencePanelClosing} onClick={()=>toggleAudiencePanel("classes")}><ChevronDown size={14} aria-hidden="true"/><TranslatedText>{tr(audiencePanel==="classes" && !audiencePanelClosing?"Collapse participant list":"Show participant list")}</TranslatedText></button>
              </div>}
              {(!examSession || parentExaminationId || draft.audienceType === "classes") && <ClassPicker
                panelId="builder-class-picker"
                selected={draft.classIds}
                onChange={updateSessionClasses}
                classNotices={Object.fromEntries(Object.entries(classAllocations).map(([classId,allocation])=>[classId,tr(classTransfers[classId]?"Will transfer from {value0} when saved":"Already assigned to {value0}",{value0:allocation.label})]))}
                classes={(() => {
                  const selected = new Set(draft.classIds);
                  const known = new Set(availableClasses.map((c: any) => c.id));
                  return [...availableClasses, ...allTeacherClasses.filter((c: any) => selected.has(c.id) && !known.has(c.id))];
                })()}
                loading={classesLoading}
                error={classesError}
                searchActions={!examination?csvAction("classes"):undefined}
                expanded={Boolean(parentExaminationId)||audiencePanel==="classes" && !audiencePanelClosing}
                onExpandedChange={()=>toggleAudiencePanel("classes")}
                onPanelClosed={finishAudiencePanelClose}
                hideTrigger
                individualMode={individualMode}
                onIndividualModeChange={setIndividualMode}
                individualCount={individualStudents ?? draft.studentEmails.length}
                selectedIndividualsPanel={<div className="teacher-selected-class-list">{draft.studentEmails.map(email=><div key={email} className="teacher-selected-class-row"><span className="teacher-selected-class-view"><b>{email}</b></span><button type="button" className="icon-button" aria-label={tr("Remove {value0}",{value0:email})} onClick={()=>update("studentEmails",draft.studentEmails.filter(value=>value!==email))}><X size={14}/></button></div>)}{!draft.studentEmails.length && <p className="teacher-class-browser-empty"><TranslatedText>{tr("Select individual students from the list on the left.")}</TranslatedText></p>}</div>}
                individualStudentsPanel={!examination?<StudentSearchPicker searchActions={csvAction("students")} classIds={draft.classIds} emails={draft.studentEmails} onChange={emails=>update("studentEmails",emails)} onClose={()=>setIndividualMode(false)} />:undefined}
              />}

              {audienceFeedback}
              {allocationError && <p className="teacher-state-failed" role="alert">{allocationError}</p>}
            </div>
          </div>}

          {examination && !parentExaminationId && <div id="examination-settings" className="examination-common-settings">
            <TeacherSectionTitle title={tr("Common settings for all sessions")} />
            <GlidingTooltipGroup className="teacher-exam-mode-setting" label={tr("Submission settings")} role="group" multiline>
              <div className="teacher-option-row">
                <span className="teacher-setting-label" tabIndex={0} data-tooltip={tr("Students can see their scores once their work has been graded.")}><b><TranslatedText>{tr("Show scores after submission")}</TranslatedText></b><CircleHelp size={13} aria-hidden="true" /></span>
                <button type="button" role="switch" disabled={Boolean(parentExaminationId)} aria-label={tr("Show scores after submission")} aria-checked={draft.studentOptions.showScoresAfterSubmit} className={`teacher-toggle${draft.studentOptions.showScoresAfterSubmit ? " is-on" : ""}`} onClick={() => update("studentOptions", { ...draft.studentOptions, showScoresAfterSubmit: !draft.studentOptions.showScoresAfterSubmit })}><span /></button>
              </div>
              <div className="teacher-option-row teacher-submission-limit">
                <span className="teacher-setting-label" tabIndex={0} data-tooltip={tr("Choose unlimited submissions or set a limit per problem.")}><b><TranslatedText>{tr("Submission limit")}</TranslatedText></b><CircleHelp size={13} aria-hidden="true" /></span>
                <SubmissionLimitControl compact disabled={Boolean(parentExaminationId)} value={draft.studentOptions.maxSubmissions} onChange={maxSubmissions => update("studentOptions", { ...draft.studentOptions, maxSubmissions })} />
              </div>
              <div className="teacher-option-row">
                <span className="teacher-setting-label" tabIndex={0} data-tooltip={tr("Start in full screen. The configured violation limit automatically submits all exam work.")}><b><TranslatedText>{tr("Exam mode")}</TranslatedText></b><CircleHelp size={13} aria-hidden="true" /></span>
                <button type="button" role="switch" aria-label={tr("Exam mode")} disabled={examination || Boolean(parentExaminationId)} aria-checked={Boolean(draft.studentOptions.examMode)} className={`teacher-toggle${draft.studentOptions.examMode ? " is-on" : ""}`} onClick={() => update("studentOptions", { ...draft.studentOptions, examMode: !draft.studentOptions.examMode, maxSubmissions: !draft.studentOptions.examMode ? DEFAULT_SUBMISSION_LIMIT : draft.studentOptions.maxSubmissions })}><span /></button>
              </div>
              {draft.studentOptions.examMode && <div className="teacher-option-row teacher-violation-limit">
                <label htmlFor="exam-violation-limit"><TranslatedText>{tr("Violations before automatic submission")}</TranslatedText></label>
                <input id="exam-violation-limit" type="number" min={1} max={100} step={1} value={draft.studentOptions.examViolationLimit ?? 2} onChange={event => update("studentOptions", {...draft.studentOptions, examViolationLimit: Math.min(100, Math.max(1, Math.trunc(event.target.valueAsNumber || 2)))})} />
              </div>}
            </GlidingTooltipGroup>
          </div>}

          <div
            className={`teacher-schedule-section teacher-builder-schedule${
              highlightError?.targetId === (examSession ? "exam-session-schedule" : "assignment-schedule") ? " has-error" : ""
            }`}
            id={examSession ? "exam-session-schedule" : "assignment-schedule"}
          >
            <TeacherSectionTitle title={tr(examSession ? "Exam schedule & submissions" : "Schedule & submissions")} />
            {examination ? <ExaminationSessionPlanner
              onRefresh={isNew ? undefined : refreshSessionTimes}
              refreshing={refreshingSessions}
              initialSession={requestedSession}
              value={examinationPlan}
              errorTarget={highlightError}
              onClearError={() => setHighlightError(null)}
              examinationTitle={draft.title}
              availableClasses={availableClasses}
              proctorOptions={proctorOptions}
              proctorsLoading={proctorsLoading}
              proctorsError={proctorsError}
              classesLoading={classesLoading}
              classesError={classesError}
              allProblems={allProblems}
              problemsLoading={problemsLoading}
              problemsError={problemsError}
              onOpenProblemLibrary={openExamSessionProblemLibrary}
              onOpenProblemSettings={openExamSessionProblemSettings}
              settingsProblemId={settingsProblemId}
              problemDetails={problemDetails}
              catalogErrors={catalogErrors}
              onRetryCatalog={() => setCatalogRetry(v => v + 1)}
              onChange={plan => {
                setHighlightError(null);
                const sessionClassIds = [...new Set(plan.sessions.flatMap(slot => slot.classIds || []))];
                setExaminationPlan(plan);
                setDraft(current => current ? {
                  ...current,
                  ...examinationSchedule(plan),
                  classIds: sessionClassIds,
                } : current);
                markUnsaved();
                setSaveState("Unsaved changes");
                setAlertText("");
              }}
            /> : <>
            <fieldset className="teacher-exam-session-schedule-fields">
              <TeacherField label={tr(examSession ? "STARTS" : "OPENS")}>
                <DateTimeInput label={tr(examSession ? "Starts" : "Opens")} value={draft.opens} onChange={(value) => update("opens", value)} />
              </TeacherField>
              <TeacherField label={tr(examSession ? "ENDS" : "DUE DATE")}>
                <DateTimeInput label={tr(examSession ? "Ends" : "Due date")} value={draft.closes} onChange={(value) => update("closes", value)} />
              </TeacherField>
            </fieldset>            </>}

            {examSession && !examination && durationMinutes > 0 && <p className="teacher-timezone"><TranslatedText>{tr("Duration ·")}</TranslatedText> {durationMinutes} <TranslatedText>{tr("min")}</TranslatedText></p>}

          {!examination && !parentExaminationId && <div>
          <GlidingTooltipGroup className="teacher-exam-mode-setting" label={tr("Submission settings")} role="group" multiline>
            <div className="teacher-option-row">
              <span className="teacher-setting-label" tabIndex={0} data-tooltip={tr("Students can see their scores once their work has been graded.")}><b><TranslatedText>{tr("Show scores after submission")}</TranslatedText></b><CircleHelp size={13} aria-hidden="true" /></span>
              <button type="button" role="switch" disabled={Boolean(parentExaminationId)} aria-label={tr("Show scores after submission")} aria-checked={draft.studentOptions.showScoresAfterSubmit} className={`teacher-toggle${draft.studentOptions.showScoresAfterSubmit ? " is-on" : ""}`} onClick={() => update("studentOptions", { ...draft.studentOptions, showScoresAfterSubmit: !draft.studentOptions.showScoresAfterSubmit })}><span /></button>
            </div>
            <div className="teacher-option-row teacher-submission-limit">
              <span className="teacher-setting-label" tabIndex={0} data-tooltip={tr("Choose unlimited submissions or set a limit per problem.")}><b><TranslatedText>{tr("Submission limit")}</TranslatedText></b><CircleHelp size={13} aria-hidden="true" /></span>
              <SubmissionLimitControl compact disabled={Boolean(parentExaminationId)} value={draft.studentOptions.maxSubmissions} onChange={maxSubmissions => update("studentOptions", { ...draft.studentOptions, maxSubmissions })} />
            </div>
            <div className="teacher-option-row">
              <span className="teacher-setting-label" tabIndex={0} data-tooltip={tr("Start in full screen. The configured violation limit automatically submits all exam work.")}><b><TranslatedText>{tr("Exam mode")}</TranslatedText></b><CircleHelp size={13} aria-hidden="true" /></span>
              <button type="button" role="switch" aria-label={tr("Exam mode")} disabled={examination || Boolean(parentExaminationId)} aria-checked={Boolean(draft.studentOptions.examMode)} className={`teacher-toggle${draft.studentOptions.examMode ? " is-on" : ""}`} onClick={() => update("studentOptions", { ...draft.studentOptions, examMode: !draft.studentOptions.examMode, maxSubmissions: !draft.studentOptions.examMode ? DEFAULT_SUBMISSION_LIMIT : draft.studentOptions.maxSubmissions })}><span /></button>
            </div>
            {draft.studentOptions.examMode && <div className="teacher-option-row teacher-violation-limit">
              <label htmlFor="exam-violation-limit"><TranslatedText>{tr("Violations before automatic submission")}</TranslatedText></label>
              <input id="exam-violation-limit" type="number" min={1} max={100} step={1} value={draft.studentOptions.examViolationLimit ?? 2} onChange={event => update("studentOptions", {...draft.studentOptions, examViolationLimit: Math.min(100, Math.max(1, Math.trunc(event.target.valueAsNumber || 2)))})} />
            </div>}
          </GlidingTooltipGroup>
          </div>}
          </div>

          {!examination && <div
            className={`teacher-builder-problems${
              highlightError?.targetId === (examSession ? "exam-session-problems" : "assignment-problems") ? " has-error" : ""
            }`}
            id={examSession ? "exam-session-problems" : "assignment-problems"}
          >
            <TeacherSectionTitle title={tr(examSession ? "Exam content" : "Assignment content")} />
            <GlidingTooltipGroup className="builder-scoring-summary" label={tr("Equal scoring")} role="group" multiline>
              <strong><TranslatedText>{tr("Total: 10 points")}</TranslatedText></strong>
              <span><TranslatedText>{tr("{value0} selected questions", { value0: totalQuestions })}</TranslatedText></span>
              <button type="button" className="builder-scoring-help" aria-label={tr("Equal scoring")} data-tooltip={tr("Each selected question has the same weight, across all problems and SQL types.")}><CircleHelp size={14} aria-hidden="true" /></button>
            <button
              className="button primary teacher-small-button builder-library-trigger"
              type="button"
              disabled={problemsLoading || !!problemsError}
              ref={libraryTrigger}
              aria-expanded={addProblemOpen && !libraryClosing}
              aria-controls="builder-problem-library"
              onClick={toggleProblemLibrary}
            >
              <Plus size={15} aria-hidden="true" />
              <TranslatedText>{problemsLoading ? tr("Loading library…") : tr("Add from library")}</TranslatedText>
            </button>
            </GlidingTooltipGroup>
            {problemsError && <p className="teacher-state-failed" role="alert"><TranslatedText>{tr(problemsError)}</TranslatedText></p>}
            <ol className="builder-problem-list" aria-label={tr(examSession ? "Exam content" : "Assignment content")}>
              {draft.problems.map((item, index) => {
                const problem = allProblems.find(row => row.id === item.id);
                const lockedSnapshot = lockedProblemsSnapshot[item.id];
                const isLockedProblem = Boolean(lockedSnapshot);
                if (!problem) {
                  return <li className="builder-problem-card" key={item.id}>
                    <header className="builder-problem-card-heading">
                      <span className="builder-problem-number">{String(index + 1).padStart(2, "0")}</span>
                      <h3 className="builder-problem-title">{tr("Problem unavailable (deleted from library)")} ({item.id})</h3>
                      <div className="builder-points-actions">
                        <button type="button" className="builder-problem-remove" disabled={isLockedProblem} aria-label={tr("Remove {value0}", { value0: item.id })} title={isLockedProblem ? tr("Published assignment problems cannot be removed") : tr("Remove {value0}", { value0: item.id })} onClick={() => !isLockedProblem && update("problems", draft.problems.filter(row => row.id !== item.id))}><X size={14} aria-hidden="true" /></button>
                      </div>
                    </header>
                  </li>;
                }
                return <li className="builder-problem-card" key={item.id}>
                  <header className="builder-problem-card-heading">
                    <span className="builder-problem-number">{String(index + 1).padStart(2, "0")}</span>
                    <h3 className="builder-problem-title">{problem.title}</h3>
                    <button
                      type="button"
                      className={`button teacher-small-button builder-problem-settings-button${settingsProblemId === item.id ? " is-active" : ""}`}
                      aria-expanded={settingsProblemId === item.id}
                      onClick={() => {
                        setSettingsProblemId(curr => curr === item.id ? null : item.id);
                        setSelectedQuestionsId(null);
                        setAddProblemOpen(false);
                      }}
                    >
                      <Settings2 size={13} aria-hidden="true" />
                      <span><TranslatedText>{tr("Problem settings")}</TranslatedText></span>
                      <ChevronDown size={13} className={settingsProblemId === item.id ? "is-expanded" : ""} aria-hidden="true" />
                    </button>
                    <div className="builder-points-actions">
                      <button type="button" aria-label={tr("Move {value0} up", { value0: problem.title })} title={tr("Move {value0} up", { value0: problem.title })} disabled={isLockedProblem || index === 0 || Boolean(lockedProblemsSnapshot[draft.problems[index - 1]?.id])} onClick={() => { const next = [...draft.problems]; [next[index - 1], next[index]] = [next[index], next[index - 1]]; update("problems", next); }}><ArrowUp size={14} aria-hidden="true" /></button>
                      <button type="button" aria-label={tr("Move {value0} down", { value0: problem.title })} title={tr("Move {value0} down", { value0: problem.title })} disabled={isLockedProblem || index === draft.problems.length - 1} onClick={() => { const next = [...draft.problems]; [next[index], next[index + 1]] = [next[index + 1], next[index]]; update("problems", next); }}><ArrowDown size={14} aria-hidden="true" /></button>
                      <button type="button" className="builder-problem-remove" disabled={isLockedProblem} aria-label={tr("Remove {value0}", { value0: problem.title })} title={isLockedProblem ? tr("Published assignment problems cannot be removed") : tr("Remove {value0}", { value0: problem.title })} onClick={() => !isLockedProblem && update("problems", draft.problems.filter(row => row.id !== item.id))}><X size={14} aria-hidden="true" /></button>
                    </div>
                  </header>
                  <div className="builder-problem-tools">
                    <BuilderInlineSettings
                      selectionMode={examSession || examination ? "auto" : "manual"}
                      hideTrigger
                      problemId={item.id}
                      title={problem.title}
                      detail={problemDetails[item.id] || null}
                      parts={item.workspaceParts || []}
                      questionSelection={item.questionSelection}
                      locked={isLockedProblem}
                      lockedQuestionSelection={lockedSnapshot?.questionSelection}
                      lockedParts={lockedSnapshot?.workspaceParts}
                      onQuestionSelectionChange={(selection) => updateProblemSettings(item.id, { questionSelection: selection })}
                      onPartsChange={(parts) => updateProblemSettings(item.id, { workspaceParts: parts })}
                      onBatchChange={(patch) => updateProblemSettings(item.id, patch)}
                      visibility={{
                        databaseStructureVisible: item.databaseStructureVisible !== false,
                        databaseDescriptionVisible: item.databaseDescriptionVisible !== false,
                      }}
                      onVisibilityChange={(vis) => updateProblemSettings(item.id, vis)}
                      datasetVisibility={item.datasetVisibility || {}}
                      onDatasetVisibilityChange={(datasetVisibility) => updateProblemSettings(item.id, { datasetVisibility })}
                      open={settingsProblemId === item.id}
                      onToggle={() => {
                        setSettingsProblemId(curr => curr === item.id ? null : item.id);
                        setSelectedQuestionsId(null);
                        setAddProblemOpen(false);
                      }}
                      error={catalogErrors[item.id]}
                      onRetry={() => setCatalogRetry(value => value + 1)}
                    />
                  </div>
                </li>;
              })}
            </ol>

          </div>}

          </div>
        </section>

      </div>

      {addProblemOpen && <BuilderProblemLibrary
        problems={examination && examSessionTargetIndex !== null ? examSessionAvailableProblems : availableProblems}
        closing={libraryClosing}
        onAdd={id => {
          if (examination && examSessionTargetIndex !== null) {
            addProblemToExamSession(id);
            closeProblemLibrary();
          } else {
            // Assignments pick questions by hand: a newly added problem starts with nothing ticked.
            if (examSession || examination) addProblem(id);
            else addProblem(id, [], undefined, undefined, { DDL: [], DML: [], SQL: [], TRIGGER: [] });
          }
        }}
        onClose={closeProblemLibrary}
        onClosed={() => {
          setAddProblemOpen(false);
          setLibraryClosing(false);
          setExamSessionTargetIndex(null);
        }}
      />}
      {previewOpen && <aside
        className={`teacher-student-preview-panel${examSession ? " teacher-exam-session-preview-panel" : " teacher-assignment-preview-panel"}${previewClosing ? " is-closing" : ""}`}
        aria-label={tr("Student preview")}
        onAnimationEnd={(event) => {
          if (event.target !== event.currentTarget || event.animationName !== "teacher-preview-panel-out") return;
          setPreviewOpen(false);
          setPreviewClosing(false);
        }}
      >
        <button
          className="teacher-student-preview-resize"
          type="button"
          role="separator"
          aria-orientation="vertical"
          aria-valuemin={320}
          aria-valuemax={getMaxPreviewWidth()}
          aria-valuenow={previewWidth}
          aria-label={tr("Resize preview panel")}
          title={tr("Resize preview panel")}
          onPointerDown={(event) => {
            if (event.button !== 0) return;
            event.currentTarget.setPointerCapture(event.pointerId);
            event.preventDefault();
          }}
          onPointerMove={(event) => {
            if (!event.currentTarget.hasPointerCapture(event.pointerId)) return;
            const bounds = examSessionSplitLayoutRef.current?.getBoundingClientRect();
            if (bounds) resizePreviewTo(bounds.right - event.clientX);
          }}
          onPointerUp={(event) => {
            if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
          }}
          onKeyDown={(event) => {
            if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
            event.preventDefault();
            resizePreviewTo(previewWidth + (event.key === "ArrowLeft" ? 24 : -24));
          }}
          onDoubleClick={() => resizePreviewTo(420)}
        ><span aria-hidden="true" /></button>
        <header className="teacher-student-preview-heading">
          <h2><TranslatedText>{tr("Student preview")}</TranslatedText></h2>
          <button type="button" className="icon-button" aria-label={tr("Close preview")} title={tr("Close preview")} onClick={closeExamSessionPreview}>
            <X size={18} aria-hidden="true" />
          </button>
        </header>
        <div className="teacher-student-preview">
          <div className={`teacher-student-preview-scroll${examSession ? " teacher-exam-session-preview-scroll" : ""}`}>
            <ActivityPreview draft={{...draft, problems: weightedProblems}} examSession={examSession} classes={availableClasses} problems={allProblems} bannerActions={bannerActions} />
          </div>
        </div>
      </aside>}
      </div>

      {expandedTextField && <Dialog
        title={tr(expandedTextField === "description" ? "DESCRIPTION" : "INSTRUCTIONS")}
        className="teacher-long-text-dialog"
        onClose={() => setExpandedTextField(null)}
      >
        <div className="teacher-long-text-dialog-body teacher-builder-long-text-dialog-body">
          <strong className="teacher-long-text-toolbar-title"><TranslatedText>{tr(expandedTextField === "description" ? "DESCRIPTION" : "INSTRUCTIONS")}</TranslatedText></strong>
          <div className="teacher-builder-long-text-expanded">
            <ProblemRichTextEditor
              value={draft[expandedTextField]}
              label={tr(expandedTextField === "description" ? "DESCRIPTION" : "INSTRUCTIONS")}
              onChange={(value) => update(expandedTextField, value)}
              onOpenExpanded={() => undefined}
            />
          </div>
          <div className="teacher-dialog-actions">
            <button className="button primary teacher-long-text-done" type="button" onClick={() => setExpandedTextField(null)}><TranslatedText>{tr("Done")}</TranslatedText></button>
          </div>
        </div>
      </Dialog>}
      {pendingClassTransfer && <Dialog title={tr("Move class to this session?")} onClose={()=>setPendingClassTransfer(null)}>
        <div className="teacher-preview-dialog">
          <p><TranslatedText>{tr("Class {value0} is assigned to {value1}. Move it to {value2}? When you save, the class will be removed from its current session and assigned only to this session.",{value0:availableClasses.find(row=>row.id===pendingClassTransfer.classId)?.course || pendingClassTransfer.classId,value1:pendingClassTransfer.sourceLabel,value2:draft.title})}</TranslatedText></p>
          <div className="teacher-dialog-actions">
            <button className="button" type="button" onClick={()=>setPendingClassTransfer(null)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
            <button className="button primary" type="button" onClick={()=>{setClassTransfers(current=>({...current,[pendingClassTransfer.classId]:pendingClassTransfer.sourceId}));update("classIds",pendingClassTransfer.next);setPendingClassTransfer(null);}}><TranslatedText>{tr("Confirm transfer")}</TranslatedText></button>
          </div>
        </div>
      </Dialog>}
      {pendingNavigation && <Dialog title={tr("Unsaved changes")} onClose={() => setPendingNavigation(null)}>
        <div className="teacher-preview-dialog">
          <p><TranslatedText>{tr("You have unsaved changes. Would you like to save before leaving?")}</TranslatedText></p>
          {!saveBusy && saveState && saveState !== "Unsaved changes" && saveState !== "All changes saved" && <p className="teacher-state-failed" role="alert"><TranslatedText>{formatErrorMessage(saveState)}</TranslatedText></p>}
          <div className="teacher-dialog-actions">
            <button className="button" type="button" disabled={saveBusy} onClick={() => setPendingNavigation(null)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
            <button className="button primary" type="button" disabled={saveBusy || !draft.title.trim()} onClick={() => void saveToServer(draft.published, pendingNavigation)}><TranslatedText>{tr("Save and exit")}</TranslatedText></button>
            <button className="button teacher-danger-button" type="button" disabled={saveBusy} onClick={() => { const destination = pendingNavigation; try{sessionStorage.removeItem(draftKey);}catch{/* Browser storage may be unavailable. */} setPendingNavigation(null); navigate(destination); }}><TranslatedText>{tr("Leave without saving")}</TranslatedText></button>
          </div>
        </div>
      </Dialog>}
      {csvMode && <AudienceImportDialog mode={csvMode} classIds={availableClasses.map(item=>item.id)} onImport={importAudienceCsv} onClose={()=>setCsvMode(null)} />}
      {examSession && cropSource && <ExamSessionBannerCrop sourceUrl={cropSource} initialCrop={cropInitial} onClose={closeCrop} onApply={applyBanner} />}
      {showPublishModal && (
        <ExaminationPublishModal
          examinationId={parentExaminationId || (!isNew && examination ? id : undefined)}
          plan={examinationPlan}
          draftProblems={draft?.problems}
          allProblems={allProblems}
          problemDetails={problemDetails}
          availableClasses={availableClasses}
          examinationTitle={draft?.title}
          isPublishing={saveBusy}
          onClose={() => setShowPublishModal(false)}
          onConfirmPublish={() => {
            setShowPublishModal(false);
            saveToServer(true);
          }}
        />
      )}
      {showPublishedOverviewModal && (
        <ExaminationPublishModal
          examinationId={parentExaminationId || (!isNew && examination ? id : undefined)}
          plan={examinationPlan}
          draftProblems={draft?.problems}
          allProblems={allProblems}
          problemDetails={problemDetails}
          availableClasses={availableClasses}
          examinationTitle={draft?.title}
          isAlreadyPublished={true}
          isPublishing={saveBusy}
          onClose={() => setShowPublishedOverviewModal(false)}
          onConfirmPublish={() => {
            setShowPublishedOverviewModal(false);
            saveToServer(true);
          }}
        />
      )}
    </section>
  );
}
