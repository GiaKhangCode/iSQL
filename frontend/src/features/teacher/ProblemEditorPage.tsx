import {Select} from "@/shared/ui/Select";
import { importLibraryBundle, librarySectionCount, type LibraryImportOptions } from "@/shared/lib/sql/libraryBundleImport";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { toast } from "@/shared/ui/StackingToasts";
import { UnsavedChangesBadge } from "@/shared/ui/UnsavedChangesBadge";
import { useSectionNavigation, useNavigationIndicator } from "@/shared/hooks/useSectionNavigation";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { DatasetDraft } from "@/features/teacher/content-editor/DatasetsPanel";
import { TeacherSqlSolutions, initialSqlSolutions } from "@/features/teacher/problem-editor/TeacherSqlSolutions";
import { TeacherStageExercises, initialStageExercise } from "@/features/teacher/problem-editor/TeacherStageExercises";
import { useState, useEffect, useMemo, useRef, type CSSProperties, type MouseEvent as ReactMouseEvent } from "react";
import { useParams, useNavigate, useLocation } from "react-router-dom";
import { Dialog, ErrorState, Loading } from "@/shared/ui/ui";
import { mergeLegacyDatabaseDescription } from "@/shared/lib/markdown-richtext/legacyDatabaseDescription";
import { ProblemQuestionContent, type ProblemQuestion } from "@/shared/components/ProblemQuestionContent";
import { WorkspaceDatabasePanel } from "@/shared/components/WorkspaceDatabasePanel";
import { usePreparedDatabase } from "@/shared/components/usePreparedDatabase";
import { questionReference } from "@/shared/lib/workspace/workspaceReference";
import { teacherService, type TeacherDatabaseLibraryEntry } from "@/shared/api/teacherService";
import type { WorkspacePart } from "@/data/models";
import { type TeacherProblem } from "@/data/teacherTypes";
import { TeacherField, TeacherSectionTitle } from "@/features/teacher/components/TeacherPageParts";
import { parseDatabaseSchema } from "@/shared/lib/sql/parseDatabaseSchema";
import { parseSeedData } from "@/shared/lib/sql/parseSeedData";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { WORKSPACE_PARTS, WORKSPACE_PART_LABELS, WORKSPACE_PART_TOOLTIPS } from "@/shared/lib/workspace/workspaceParts";
import {  Database, Download, Eye, Plus, X  } from "lucide-react";
import "@/styles/problem-workspace.css";
import { TeacherSchemaDiagram } from "@/features/teacher/problem-editor/TeacherSchemaDiagram";
import { ProblemRichTextEditor } from "@/features/teacher/problem-editor/ProblemRichTextEditor";
import { DatasetDataPreview, databaseLibraryDatasets, parseProblemTopics, problemPayloadWasSaved } from "@/features/teacher/problem-editor/ProblemEditorHelpers";
import { notify } from "@/shared/ui/notify";
import { questionLabel } from "@/shared/lib/workspace/questionLabel";

function getNaturalDatabaseId(allEntries: TeacherDatabaseLibraryEntry[], targetId: string): string {
  const sorted = [...allEntries].sort((a, b) => {
    const tA = parseServerDateTime(a.createdAt || "").getTime();
    const tB = parseServerDateTime(b.createdAt || "").getTime();
    return tA - tB || a.id.localeCompare(b.id);
  });
  const idx = sorted.findIndex(e => e.id === targetId);
  return idx !== -1 ? String(idx + 1) : "";
}

/** The problem only takes its tables and initial data from the library; questions stay with the problem. */
const DATABASE_ONLY_IMPORT: LibraryImportOptions = { sections: ["schema", "datasets"], mode: "replace" };
const problemDraftKey = (id: string) => `teacher-problem-draft:${id}`;

export function ProblemEditorPage() {
  const { t: tr } = useLanguage("teacher");
  const { problemId } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const selectedId = problemId || "new";
  const [problem, setProblem] = useState<TeacherProblem | null>(null);
  const [loadError, setLoadError] = useState("");
  const [databaseTab, setDatabaseTab] = useState<"structure" | "schema" | "datasets" | "solution" | "trigger">("structure");
  const [databaseSetupTab, setDatabaseSetupTab] = useState<"tables" | "data">("tables");
  const [libraryImportOpen, setLibraryImportOpen] = useState(false);
  const [libraryEntries, setLibraryEntries] = useState<TeacherDatabaseLibraryEntry[]>([]);
  const [libraryPreviewEntryId, setLibraryPreviewEntryId] = useState<string | null>(null);
  const [libraryPreviewDatasetIndex, setLibraryPreviewDatasetIndex] = useState(0);
  const [libraryLoading, setLibraryLoading] = useState(false);
  const [libraryError, setLibraryError] = useState("");

  const [databaseLibraryId, setDatabaseLibraryId] = useState<string>(() => {
    try {
      return localStorage.getItem(`teacher-problem-db-id:${selectedId}`) || "";
    } catch {
      return "";
    }
  });
  const [databaseLibraryTitle, setDatabaseLibraryTitle] = useState<string>(() => {
    try {
      return localStorage.getItem(`teacher-problem-db-title:${selectedId}`) || "";
    } catch {
      return "";
    }
  });
  const [databaseLibraryOrder, setDatabaseLibraryOrder] = useState<string>(() => {
    try {
      return localStorage.getItem(`teacher-problem-db-order:${selectedId}`) || "";
    } catch {
      return "";
    }
  });

  const updateDatabaseLibraryMeta = (id: string, title: string, order?: number | string) => {
    setDatabaseLibraryId(id);
    setDatabaseLibraryTitle(title);
    const orderStr = order !== undefined ? String(order) : "";
    if (order !== undefined) {
      setDatabaseLibraryOrder(orderStr);
    }
    try {
      localStorage.setItem(`teacher-problem-db-id:${selectedId}`, id);
      localStorage.setItem(`teacher-problem-db-title:${selectedId}`, title);
      if (order !== undefined) {
        localStorage.setItem(`teacher-problem-db-order:${selectedId}`, orderStr);
      }
    } catch { /* storage blocked */ }
  };

  // Sync database library metadata across tabs and on focus
  useEffect(() => {
    if (!databaseLibraryId) return;

    async function syncDatabaseLibraryMeta() {
      if (!databaseLibraryId) return;
      try {
        const entries = await teacherService.getDatabaseLibrary();
        const entry = entries.find(e => e.id === databaseLibraryId);
        if (entry) {
          const naturalOrder = getNaturalDatabaseId(entries, entry.id);
          setDatabaseLibraryTitle(entry.title);
          if (naturalOrder) setDatabaseLibraryOrder(naturalOrder);
          try {
            localStorage.setItem(`teacher-problem-db-title:${selectedId}`, entry.title);
            if (naturalOrder) localStorage.setItem(`teacher-problem-db-order:${selectedId}`, naturalOrder);
          } catch {}
        }
      } catch {}
    }

    function handleStorage(e: StorageEvent) {
      if (e.key === `teacher-db-library-entry:${databaseLibraryId}` && e.newValue) {
        try {
          const data = JSON.parse(e.newValue);
          if (data?.title) {
            setDatabaseLibraryTitle(data.title);
            try {
              localStorage.setItem(`teacher-problem-db-title:${selectedId}`, data.title);
            } catch {}
          }
        } catch {}
      }
    }

    function handleFocusOrVisible() {
      if (document.visibilityState === "visible") {
        void syncDatabaseLibraryMeta();
      }
    }

    window.addEventListener("storage", handleStorage);
    window.addEventListener("focus", handleFocusOrVisible);
    document.addEventListener("visibilitychange", handleFocusOrVisible);

    return () => {
      window.removeEventListener("storage", handleStorage);
      window.removeEventListener("focus", handleFocusOrVisible);
      document.removeEventListener("visibilitychange", handleFocusOrVisible);
    };
  }, [databaseLibraryId, selectedId]);

  useEffect(() => {
    let active = true;

    /** Restores the draft stashed before leaving for the database library and selects the database created there. */
    async function finishLoad(base: TeacherProblem) {
      const params = new URLSearchParams(window.location.search);
      const databaseId = params.get("database");
      const restoreDraft = params.get("restore") === "1" || Boolean(databaseId);
      let draft: { problem: TeacherProblem; datasetNames: string[] } | null = null;
      try {
        const raw = sessionStorage.getItem(problemDraftKey(selectedId));
        if (raw && restoreDraft) draft = JSON.parse(raw);
      } catch { draft = null; }
      let next = draft?.problem ?? base;
      let names = draft?.datasetNames;
      let changed = Boolean(draft);
      if (databaseId) {
        try {
          const entries = await teacherService.getDatabaseLibrary();
          if (!active) return;
          const entry = entries.find(item => item.id === databaseId);
          if (entry) {
            const selectedSections = DATABASE_ONLY_IMPORT.sections.filter(section => librarySectionCount(entry, section) > 0);
            if (selectedSections.length > 0) {
              const imported = importLibraryBundle(next, entry, { ...DATABASE_ONLY_IMPORT, sections: selectedSections });
              next = imported.problem;
              names = imported.datasetNames ?? names;
              changed = true;
            }
            updateDatabaseLibraryMeta(entry.id, entry.title, getNaturalDatabaseId(entries, entry.id));
          }
        } catch { /* The problem is still usable; the database can be chosen manually. */ }
      }
      if (!active) return;
      try { sessionStorage.removeItem(problemDraftKey(selectedId)); } catch {}
      setProblem(next);
      if (names) {
        setDatasetNames(names);
        try { localStorage.setItem(`teacher-dataset-names:${selectedId}`, JSON.stringify(names)); } catch { /* storage blocked */ }
      }
      if (changed) {
        markUnsaved();
        setSaveState("Unsaved changes");
      } else if (selectedId !== "new") {
        setSaveState("Saved");
      }
      if (params.has("database") || params.has("restore")) navigate(location.pathname, { replace: true });
    }

    async function fetchProblem() {
      setDatabaseTab("structure");
      setDatabaseSetupTab("tables");
      setSaveState("");
      markSaved();
      let savedDbId = "";
      try {
        savedDbId = localStorage.getItem(`teacher-problem-db-id:${selectedId}`) || "";
        const savedTitle = localStorage.getItem(`teacher-problem-db-title:${selectedId}`) || "";
        const savedOrder = localStorage.getItem(`teacher-problem-db-order:${selectedId}`) || "";
        setDatabaseLibraryId(savedDbId);
        setDatabaseLibraryTitle(savedTitle);
        setDatabaseLibraryOrder(savedOrder);
        if (savedDbId) {
          teacherService.getDatabaseLibrary().then((entries) => {
            if (!active) return;
            const naturalOrder = getNaturalDatabaseId(entries, savedDbId);
            if (naturalOrder) {
              setDatabaseLibraryOrder(naturalOrder);
              try { localStorage.setItem(`teacher-problem-db-order:${selectedId}`, naturalOrder); } catch {}
            }
          }).catch(() => {});
        }
      } catch {
        setDatabaseLibraryId("");
        setDatabaseLibraryTitle("");
        setDatabaseLibraryOrder("");
      }
      try {
        if (selectedId === "new") {
          const allProblems = await teacherService.getAllProblems();
          if (!active) return;
          let maxNum = 0;
          allProblems.forEach((p: any) => {
            const matches = String(p.number ?? "").match(/\d+/g);
            const num = matches ? parseInt(matches[matches.length - 1], 10) : NaN;
            if (!isNaN(num) && num > maxNum) maxNum = num;
          });
          const nextNumber = String(maxNum + 1).padStart(3, '0');
          
          const newProb: TeacherProblem = { 
            id: "new",
            workspaceParts: ["SQL"],
            number: nextNumber, 
            title: "",
            difficulty: "Easy",
            topics: "", 
            database: "SQL Server",
            schemaFileName: "database.sql",
            statement: "",
            requirements: "",
            hints: [],
            schema: "",
            seedData: "",
            seedSummary: "",
            referenceSolution: "",
            sqlSolutions: initialSqlSolutions(),
            triggerExercise: initialStageExercise("TRIGGER"),
            ddlExercise: initialStageExercise("DDL"),
            dmlExercise: initialStageExercise("DML"),
            expectedOutputs: [],
            expectedOutputVisible: true,
            expectedColumns: [],
            expectedRows: [],
            testCases: [{ seedData: "", isHidden: false }],
            canEdit: true,
            canDelete: false
          };
          await finishLoad(newProb);
          return;
        }

        const data = await teacherService.getProblem(selectedId);
        if (!active) return;
        const loadedTopics = parseProblemTopics(data.topics?.length ? data.topics : data.topic);
        const loadedProb: TeacherProblem = {
          id: data.id,
          workspaceParts: Array.isArray(data.workspaceParts) ? data.workspaceParts : ["SQL"],
          number: data.number || "000",
          title: data.title || "",
          difficulty: data.difficulty || "Easy",
          topics: loadedTopics.join(", "),
          database: data.databaseType || "SQL Server",
          schemaFileName: data.schemaFileName || "database.sql",
          databaseDescription: data.databaseDescription || [],
          databaseDescriptionVisible: data.databaseDescriptionVisible !== false,
          statement: mergeLegacyDatabaseDescription(data.description || "",data.databaseDescription || [],data.databaseDescriptionVisible !== false),
          requirements: data.requirements || "",
          hints: data.hint ? data.hint.split('\n') : [],
          schema: data.schema || "",
          seedData: data.seedData || "",
          seedSummary: "",
          referenceSolution: data.referenceSolution || "",
          sqlSolutions: initialSqlSolutions(data.sqlSolutions, data.referenceSolution || "").map((solution, index) => ({ ...solution, description: solution.description || "", expectedOutputs: solution.expectedOutputs?.length ? solution.expectedOutputs : index === 0 ? data.expectedOutputs || [] : [], expectedOutputVisible: solution.expectedOutputVisible ?? data.expectedOutputVisible !== false })),
          triggerExercise: initialStageExercise("TRIGGER", data.triggerExercise),
          ddlExercise: initialStageExercise("DDL", data.ddlExercise),
          dmlExercise: initialStageExercise("DML", data.dmlExercise),
          expectedOutputs: Array.isArray(data.expectedOutputs)
            ? data.expectedOutputs
            : data.expected ? [data.expected] : [],
          expectedOutputVisible: data.expectedOutputVisible !== false,
          testCases: data.testCases && data.testCases.length > 0 ? data.testCases : [{ seedData: data.seedData || "", isHidden: false }],
          expectedColumns: [],
          expectedRows: [],
          canEdit: data.canEdit !== false,
          canDelete: Boolean(data.canDelete)
        };
        await finishLoad(loadedProb);
      } catch(e) {
        if (active) setLoadError(e instanceof Error ? e.message : "Could not load this problem.");
      }
    }
    fetchProblem();
    return () => { active = false; };
  }, [selectedId]);

  const [saveState, setSaveState] = useState("");
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  const hasUnsavedChangesRef = useRef(false);
  const [pendingNavigation, setPendingNavigation] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const fallbackSqlIdRef = useRef(crypto.randomUUID());
  const retrySave = useRef<(destination?: string) => Promise<void>>(async () => {});
  const [validating, setValidating] = useState(false);
  const [dialog, setDialog] = useState<"statement" | null>(null);
  const [datasetNames, setDatasetNames] = useState<string[]>([]);
  useEffect(() => {
    try {
      const stored = JSON.parse(localStorage.getItem(`teacher-dataset-names:${selectedId}`) || "[]");
      setDatasetNames(Array.isArray(stored) ? stored.map((name) => typeof name === "string" ? name : "") : []);
    } catch { setDatasetNames([]); }
  }, [selectedId]);
  function changeDatasetNames(names: string[]) {
    setDatasetNames(names);
    markUnsaved();
    try { localStorage.setItem(`teacher-dataset-names:${selectedId}`, JSON.stringify(names)); } catch { /* Editing remains available when storage is blocked. */ }
  }
  const [previewTab, setPreviewTab] = useState<"Description" | "Database">("Description");
  const [previewPart, setPreviewPart] = useState<WorkspacePart>("SQL");
  const [previewQuestionIds, setPreviewQuestionIds] = useState<Record<WorkspacePart, string>>({ DDL: "", DML: "", SQL: "", TRIGGER:"" });
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewClosing, setPreviewClosing] = useState(false);
  const [previewWidth, setPreviewWidth] = useState(420);
  const {activeSection:activePageSection,setActiveSection:setActivePageSection,navigateToSection}=useSectionNavigation([
    "basic-information","problem-statement","database","database-sql-input","database-setup-panel-data",
    "database-ddl-questions","database-dml-questions","database-trigger-questions","database-dql-questions","database-dql-sql-input",
  ],Boolean(problem),`${databaseTab}|${databaseSetupTab}|${selectedId}|${problem?.id}`);
  const {navigationRef:problemNavigationRef,activeItemRef:activeNavigationLinkRef,indicator:navigationIndicator}=useNavigationIndicator(activePageSection,`${problem?.id}|${selectedId}|${hasUnsavedChanges}|${databaseTab}|${databaseSetupTab}`);
  const splitLayoutRef = useRef<HTMLDivElement>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const canEdit = selectedId === "new" || (problem?.canEdit ?? true);
  const canDelete = selectedId !== "new" && Boolean(problem?.canDelete);

  function markUnsaved() {
    hasUnsavedChangesRef.current = true;
    setHasUnsavedChanges(true);
  }

  function markSaved() {
    hasUnsavedChangesRef.current = false;
    setHasUnsavedChanges(false);
  }

  function requestNavigation(destination: string) {
    if (hasUnsavedChangesRef.current) setPendingNavigation(destination);
    else navigate(destination);
  }

  function getMaxPreviewWidth() {
    const availableWidth = splitLayoutRef.current?.clientWidth ?? 1200;
    return window.innerWidth <= 900
      ? Math.max(320, Math.min(680, availableWidth - 332, window.innerWidth * 0.42))
      : Math.max(320, Math.min(680, availableWidth - 584));
  }

  function resizePreviewTo(width: number) {
    setPreviewWidth(Math.max(320, Math.min(getMaxPreviewWidth(), width)));
  }

  function closePreview() {
    if (!previewOpen || previewClosing) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setPreviewOpen(false);
      return;
    }
    setPreviewClosing(true);
  }

  useEffect(() => {
    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      if (!hasUnsavedChangesRef.current) return;
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", handleBeforeUnload);
    return () => window.removeEventListener("beforeunload", handleBeforeUnload);
  }, []);

  useEffect(() => {
    const handleNavigationClick = (event: MouseEvent) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const target = event.target;
      if (!(target instanceof Element)) return;
      const anchor = target.closest<HTMLAnchorElement>("a[href]");
      if (!anchor || anchor.target === "_blank" || anchor.hasAttribute("download")) return;
      const destination = new URL(anchor.href, window.location.href);
      if (destination.origin !== window.location.origin || !hasUnsavedChangesRef.current) return;
      if (destination.pathname === location.pathname && destination.search === location.search) return;
      event.preventDefault();
      event.stopPropagation();
      setPendingNavigation(`${destination.pathname}${destination.search}${destination.hash}`);
    };
    document.addEventListener("click", handleNavigationClick, true);
    return () => document.removeEventListener("click", handleNavigationClick, true);
  }, [location.pathname, location.search]);

  async function deleteProblem() {
    if (selectedId === "new" || !canDelete || deleteBusy || saving || validating) return;
    setDeleteBusy(true);
    try {
      await teacherService.deleteProblem(selectedId);
      notify.success(tr("Problem deleted"), problem ? `${problem.number} · ${problem.title}` : undefined);
      navigate("/teacher/problems", { replace: true });
    } catch (error) {
      notify.fail(tr("Could not delete this problem."), error, tr);
    } finally {
      setDeleteBusy(false);
    }
  }
  const previewReference = useMemo(() => {
    const schemaTables = parseDatabaseSchema(problem?.schema || "");
    const seed = problem?.testCases?.length ? problem.testCases.find(dataset => !dataset.isHidden)?.seedData || "" : problem?.seedData || "";
    return { schemaTables, tables: parseSeedData(seed, schemaTables) };
  }, [problem?.schema, problem?.testCases, problem?.seedData]);

  const previewStageQuestions = (previewPart === "DDL" ? problem?.ddlExercise : previewPart === "DML" ? problem?.dmlExercise : previewPart === "TRIGGER" ? problem?.triggerExercise : undefined)?.questions || [];
  const previewStageQuestion = previewStageQuestions.find(question => question.id === previewQuestionIds[previewPart]) || previewStageQuestions[0];
  const previewSeed = problem?.testCases?.length ? problem.testCases.find(dataset => !dataset.isHidden)?.seedData || "" : problem?.seedData || "";
  const previewSqlQuestions = problem?.sqlSolutions || [];
  const previewSqlQuestion = previewSqlQuestions.find(question => question.id === previewQuestionIds.SQL) || previewSqlQuestions[0];
  const preparationKey = previewStageQuestion && (previewStageQuestion.setupDdl?.trim() || previewStageQuestion.setupDml?.trim())
    ? JSON.stringify([previewPart,problem?.schema,previewSeed,previewStageQuestion.id,previewStageQuestion.startFrom,previewStageQuestion.setupDdl,previewStageQuestion.setupDml])
    : previewPart === "SQL" && previewSqlQuestion?.setupSql?.trim() ? JSON.stringify([previewPart,problem?.schema,previewSeed,previewSqlQuestion.id,previewSqlQuestion.setupSql]) : null;
  const preparedPreview = usePreparedDatabase(preparationKey, previewOpen && previewTab === "Database", async () => {
    const result = previewPart === "SQL"
      ? await teacherService.validateSolution({schema:problem!.schema, seedData:previewSeed, referenceSolution:"", setupSql:previewSqlQuestion?.setupSql || "", prepareOnly:true})
      : await teacherService.validateStage({part:previewPart === "DDL" ? "DDL" : previewPart === "TRIGGER" ? "TRIGGER" : "DML",schema:problem!.schema,seedData:previewSeed,
      question:{...previewStageQuestion,answer:""},prepareOnly:true});
    return {schemaTables:result.schemaTables || [],tables:result.tables || []};
  });

  if (!problem) {
    return <div className="teacher-page">{loadError ? <ErrorState title={tr("Problem editor unavailable")} message={tr(loadError)} onRetry={() => window.location.reload()} /> : <Loading variant="form" label={tr("Loading problem editor…")} />}</div>;
  }

  const datasetDrafts: DatasetDraft[] = (problem.testCases || [{ seedData: problem.seedData, isHidden: false }])
    .map((dataset, index) => ({ fileName: datasetNames[index] ?? `seed_${index + 1}.sql`, sql: dataset.seedData, isHidden: dataset.isHidden }));
  const hasDatabase = Boolean(problem.schema.trim() || datasetDrafts.some(dataset => dataset.sql.trim()));
  const schemaTables = parseDatabaseSchema(problem.schema || "");
  const previewLibraryEntry = libraryEntries.find((entry) => entry.id === libraryPreviewEntryId) ?? null;
  const previewLibraryDatasets = previewLibraryEntry ? databaseLibraryDatasets(previewLibraryEntry) : [];
  const previewLibraryDataset = previewLibraryDatasets[libraryPreviewDatasetIndex] ?? previewLibraryDatasets[0] ?? null;
  const previewLibraryTables = previewLibraryEntry ? parseDatabaseSchema(previewLibraryEntry.createTableSql || "") : [];
  const previewQuestions: ProblemQuestion[] = previewPart === "SQL" ? (problem.sqlSolutions || []).map(solution => ({
    id: solution.id, description: solution.description, hasSetup:Boolean(solution.setupSql?.trim()),
    expectedSamples: solution.expectedOutputVisible === false ? [] : (problem.testCases || []).flatMap((dataset, index) => {
      const table = solution.expectedOutputs?.[index];
      return !dataset.isHidden && table ? [{ dataset: index + 1, table }] : [];
    }),
  })) : previewStageQuestions.map(question => ({...question,hasSetup:Boolean(question.setupDdl?.trim() || question.setupDml?.trim())}));
  const previewQuestion = previewQuestions.find(question => question.id === previewQuestionIds[previewPart]) || previewQuestions[0];
  const previewStartFrom = previewPart === "SQL" ? undefined : previewQuestion?.startFrom ?? (previewPart === "DDL" ? "empty" : "structure");
  const previewDatabase = questionReference(previewReference, previewStartFrom);
  const pageSections: { id: string; label: string; tab?: "structure" | "schema" | "datasets" | "solution" | "trigger"; nested?: boolean }[] = [
    { id: "basic-information", label: "Basic information" },
    { id: "problem-statement", label: "Problem content" },
    { id: "database", label: "Question set" },
    ...(databaseTab === "structure" ? databaseSetupTab === "tables" ? [] : [
      { id: "database-setup-panel-data", label: "Initial data", tab: "structure" as const, nested: true },
    ] : databaseTab === "schema" ? [
      { id: "database-ddl-questions", label: "DDL questions", tab: "schema" as const, nested: true },
    ] : databaseTab === "trigger" ? [
      { id:"database-trigger-questions",label:"Trigger exercises",tab:"trigger" as const,nested:true },
    ] : databaseTab === "datasets" ? [
      { id: "database-dml-questions", label: "DML questions", tab: "datasets" as const, nested: true },
    ] : [
      { id: "database-dql-questions", label: "DQL questions", tab: "solution" as const, nested: true },
      { id: "database-dql-sql-input", label: "SQL query input", tab: "solution" as const, nested: true },
    ]),
  ];

  function update<K extends keyof TeacherProblem>(
    field: K,
    value: TeacherProblem[K],
  ) {
    if (!canEdit) return;
    setProblem((current) => {
      if (!current) return null;
      const schemaOrDatasetsChanged =
        (field === "schema" && value !== current.schema)
        || (field === "testCases" && JSON.stringify((value as TeacherProblem["testCases"] || []).map((dataset) => dataset.seedData))
          !== JSON.stringify((current.testCases || []).map((dataset) => dataset.seedData)));
      const firstSolutionChanged = field === "sqlSolutions" && (
        ((value as TeacherProblem["sqlSolutions"])?.[0]?.query ?? "") !== (current.sqlSolutions?.[0]?.query ?? "")
        || ((value as TeacherProblem["sqlSolutions"])?.[0]?.setupSql || "") !== (current.sqlSolutions?.[0]?.setupSql || "")
      );
      return {
        ...current,
        [field]: value,
        ...(schemaOrDatasetsChanged ? { expectedOutputs: [], sqlSolutions: current.sqlSolutions?.map(solution => ({ ...solution, expectedOutputs: [] })) } : {}),
        ...(firstSolutionChanged ? { expectedOutputs: [] } : {}),
      };
    });
    markUnsaved();
    setSaveState("Unsaved changes");
  }

  function navigateToProblemSection(event: ReactMouseEvent<HTMLAnchorElement>, section: typeof pageSections[number]) {
    event.preventDefault();
    if (section.tab) setDatabaseTab(section.tab);
    navigateToSection(section.id);
  }

  async function openDatabaseLibrary() {
    if (!canEdit) return;
    setLibraryImportOpen(true);
    setLibraryLoading(true);
    setLibraryError("");
    setLibraryEntries([]);
    setLibraryPreviewEntryId(null);
    setLibraryPreviewDatasetIndex(0);
    try {
      setLibraryEntries(await teacherService.getDatabaseLibrary());
    } catch (error) {
      setLibraryError(error instanceof Error ? error.message : tr("Could not load database library."));
    } finally {
      setLibraryLoading(false);
    }
  }

  function importDatabaseEntry(entry: TeacherDatabaseLibraryEntry) {
    if (!problem || !canEdit) return;
    try {
      const selected = DATABASE_ONLY_IMPORT.sections.filter(section => librarySectionCount(entry, section) > 0);
      if (!selected.length) return;
      const imported = importLibraryBundle(problem, entry, {...DATABASE_ONLY_IMPORT, sections:selected});
      if (imported.datasetNames) changeDatasetNames(imported.datasetNames);
      setProblem(imported.problem);
      setLibraryError("");
    } catch(error) {
      setLibraryError(error instanceof Error ? error.message : tr("Could not import library content."));
      return;
    }
    const order = getNaturalDatabaseId(libraryEntries, entry.id);
    updateDatabaseLibraryMeta(entry.id, entry.title, order);
    markUnsaved();
    setSaveState("Unsaved changes");
    setLibraryImportOpen(false);
  }

  /** Keeps the unsaved problem in this tab, then lets the teacher author a database and come back with it selected. */
  function createNewDatabase() {
    if (!problem || !canEdit) return;
    try {
      sessionStorage.setItem(problemDraftKey(selectedId), JSON.stringify({ problem, datasetNames }));
    } catch {
      toast.show({ variant: "error", title: tr("Could not keep your problem while creating a database. Save it first.") });
      return;
    }
    const returnTo = selectedId === "new" ? "/teacher/problems/new" : `/teacher/problems/${encodeURIComponent(selectedId)}/edit`;
    navigate(`/teacher/database-library?new=1&returnTo=${encodeURIComponent(returnTo)}`);
  }


  async function saveProblem(exitDestination?: string) {
    if (!problem || !canEdit || saving || validating) return;
    if (!problem.number.trim()) {
      setSaveState("Enter a problem number before saving.");
      toast.show({ variant: "error", title: tr("Enter a problem number before saving.") });
      return;
    }
    if (!problem.title.trim()) {
      setSaveState("Enter a title before saving.");
      toast.show({ variant: "error", title: tr("Enter a title before saving.") });
      return;
    }
    // Câu trigger phải có ít nhất một kịch bản kiểm tra (backend cũng chặn).
    const triggerQuestions = problem.triggerExercise?.questions || [];
    const triggerWithoutScenario = triggerQuestions
      .find(question => question.answer.trim() && !(question.probes || []).some(probe => probe.trim()));
    if (triggerWithoutScenario) {
      setSaveState("Each trigger question needs at least one test scenario.");
      toast.show({ variant: "error", title: tr("Each trigger question needs at least one test scenario."), description: questionLabel(triggerQuestions.indexOf(triggerWithoutScenario), triggerWithoutScenario.description, tr("Question")) });
      return;
    }

    await executeSaveProblem(exitDestination);
  }

  async function executeSaveProblem(exitDestination?: string) {
    if (!problem || saving || validating) return;
    const effectiveDbId = databaseLibraryId;
    const effectiveDbTitle = databaseLibraryTitle;
    const effectiveDbOrder = databaseLibraryOrder;

    const payload = {
        number: problem.number,
        title: problem.title,
        workspaceParts: (() => {
          const configured=WORKSPACE_PARTS.filter(part=>part==="SQL" ? (problem.sqlSolutions || []).some(question=>question.query.trim()) || Boolean(problem.referenceSolution.trim()) :
            (part==="DDL"?problem.ddlExercise:part==="DML"?problem.dmlExercise:problem.triggerExercise)?.questions.some(question=>question.answer.trim()));
          return configured.length?configured:problem.workspaceParts || ["SQL"];
        })(),
        topics: problem.topics || "",
        statement: problem.statement,
        hints: problem.hints,
        database: problem.database,
        schemaFileName: problem.schemaFileName || "database.sql",
        databaseDescription: problem.databaseDescription || [],
        databaseDescriptionVisible: problem.databaseDescriptionVisible !== false,
        schema: problem.schema,
        testCases: problem.testCases || [{ seedData: problem.seedData, isHidden: false }],
        referenceSolution: problem.referenceSolution,
        expectedOutputs: problem.expectedOutputs || [],
        expectedOutputVisible: problem.expectedOutputVisible !== false,
        sqlSolutions: problem.sqlSolutions,
        triggerExercise: problem.triggerExercise,
        ddlExercise: problem.ddlExercise,
        dmlExercise: problem.dmlExercise,
    };
    setSaving(true);
    setSaveState("Saving to server...");
    const notification = toast.show({ variant: "loading", title: "Saving…", description: problem.title });
    try {
        let savedProblem: any = null;
        if (selectedId === "new") {
            savedProblem = await teacherService.createProblem(payload);
            if (savedProblem?.id) {
              try {
                localStorage.setItem(`teacher-dataset-names:${savedProblem.id}`, JSON.stringify(datasetNames));
                localStorage.removeItem("teacher-dataset-names:new");
              } catch { /* Storage availability must not prevent saving the problem. */ }
            }
        } else {
            await teacherService.updateProblem(selectedId, payload);
        }
        markSaved();
        setSaveState("Saved");
        toast.update(notification, { variant: "success", title: "All changes saved" });
        if (selectedId === "new" && savedProblem?.id && effectiveDbId) {
          try {
            localStorage.setItem(`teacher-problem-db-id:${savedProblem.id}`, effectiveDbId);
            localStorage.setItem(`teacher-problem-db-title:${savedProblem.id}`, effectiveDbTitle);
            localStorage.setItem(`teacher-problem-db-order:${savedProblem.id}`, effectiveDbOrder);
            localStorage.removeItem("teacher-problem-db-id:new");
            localStorage.removeItem("teacher-problem-db-title:new");
            localStorage.removeItem("teacher-problem-db-order:new");
          } catch {}
        }
        if (exitDestination) {
            navigate(exitDestination);
        } else if (selectedId === "new") {
            const createdId = savedProblem?.id;
            navigate(createdId ? `/teacher/problems/${createdId}/edit` : "/teacher/problems", { replace: true });
        }
    } catch (e) {
        const message = e instanceof Error ? e.message : "Failed to save to server.";
        const isServerError = /internal server error|^server error$/i.test(message.trim());
        if (selectedId !== "new" && isServerError) {
          try {
            const savedProblem = await teacherService.getProblem(selectedId);
            if (problemPayloadWasSaved(savedProblem, payload)) {
              markSaved();
              setSaveState("Saved");
              toast.update(notification, { variant: "success", title: "All changes saved" });
              if (exitDestination) navigate(exitDestination);
              return;
            }
          } catch {
            // Keep the original save error when the confirmation request also fails.
          }
        }
        const saveError = isServerError ? "Save failed" : message;
        setSaveState(saveError);
        toast.update(notification, { variant: "error", title: saveError, duration: 0, action: { label: "Retry", onClick: () => void retrySave.current(exitDestination) } });
    } finally {
        setSaving(false);
    }
  }

  retrySave.current = saveProblem;

  return (
    <section className="teacher-page teacher-problem-page">
      <div
        ref={splitLayoutRef}
        className={`teacher-problem-workspace-layout${previewOpen ? " has-live-preview" : ""}${previewClosing ? " is-closing-preview" : ""}`}
        style={{ "--teacher-preview-width": `${previewWidth}px` } as CSSProperties}
      >
      <div className="teacher-problem-authoring-layout">
        <nav ref={problemNavigationRef} className="teacher-problem-navigation" aria-label={tr("Problem editor sections")}>
          <span
            className="teacher-problem-navigation-indicator"
            aria-hidden="true"
            style={{
              width: `${navigationIndicator.width}px`,
              height: `${navigationIndicator.height}px`,
              transform: `translate(${navigationIndicator.x}px, ${navigationIndicator.y}px)`,
            }}
          />
          <div className="teacher-problem-navigation-toolbar">
            <div className="teacher-problem-toolbar-title">
              <strong><TranslatedText>{tr(selectedId === "new" ? "New problem" : "Edit problem")}</TranslatedText></strong>
              <UnsavedChangesBadge visible={hasUnsavedChanges} />
            </div>
            <div className="teacher-problem-save-exit-actions">
              <button className="button" type="button" disabled={saving || validating} onClick={() => requestNavigation("/teacher/problems")}><TranslatedText>{tr("Exit")}</TranslatedText></button>
              {canEdit && (
                <button className="button primary" type="button" disabled={saving || validating} onClick={() => void saveProblem()}>
                  <TranslatedText>{saving ? tr("Saving…") : tr("Save")}</TranslatedText>
                </button>
              )}
            </div>
          </div>
          <span className="teacher-problem-navigation-label"><TranslatedText>{tr("On this page")}</TranslatedText></span>
          {pageSections.map((section) => (
            <a
              key={section.id}
              ref={activePageSection === section.id ? activeNavigationLinkRef : undefined}
              href={`#${section.id}`}
              className={`${activePageSection === section.id ? "is-active" : ""}${section.nested ? " is-nested" : ""}`.trim()}
              aria-current={activePageSection === section.id ? "location" : undefined}
              onClick={(event) => navigateToProblemSection(event, section)}
            ><TranslatedText>{tr(section.label)}</TranslatedText></a>
          ))}
          <div className="teacher-problem-navigation-actions">
            <button
              className="button teacher-problem-preview-action"
              type="button"
              onClick={() => {
                if (previewOpen && !previewClosing) {
                  closePreview();
                  return;
                }
                setPreviewTab("Description");
                setPreviewPart(databaseTab === "schema" ? "DDL" : databaseTab === "datasets" ? "DML" : databaseTab === "solution" ? "SQL" : databaseTab === "trigger" ? "TRIGGER" : problem.workspaceParts?.[0] || "SQL");
                setPreviewClosing(false);
                setPreviewOpen(true);
              }}
            ><TranslatedText>{tr("Preview")}</TranslatedText></button>
            {canDelete && <button className="button teacher-danger-button" type="button" disabled={saving || validating || deleteBusy} onClick={() => setConfirmDelete(true)}><TranslatedText>{tr("Delete")}</TranslatedText></button>}
          </div>
        </nav>
        <section className="teacher-problem-card teacher-editor-details">
          {!canEdit && (
            <div className="teacher-readonly-banner" role="status" style={{
              padding: "10px 14px",
              marginBottom: "16px",
              borderRadius: "8px",
              border: "1px solid var(--border-subtle, #e2e8f0)",
              background: "var(--surface-muted, #f8fafc)",
              color: "var(--text-secondary, #475569)",
              fontSize: "0.9rem",
              fontWeight: 500,
            }}>
              Bạn chỉ có quyền xem bài toán này và không thể chỉnh sửa nội dung.
            </div>
          )}
          <section id="basic-information" className="teacher-problem-details-section teacher-problem-basics-section">
            <TeacherSectionTitle title={tr("Basic information")} />
          <div className="teacher-field-row" style={{ display: 'grid', gridTemplateColumns: '80px 1fr', gap: '16px' }}>
            <TeacherField label={tr("NUMBER")}>
              <input
                value={problem.number}
                disabled={!canEdit}
                readOnly={!canEdit}
                onChange={(event) => update("number", event.target.value)}
              />
            </TeacherField>
            <TeacherField label={tr("TITLE")}>
              <input
                value={problem.title}
                disabled={!canEdit}
                readOnly={!canEdit}
                onChange={(event) => update("title", event.target.value)}
              />
            </TeacherField>
          </div>
          </section>
          <section id="problem-statement" className="teacher-problem-details-section teacher-problem-content-section">
            <TeacherSectionTitle title={tr("Problem content")} />
          <div className="teacher-field teacher-long-text-field">
            <ProblemRichTextEditor
              value={problem.statement}
              label={tr("Problem statement")}
              disabled={!canEdit}
              onChange={(value) => update("statement", value)}
              onOpenExpanded={() => setDialog("statement")}
            />
          </div>
          </section>
        <section id="database" className="teacher-problem-database-section">
          <div className="teacher-problem-database-heading">
            <div className="teacher-problem-database-heading-left">
              <TeacherSectionTitle title={tr("Question set")} />
            </div>

            <span className="teacher-database-dialect-badge">SQL Server</span>
          </div>
          <div className="teacher-problem-database-toolbar">
          <GlidingTooltipGroup className="teacher-problem-database-tabs" role="tablist" label={tr("Database configuration")} multiline>
            {([
              ["structure", "Database schema", "DDL"],
              ["schema", WORKSPACE_PART_LABELS.DDL, "DDL"],
              ["datasets", WORKSPACE_PART_LABELS.DML, "DML"],
              ["solution", WORKSPACE_PART_LABELS.SQL, "SQL"],
              ["trigger", WORKSPACE_PART_LABELS.TRIGGER, "TRIGGER"],
            ] as const).map(([tab, label, part]) => (
              <button
                key={tab}
                type="button"
                role="tab"
                data-tooltip={tr(WORKSPACE_PART_TOOLTIPS[part])}
                aria-selected={databaseTab === tab}
                aria-controls={`database-panel-${tab}`}
                className={databaseTab === tab ? "is-active" : ""}
                onClick={() => {
                  setDatabaseTab(tab);
                  setActivePageSection("database");
                }}
              ><TranslatedText>{tr(label)}</TranslatedText></button>
            ))}
          </GlidingTooltipGroup>
          </div>
          {databaseTab === "structure" && <section id="database-panel-structure" role="tabpanel" className="teacher-validation-section">
            {hasDatabase && <div className="teacher-database-selected-bar">
              <span title={databaseLibraryTitle}><Database size={14} aria-hidden="true" />{databaseLibraryTitle || tr("Unnamed")}</span>
              {canEdit && <button className="button" type="button" onClick={() => void openDatabaseLibrary()}><Download size={13} aria-hidden="true" /><TranslatedText>{tr("Choose from library")}</TranslatedText></button>}
              {canEdit && <button className="button" type="button" onClick={createNewDatabase}><Plus size={13} aria-hidden="true" /><TranslatedText>{tr("Create new")}</TranslatedText></button>}
            </div>}
            <div className="teacher-database-setup-toolbar">
              <div className="teacher-database-setup-tabs" role="tablist" aria-label={tr("Database setup")}>
                <span className="teacher-database-setup-indicator" aria-hidden="true" style={{ transform: `translateX(${databaseSetupTab === "data" ? 100 : 0}%)` }} />
                {(["tables", "data"] as const).map(tab => <button
                  key={tab} id={`database-setup-tab-${tab}`} type="button" role="tab"
                  aria-selected={databaseSetupTab === tab} aria-controls={`database-setup-panel-${tab}`}
                  tabIndex={databaseSetupTab === tab ? 0 : -1}
                  onClick={() => setDatabaseSetupTab(tab)}
                  onKeyDown={event => {
                    if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
                    event.preventDefault();
                    const next = event.key === "Home" ? "tables" : event.key === "End" ? "data" : tab === "tables" ? "data" : "tables";
                    setDatabaseSetupTab(next);
                    document.getElementById(`database-setup-tab-${next}`)?.focus();
                  }}
                ><TranslatedText>{tr(tab === "tables" ? "Tables" : "Initial data")}</TranslatedText></button>)}
              </div>

            </div>
            {!hasDatabase && <div className="teacher-database-empty-state" role="status">
              <Database size={26} aria-hidden="true" />
              <strong><TranslatedText>{tr("No database selected")}</TranslatedText></strong>
              <p><TranslatedText>{tr("Choose a database from the library. If none fits this problem, create a new one and you will return here.")}</TranslatedText></p>
              {canEdit && <div className="teacher-database-empty-actions">
                <button className="button primary" type="button" onClick={() => void openDatabaseLibrary()}><Download size={14} aria-hidden="true" /><TranslatedText>{tr("Choose from library")}</TranslatedText></button>
                <button className="button" type="button" onClick={createNewDatabase}><Plus size={14} aria-hidden="true" /><TranslatedText>{tr("New database")}</TranslatedText></button>
              </div>}
            </div>}
            {hasDatabase && databaseSetupTab === "tables" && <div id="database-setup-panel-tables" role="tabpanel" aria-labelledby="database-setup-tab-tables">
              <div id="database-sql-input" className="teacher-problem-navigation-target">
                {schemaTables.length > 0
                  ? <TeacherSchemaDiagram tables={schemaTables} showTitle={false} />
                  : <p className="teacher-schema-diagram-message is-error"><TranslatedText>{tr("No CREATE TABLE statements in this database.")}</TranslatedText></p>}
              </div>
            </div>}
            {hasDatabase && databaseSetupTab === "data" && <div id="database-setup-panel-data" role="tabpanel" aria-labelledby="database-setup-tab-data" className="teacher-test-cases-section">
              {datasetDrafts.map((dataset, index) => <section key={index} className="teacher-database-readonly-dataset">
                <h4>{tr("Dataset {value0}", { value0: index + 1 })}{dataset.isHidden ? <small> · <TranslatedText>{tr("Hidden dataset")}</TranslatedText></small> : null}</h4>
                <DatasetDataPreview datasetNumber={index + 1} seedData={dataset.sql} />
              </section>)}
            </div>}
          </section>}
          <section id="database-panel-schema" role="tabpanel" hidden={databaseTab !== "schema"} style={databaseTab === "schema" ? undefined : { display: "none" }} className="teacher-validation-section">
            <div id="database-ddl-questions" className="teacher-problem-navigation-target">
              <TeacherStageExercises part="DDL" exercise={initialStageExercise("DDL", problem.ddlExercise)} schema={problem.schema}
                datasets={problem.testCases || [{ seedData: problem.seedData || "", isHidden: false }]}
                readOnly={!canEdit}
                onChange={exercise => update("ddlExercise", exercise)} onRunningChange={setValidating} />
            </div>
          </section>
          <section id="database-panel-trigger" role="tabpanel" hidden={databaseTab !== "trigger"} style={databaseTab === "trigger" ? undefined : { display: "none" }} className="teacher-validation-section">
            <div id="database-trigger-questions" className="teacher-problem-navigation-target">
              <TeacherStageExercises part="TRIGGER" exercise={initialStageExercise("TRIGGER",problem.triggerExercise)} schema={problem.schema}
                datasets={problem.testCases || [{seedData:problem.seedData,isHidden:false}]} readOnly={!canEdit} onChange={exercise=>update("triggerExercise",exercise)} onRunningChange={setValidating}/>
            </div>
          </section>
          <section id="database-panel-datasets" role="tabpanel" hidden={databaseTab !== "datasets"} style={databaseTab === "datasets" ? undefined : { display: "none" }} className="teacher-validation-section">
            <div id="database-dml-questions" className="teacher-problem-navigation-target">
              <TeacherStageExercises part="DML" exercise={initialStageExercise("DML", problem.dmlExercise)} schema={problem.schema}
                datasets={problem.testCases || [{ seedData: problem.seedData || "", isHidden: false }]}
                readOnly={!canEdit}
                onChange={exercise => update("dmlExercise", exercise)} onRunningChange={setValidating} />
            </div>
          </section>
          <div id="database-panel-solution" role="tabpanel" hidden={databaseTab !== "solution"} style={databaseTab === "solution" ? undefined : { display: "none" }}>
            <div id="database-dql-questions" className="teacher-problem-navigation-target">
            <TeacherSqlSolutions
              solutions={initialSqlSolutions(problem.sqlSolutions, problem.referenceSolution, fallbackSqlIdRef.current)}
              schema={problem.schema}
              datasets={problem.testCases || [{ seedData: problem.seedData }]}
              readOnly={!canEdit}
              onChange={(solutions) => {
                update("sqlSolutions", solutions);
                update("referenceSolution", solutions[0]?.query || "");
              }}
              onExpectedOutputsChange={(outputs) => {
                update("expectedOutputs", outputs);
              }}
              onRunningChange={setValidating}
            />
            </div>
          </div>
        </section>
        </section>


      </div>
      {previewOpen && (
        <aside
          className={`teacher-student-preview-panel${previewClosing ? " is-closing" : ""}`}
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
              const bounds = splitLayoutRef.current?.getBoundingClientRect();
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
            <Select className="teacher-preview-part-picker" aria-label={tr("Preview section")} value={previewPart}
              onChange={event => setPreviewPart(event.target.value as WorkspacePart)}>
              {(["DDL", "DML", "SQL", "TRIGGER"] as const).map(part => <option key={part} value={part}>{WORKSPACE_PART_LABELS[part]}</option>)}
            </Select>
            <button type="button" className="icon-button" aria-label={tr("Close preview")} title={tr("Close preview")} onClick={closePreview}>
              <X size={18} aria-hidden="true" />
            </button>
          </header>
          <div className="teacher-student-preview">
            <div className="teacher-preview-tabs" role="tablist" aria-label={tr("Student preview sections")}>
              {(["Description", "Database"] as const).map((tab) => (
                <button
                  key={tab}
                  type="button"
                  role="tab"
                  aria-selected={previewTab === tab}
                  className={previewTab === tab ? "active" : ""}
                  onClick={() => setPreviewTab(tab)}
                ><TranslatedText>{tr(tab)}</TranslatedText></button>
              ))}
            </div>
            <div className="teacher-student-preview-scroll">
              {previewTab === "Description" ? (
                <div className="teacher-student-preview-content">
                  <ProblemQuestionContent key={previewPart} part={previewPart}
                    problem={{ title: problem.title || tr("Untitled problem"), description: problem.statement,
                      databaseDescription: problem.databaseDescription, databaseDescriptionVisible: problem.databaseDescriptionVisible }}
                    questions={previewQuestions} selectedId={previewQuestion?.id}
                    onQuestionChange={id => setPreviewQuestionIds(current => ({ ...current, [previewPart]: id }))} />
                </div>
              ) : (
                <div className="teacher-student-preview-content teacher-student-preview-database">
                  <WorkspaceDatabasePanel key={`${previewPart}-${previewQuestion?.id}`} database={preparedPreview.data || previewDatabase} loading={preparedPreview.loading} error={preparedPreview.error} onRetry={preparedPreview.retry} prepared={Boolean(preparationKey)} questionName={tr("Question {value0} {value1}",{value0:Math.max(0,previewQuestions.findIndex(question => question.id === previewQuestion?.id)) + 1,value1:WORKSPACE_PART_LABELS[previewPart]})} questionStartFrom={previewStartFrom} />
                </div>
              )}
            </div>
          </div>
        </aside>
      )}
      </div>
      {libraryImportOpen && (
        <Dialog title={tr("Choose from database library")} onClose={() => setLibraryImportOpen(false)} className="teacher-database-import-dialog">
          <div className="teacher-database-import-content">
            <p><TranslatedText>{tr("Choose a saved database and review its tables and data before using it.")}</TranslatedText></p>
            {libraryLoading && <Loading variant="table" columns={3} rows={4} label={tr("Loading database library…")} />}
            {libraryError && <p className="teacher-state-failed" role="alert">{libraryError}</p>}
            {!libraryLoading && !libraryError && !libraryEntries.length && <p><TranslatedText>{tr("No saved databases yet.")}</TranslatedText></p>}
            {!libraryLoading && libraryEntries.length > 0 && <div className="teacher-database-import-workspace">
              <nav className="teacher-database-import-list" aria-label={tr("Saved databases")}>
                {libraryEntries.map((entry) => {
                  const isSelected = libraryPreviewEntryId === entry.id;
                  const datasetCount = databaseLibraryDatasets(entry).length;
                  return (
                    <div key={entry.id} className={`teacher-database-import-item${isSelected ? " is-active" : ""}`}>
                      <span className="teacher-database-import-item-icon"><Database size={16} aria-hidden="true" /></span>
                      <span className="teacher-database-import-item-copy">
                        <strong>{entry.title}</strong>
                        <small><TranslatedText>{tr("{value0} datasets", { value0: datasetCount })}</TranslatedText></small>
                      </span>
                      <button className="button teacher-database-import-preview-button" type="button" aria-pressed={isSelected} onClick={() => {
                        setLibraryPreviewEntryId(entry.id);
                        setLibraryPreviewDatasetIndex(0);
                        setLibraryError("");
                      }}><Eye size={13} aria-hidden="true" /><TranslatedText>{tr("Preview")}</TranslatedText></button>
                    </div>
                  );
                })}
              </nav>

              <div className="teacher-database-import-preview">
                {previewLibraryEntry ? <>
                  <header className="teacher-database-import-preview-heading">
                    <div><strong>{previewLibraryEntry.title}</strong></div>
                    <span className="teacher-database-import-preview-badge"><Database size={12} aria-hidden="true" />SQL Server</span>
                  </header>
                  <div className="teacher-database-import-preview-sections">
                    <section className="teacher-database-import-schema-preview">
                      <h3><TranslatedText>{tr("Database diagram")}</TranslatedText></h3>
                      {previewLibraryTables.length > 0
                        ? <TeacherSchemaDiagram tables={previewLibraryTables} />
                        : <p className="teacher-schema-diagram-message is-error"><TranslatedText>{tr("No CREATE TABLE statements in this database.")}</TranslatedText></p>}
                    </section>
                    <section className="teacher-database-import-data-preview">
                      <div className="teacher-database-import-data-heading">
                        <h3><TranslatedText>{tr("Test datasets")}</TranslatedText></h3>
                        <label>
                          <span><TranslatedText>{tr("Select test dataset")}</TranslatedText></span>
                          <Select value={libraryPreviewDatasetIndex} onChange={(event) => setLibraryPreviewDatasetIndex(Number(event.target.value))}>
                            {previewLibraryDatasets.map((dataset, index) => <option key={`${dataset.fileName}-${index}`} value={index}>{tr("Dataset {value0}", { value0: index + 1 })}</option>)}
                          </Select>
                        </label>
                      </div>
                      {previewLibraryDataset && <DatasetDataPreview datasetNumber={libraryPreviewDatasetIndex + 1} seedData={previewLibraryDataset.sql || ""} />}
                    </section>
                    <p className="muted"><TranslatedText>{tr("Choosing a database replaces this problem's tables and initial datasets. Questions are kept.")}</TranslatedText></p>
                  </div>
                  <div className="teacher-database-import-dialog-actions">
                    <button className="button" type="button" onClick={() => setLibraryImportOpen(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
                    <button className="button primary" type="button" disabled={!DATABASE_ONLY_IMPORT.sections.some(section => librarySectionCount(previewLibraryEntry, section) > 0)} onClick={() => importDatabaseEntry(previewLibraryEntry)}><Download size={14} aria-hidden="true" /><TranslatedText>{tr("Use this database")}</TranslatedText></button>
                  </div>
                </> : <div className="teacher-database-import-preview-empty"><Database size={24} aria-hidden="true" /><p><TranslatedText>{tr("Select a database to preview its tables and data.")}</TranslatedText></p></div>}
              </div>
            </div>}
          </div>
        </Dialog>
      )}
      {pendingNavigation && (
        <Dialog title={tr("Unsaved changes")} onClose={() => setPendingNavigation(null)}>
          <div className="teacher-preview-dialog">
            <p><TranslatedText>{tr("You have unsaved changes. Would you like to save before leaving?")}</TranslatedText></p>
          {!saving && saveState && saveState !== "Unsaved changes" && saveState !== "Saved" && <p className="teacher-state-failed" role="alert"><TranslatedText>{tr(saveState)}</TranslatedText></p>}
            <div className="teacher-dialog-actions">
              <button className="button" type="button" disabled={saving} onClick={() => setPendingNavigation(null)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
              <button className="button primary" type="button" disabled={saving || validating || !problem.title.trim() || !problem.number.trim()} onClick={() => { if (pendingNavigation) void saveProblem(pendingNavigation); }}><TranslatedText>{tr("Save and exit")}</TranslatedText></button>
              <button className="button teacher-danger-button" type="button" disabled={saving} onClick={() => {
                const destination = pendingNavigation;
                setPendingNavigation(null);
                if (selectedId === "new") {
                  try {
                    localStorage.removeItem("teacher-problem-db-id:new");
                    localStorage.removeItem("teacher-problem-db-title:new");
                    localStorage.removeItem("teacher-problem-db-order:new");
                    localStorage.removeItem("teacher-dataset-names:new");
                    sessionStorage.removeItem(problemDraftKey("new"));
                  } catch {}
                }
                if (destination) navigate(destination);
              }}><TranslatedText>{tr("Leave without saving")}</TranslatedText></button>
            </div>
          </div>
        </Dialog>
      )}
      {confirmDelete && (
        <Dialog title={tr("Delete problem")} onClose={() => { if (!deleteBusy) setConfirmDelete(false); }}>
          <div className="teacher-preview-dialog">
            <p><TranslatedText>{tr("Delete")}</TranslatedText> <strong>{problem.number} · {problem.title}</strong>?</p>
            <p className="muted"><TranslatedText>{tr("This cannot be undone. Related submissions and saved drafts will also be removed.")}</TranslatedText></p>
            <div className="teacher-dialog-actions">
              <button className="button" type="button" disabled={deleteBusy} onClick={() => setConfirmDelete(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
              <button className="button teacher-danger-button" type="button" disabled={deleteBusy} onClick={() => void deleteProblem()}><TranslatedText>{deleteBusy ? tr("Deleting…") : tr("Delete")}</TranslatedText></button>
            </div>
          </div>
        </Dialog>
      )}
      {dialog && (
        <Dialog
          title={tr("Edit statement")}
          className="teacher-long-text-dialog"
          onClose={() => setDialog(null)}
          closeOnBackdrop={false}
          closeOnCancel={false}
        >
          <div className="teacher-long-text-dialog-body">
            <div className="teacher-long-text-editor-panel">
              <ProblemRichTextEditor
                value={problem[dialog]}
                label={tr("Problem statement")}
                disabled={!canEdit}
                onChange={(value) => update(dialog, value)}
                expanded
                onDone={() => setDialog(null)}
              />
            </div>
          </div>
        </Dialog>
      )}
    </section>
  );
}
