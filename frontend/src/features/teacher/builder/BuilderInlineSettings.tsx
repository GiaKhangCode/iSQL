import { SlidingDisclosure } from "@/shared/ui/SlidingDisclosure";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import {
  ChevronDown,
  Settings,
  Settings2,
  Sparkles,
  Plus,
  Trash2,
  CheckCircle2,
  CopyCheck,
  Layers,
  Tag,
  RefreshCw,
  Search,
} from "lucide-react";
import type { WorkspacePart } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { Select } from "@/shared/ui/Select";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { Dialog } from "@/shared/ui/ui";
import { WORKSPACE_PARTS, WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import {
  catalogOf,
  type BuilderProblemDetail,
  type QuestionSelection,
  type AutoQuestionGroupRule,
  type SharedQuestionRule,
  type ExamVariant,
  type QuestionOption,
} from "@/features/teacher/builder/BuilderProblemSettings";
import { BuilderDatabaseReference } from "@/features/teacher/builder/BuilderDatabaseReference";
import { BuilderManualQuestionPicker } from "@/features/teacher/builder/BuilderManualQuestionPicker";
import { useExerciseTags } from "@/features/teacher/problem-editor/QuestionTagSelectors";
import { toast } from "@/shared/ui/StackingToasts";
import "@/styles/builder-problem-settings.css";

export function BuilderInlineSettings({
  problemId,
  title,
  detail,
  parts = [...WORKSPACE_PARTS],
  questionSelection = {},
  onQuestionSelectionChange,
  onPartsChange,
  onBatchChange,
  open,
  onToggle,
  error,
  onRetry,
  hideTrigger = false,
  selectionMode = "auto",
  visibility = { databaseStructureVisible: true, databaseDescriptionVisible: true },
  onVisibilityChange,
  datasetVisibility = {},
  onDatasetVisibilityChange,
  locked = false,
  lockedQuestionSelection,
  lockedParts,
}: {
  /** "manual": the teacher ticks the exact questions (assignments). "auto": question-group rules and random variants (exams). */
  selectionMode?: "auto" | "manual";
  problemId: string;
  title: string;
  detail: BuilderProblemDetail | null;
  parts?: WorkspacePart[];
  questionSelection?: QuestionSelection;
  onQuestionSelectionChange: (selection: QuestionSelection) => void;
  onPartsChange: (parts: WorkspacePart[]) => void;
  onBatchChange?: (patch: any) => void;
  open: boolean;
  onToggle: () => void;
  error?: string;
  onRetry?: () => void;
  hideTrigger?: boolean;
  visibility?: { databaseStructureVisible: boolean; databaseDescriptionVisible: boolean };
  onVisibilityChange?: (visibility: { databaseStructureVisible: boolean; databaseDescriptionVisible: boolean }) => void;
  datasetVisibility?: Record<string, boolean>;
  onDatasetVisibilityChange?: (visibility: Record<string, boolean>) => void;
  locked?: boolean;
  lockedQuestionSelection?: QuestionSelection;
  lockedParts?: WorkspacePart[];
}) {
  const { t: tr } = useLanguage("teacher");
  const contentId = useId();
  const [activeTab, setActiveTab] = useState<WorkspacePart>("DDL");
  const [previewPart, setPreviewPart] = useState<WorkspacePart>("DDL");

  const { tags: exerciseTags } = useExerciseTags();
  const [autoRules, setAutoRules] = useState<Record<WorkspacePart, AutoQuestionGroupRule[]>>(() => {
    return (questionSelection as any)?._autoRules || {
      DDL: [],
      DML: [],
      SQL: [],
      TRIGGER: [],
    };
  });

  const [sharedRules, setSharedRules] = useState<Record<WorkspacePart, SharedQuestionRule[]>>(() => {
    const rawRules = (questionSelection as any)?._sharedRules;
    if (rawRules) {
      return {
        DDL: rawRules.DDL || [],
        DML: rawRules.DML || [],
        SQL: rawRules.SQL || [],
        TRIGGER: rawRules.TRIGGER || [],
      };
    }
    const rawShared = (questionSelection as any)?._sharedQuestions || {};
    const initial: Record<WorkspacePart, SharedQuestionRule[]> = {
      DDL: [],
      DML: [],
      SQL: [],
      TRIGGER: [],
    };
    for (const p of WORKSPACE_PARTS) {
      const qIds = rawShared[p] || [];
      initial[p] = qIds.map((qId: string) => ({
        id: crypto.randomUUID(),
        difficulty: "ALL",
        category: "ALL",
        questionId: qId,
      }));
    }
    return initial;
  });

  const sharedQuestions = useMemo<Record<WorkspacePart, string[]>>(() => {
    const res: Record<WorkspacePart, string[]> = {
      DDL: [],
      DML: [],
      SQL: [],
      TRIGGER: [],
    };
    for (const p of WORKSPACE_PARTS) {
      res[p] = (sharedRules[p] || []).map((r) => r.questionId).filter(Boolean);
    }
    return res;
  }, [sharedRules]);


  const [highlightedDuplicateIds, setHighlightedDuplicateIds] = useState<Set<string>>(new Set());
  const [conflictSharedIds, setConflictSharedIds] = useState<Set<string>>(new Set());

  const [variantCount, setVariantCount] = useState<number>(() => (questionSelection as any)?._variantCount || 2);
  const [variantPrefix, setVariantPrefix] = useState<string>(() => {
    return (questionSelection as any)?._variantPrefix ?? (questionSelection as any)?._variantSuffix ?? "";
  });
  const [variants, setVariants] = useState<ExamVariant[]>(() => (questionSelection as any)?._variants || []);
  const [activeVariantCode, setActiveVariantCode] = useState<string>(() => {
    const list = (questionSelection as any)?._variants;
    return list && list.length > 0 ? list[0].code : "1";
  });

  const [openMenuQuestionId, setOpenMenuQuestionId] = useState<string | null>(null);
  const [swapModalTarget, setSwapModalTarget] = useState<{
    part: WorkspacePart;
    questionId: string;
    isShared: boolean;
  } | null>(null);
  const [swapCategoryFilter, setSwapCategoryFilter] = useState<string>("ALL");
  const [swapDifficultyFilter, setSwapDifficultyFilter] = useState<string>("ALL");
  const [swapSearchQuery, setSwapSearchQuery] = useState<string>("");
  const [collapsedCategories, setCollapsedCategories] = useState<Set<string>>(new Set());
  const contextMenuRef = useRef<HTMLDivElement>(null);

  function toggleCategoryCollapse(cat: string) {
    setCollapsedCategories((prev) => {
      const next = new Set(prev);
      if (next.has(cat)) {
        next.delete(cat);
      } else {
        next.add(cat);
      }
      return next;
    });
  }

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (contextMenuRef.current && !contextMenuRef.current.contains(e.target as Node)) {
        setOpenMenuQuestionId(null);
      }
    }
    if (openMenuQuestionId) {
      document.addEventListener("mousedown", handleClickOutside);
      return () => document.removeEventListener("mousedown", handleClickOutside);
    }
  }, [openMenuQuestionId]);

  useEffect(() => {
    if ((questionSelection as any)?._autoRules) {
      setAutoRules((questionSelection as any)._autoRules);
    }
    if ((questionSelection as any)?._sharedRules) {
      setSharedRules((questionSelection as any)._sharedRules);
    } else if ((questionSelection as any)?._sharedQuestions) {
      const rawShared = (questionSelection as any)._sharedQuestions;
      const initial: Record<WorkspacePart, SharedQuestionRule[]> = {
        DDL: [],
        DML: [],
        SQL: [],
        TRIGGER: [],
      };
      for (const p of WORKSPACE_PARTS) {
        const qIds = rawShared[p] || [];
        initial[p] = qIds.map((qId: string) => ({
          id: crypto.randomUUID(),
          difficulty: "ALL",
          category: "ALL",
          questionId: qId,
        }));
      }
      setSharedRules(initial);
    }
    if ((questionSelection as any)?._variants) {
      setVariants((questionSelection as any)._variants);
      if ((questionSelection as any)._variants.length > 0) {
        setActiveVariantCode((prev) => prev || (questionSelection as any)._variants[0].code);
      }
    }
    if ((questionSelection as any)?._variantCount) {
      setVariantCount((questionSelection as any)._variantCount);
    }
    if (typeof (questionSelection as any)?._variantPrefix === "string") {
      setVariantPrefix((questionSelection as any)._variantPrefix);
    } else if (typeof (questionSelection as any)?._variantSuffix === "string") {
      setVariantPrefix((questionSelection as any)._variantSuffix);
    }
  }, [questionSelection]);

  const catalog = useMemo(() => catalogOf(detail), [detail]);

  useEffect(() => {
    if (swapModalTarget) {
      const q = (catalog[swapModalTarget.part] || []).find((item) => item.id === swapModalTarget.questionId);
      setSwapCategoryFilter(q?.category || "ALL");
      setSwapDifficultyFilter("ALL");
      setSwapSearchQuery("");
    }
  }, [swapModalTarget, catalog]);

  const difficulties = useMemo(() => {
    const diffs = exerciseTags.filter((t) => t.kind === "difficulty");
    return diffs.length
      ? diffs
      : [
          { id: "easy", name: "Dễ" },
          { id: "medium", name: "Trung bình" },
          { id: "hard", name: "Khó" },
        ];
  }, [exerciseTags]);

  const partCategories = useMemo(() => {
    const partTag = activeTab === "SQL" ? "DQL" : activeTab;
    const fromTags = exerciseTags
      .filter((t) => t.kind === "category" && (t.part === "ALL" || t.part === partTag || t.part === activeTab))
      .map((t) => ({ id: t.id, name: t.name }));
    const defaults: Record<WorkspacePart, { id: string; name: string }[]> = {
      DDL: [
        { id: "ddl-create", name: "DDL - Tạo bảng (CREATE TABLE)" },
        { id: "ddl-alter", name: "DDL - Sửa cấu trúc (ALTER TABLE)" },
        { id: "ddl-drop", name: "DDL - Xóa đối tượng (DROP/TRUNCATE)" },
        { id: "ddl-constraint", name: "DDL - Ràng buộc toàn vẹn (CONSTRAINT)" },
      ],
      DML: [
        { id: "dml-insert", name: "DML - Thêm dòng dữ liệu (INSERT)" },
        { id: "dml-update", name: "DML - Cập nhật dữ liệu (UPDATE)" },
        { id: "dml-delete", name: "DML - Xóa dòng dữ liệu (DELETE)" },
      ],
      SQL: [
        { id: "dql-basic", name: "DQL - Truy vấn cơ bản (SELECT/WHERE)" },
        { id: "dql-join", name: "DQL - Kết nối nhiều bảng (JOIN)" },
        { id: "dql-group", name: "DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)" },
        { id: "dql-subquery", name: "DQL - Truy vấn con (SUBQUERY)" },
      ],
      TRIGGER: [
        { id: "trg-business", name: "Trigger - Ràng buộc nghiệp vụ (Trigger)" },
        { id: "trg-audit", name: "Trigger - Ghi vết và lịch sử (Audit)" },
      ],
    };
    const list = fromTags.length > 0 ? [...fromTags] : [...(defaults[activeTab] || [])];
    const seenNames = new Set(list.map((c) => c.name.toLowerCase()));
    (catalog[activeTab] || []).forEach((q) => {
      if (q.category && !seenNames.has(q.category.trim().toLowerCase())) {
        seenNames.add(q.category.trim().toLowerCase());
        list.push({ id: `cat-${q.category}`, name: q.category.trim() });
      }
    });
    return list;
  }, [exerciseTags, activeTab, catalog]);

  const previewCategories = useMemo(() => {
    const partTag = previewPart === "SQL" ? "DQL" : previewPart;
    const fromTags = exerciseTags
      .filter((t) => t.kind === "category" && (t.part === "ALL" || t.part === partTag))
      .map((t) => t.name);
    const defaults: Record<WorkspacePart, string[]> = {
      DDL: [
        "DDL - Tạo bảng (CREATE TABLE)",
        "DDL - Sửa cấu trúc (ALTER TABLE)",
        "DDL - Xóa đối tượng (DROP/TRUNCATE)",
        "DDL - Ràng buộc toàn vẹn (CONSTRAINT)",
      ],
      DML: [
        "DML - Thêm dòng dữ liệu (INSERT)",
        "DML - Cập nhật dữ liệu (UPDATE)",
        "DML - Xóa dữ liệu (DELETE)",
      ],
      SQL: [
        "DQL - Truy vấn cơ bản (SELECT/WHERE)",
        "DQL - Kết nối nhiều bảng (JOIN)",
        "DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)",
        "DQL - Truy vấn con (SUBQUERY)",
      ],
      TRIGGER: [
        "Trigger - Ràng buộc nghiệp vụ (Trigger)",
        "Trigger - Ghi vết và lịch sử (Audit)",
      ],
    };
    const set = new Set<string>([...fromTags, ...(defaults[previewPart] || [])]);
    (catalog[previewPart] || []).forEach((q) => {
      if (q.category) set.add(q.category);
    });
    return Array.from(set);
  }, [exerciseTags, previewPart, catalog]);

  const activeVariant = useMemo(() => {
    return variants.find((v) => v.code === activeVariantCode) || variants[0] || null;
  }, [variants, activeVariantCode]);

  const duplicateQuestionIds = useMemo(() => {
    const dups = new Set<string>(conflictSharedIds);
    const currentShared = (sharedRules[previewPart] || []).map((r) => r.questionId).filter(Boolean);
    const seen = new Set<string>();
    for (const id of currentShared) {
      if (seen.has(id)) {
        dups.add(id);
      } else {
        seen.add(id);
      }
    }
    return dups;
  }, [conflictSharedIds, sharedRules, previewPart]);

  function getDuplicatesAcrossParts(): { hasDuplicates: boolean; allDuplicateIds: Set<string> } {
    const allDuplicateIds = new Set<string>();
    let hasDuplicates = false;

    for (const p of WORKSPACE_PARTS) {
      const rules = autoRules[p] || [];
      const seen = new Map<string, string>();
      for (const r of rules) {
        const key = `${r.difficulty.trim()}:::${r.category.trim()}`;
        if (seen.has(key)) {
          hasDuplicates = true;
          allDuplicateIds.add(r.id);
          allDuplicateIds.add(seen.get(key)!);
        } else {
          seen.set(key, r.id);
        }
      }
    }
    return { hasDuplicates, allDuplicateIds };
  }

  function getMatchingQuestions(
    part: WorkspacePart,
    rule: { difficulty?: string; category?: string },
    excludeIds: Set<string> = new Set()
  ) {
    const choices = (catalog[part] || []).filter((q) => !excludeIds.has(q.id));
    return choices.filter((q) => {
      if (rule.difficulty && rule.difficulty !== "ALL") {
        if (!q.difficulty) return false;
        const normalizedRule = rule.difficulty.trim().toLowerCase();
        const normalizedQ = q.difficulty.trim().toLowerCase();
        const diffMap: Record<string, string[]> = {
          "dễ": ["dễ", "easy"],
          "easy": ["dễ", "easy"],
          "trung bình": ["trung bình", "medium"],
          "medium": ["trung bình", "medium"],
          "khó": ["khó", "hard"],
          "hard": ["khó", "hard"],
        };
        const allowed = diffMap[normalizedRule] || [normalizedRule];
        if (!allowed.includes(normalizedQ)) return false;
      }
      if (rule.category && rule.category !== "ALL") {
        if (!q.category) return false;
        const normRule = rule.category.trim().toLowerCase();
        const normQ = q.category.trim().toLowerCase();
        if (normQ !== normRule) {
          const cleanRule = normRule.replace(/\s*\(.*?\)/g, "").replace(/\s*&\s*/g, " và ").trim();
          const cleanQ = normQ.replace(/\s*\(.*?\)/g, "").replace(/\s*&\s*/g, " và ").trim();
          if (!cleanRule || cleanRule !== cleanQ) {
            return false;
          }
        }
      }
      return true;
    });
  }

  function addGroupRule(part: WorkspacePart) {
    const newRule: AutoQuestionGroupRule = {
      id: crypto.randomUUID(),
      difficulty: "ALL",
      category: "ALL",
      count: 1,
    };
    const updated = {
      ...autoRules,
      [part]: [...(autoRules[part] || []), newRule],
    };
    setAutoRules(updated);
    onQuestionSelectionChange({
      ...questionSelection,
      _autoRules: updated,
      _variants: variants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: sharedRules,
      _sharedQuestions: sharedQuestions,
    });
    setHighlightedDuplicateIds(new Set());
  }

  function updateRule(
    part: WorkspacePart,
    ruleId: string,
    patch: Partial<AutoQuestionGroupRule>
  ) {
    const updated = {
      ...autoRules,
      [part]: (autoRules[part] || []).map((r) =>
        r.id === ruleId ? { ...r, ...patch } : r
      ),
    };
    setAutoRules(updated);
    onQuestionSelectionChange({
      ...questionSelection,
      _autoRules: updated,
      _variants: variants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: sharedRules,
      _sharedQuestions: sharedQuestions,
    });
    setHighlightedDuplicateIds(new Set());
  }

  function deleteRule(part: WorkspacePart, ruleId: string) {
    const updated = {
      ...autoRules,
      [part]: (autoRules[part] || []).filter((r) => r.id !== ruleId),
    };
    setAutoRules(updated);
    onQuestionSelectionChange({
      ...questionSelection,
      _autoRules: updated,
      _variants: variants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: sharedRules,
      _sharedQuestions: sharedQuestions,
    });
    setHighlightedDuplicateIds(new Set());
  }

  function handleValidateAllRules() {
    const { hasDuplicates, allDuplicateIds } = getDuplicatesAcrossParts();
    if (hasDuplicates) {
      setHighlightedDuplicateIds(allDuplicateIds);
      toast.show({
        variant: "error",
        title: "Trùng thuộc tính nhóm câu hỏi",
        description: "Có các nhóm câu hỏi có cùng Độ khó và Loại đề (được bôi đỏ). Vui lòng điều chỉnh hoặc gộp số lượng!",
      });
      return false;
    }
    setHighlightedDuplicateIds(new Set());

    const partsWithRules = WORKSPACE_PARTS.filter((p) => (autoRules[p] || []).length > 0);
    if (!partsWithRules.length) {
      toast.show({
        variant: "info",
        title: "Chưa có nhóm câu hỏi",
        description: tr("Please add at least one question group before generating."),
      });
      return false;
    }

    for (const part of partsWithRules) {
      const rules = autoRules[part] || [];
      const excluded = new Set(sharedQuestions[part] || []);
      for (let i = 0; i < rules.length; i++) {
        const r = rules[i];
        const matches = getMatchingQuestions(part, r, excluded);
        if (matches.length < r.count) {
          const diffText = r.difficulty !== "ALL" ? r.difficulty : "Tất cả độ khó";
          const catText = r.category !== "ALL" ? r.category : "Tất cả danh mục";
          toast.show({
            variant: "error",
            title: "Không đủ câu hỏi",
            description: `${tr(WORKSPACE_PART_LABELS[part])} - Nhóm ${i + 1} (${diffText} · ${catText}): Cần ${r.count} câu nhưng ngân hàng chỉ có ${matches.length} câu phù hợp (không tính câu chung).`,
          });
          return false;
        }
      }
    }

    toast.show({
      variant: "success",
      title: "Cấu hình hợp lệ",
      description: "Ngân hàng câu hỏi đáp ứng đủ số lượng yêu cầu.",
    });
    return true;
  }

  function handleGenerateVariants() {
    const { hasDuplicates, allDuplicateIds } = getDuplicatesAcrossParts();
    if (hasDuplicates) {
      setHighlightedDuplicateIds(allDuplicateIds);
      toast.show({
        variant: "error",
        title: "Trùng thuộc tính nhóm câu hỏi",
        description: "Có các nhóm câu hỏi có cùng Độ khó và Loại đề (được bôi đỏ). Vui lòng điều chỉnh hoặc gộp số lượng!",
      });
      return;
    }
    setHighlightedDuplicateIds(new Set());

    const partsWithRules = WORKSPACE_PARTS.filter((p) => (autoRules[p] || []).length > 0);
    if (!partsWithRules.length) {
      toast.show({
        variant: "info",
        title: "Chưa có nhóm câu hỏi",
        description: tr("Please add at least one question group before generating."),
      });
      return;
    }

    const ruleSpecificity = (rule: AutoQuestionGroupRule) =>
      (rule.difficulty !== "ALL" ? 0 : 1) + (rule.category !== "ALL" ? 0 : 1);

    for (const part of partsWithRules) {
      const rules = (autoRules[part] || [])
        .map((rule, index) => ({ rule, index }))
        .sort((a, b) => ruleSpecificity(a.rule) - ruleSpecificity(b.rule));
      const simulatedUsed = new Set(sharedQuestions[part] || []);
      for (const { rule: r, index: i } of rules) {
        const matches = getMatchingQuestions(part, r, simulatedUsed);
        if (matches.length < r.count) {
          const diffText = r.difficulty !== "ALL" ? r.difficulty : "Tất cả độ khó";
          const catText = r.category !== "ALL" ? r.category : "Tất cả danh mục";
          toast.show({
            variant: "error",
            title: "Không đủ câu hỏi",
            description: `${tr(WORKSPACE_PART_LABELS[part])} - Nhóm ${i + 1} (${diffText} · ${catText}): Cần ${r.count} câu nhưng ngân hàng chỉ còn ${matches.length} câu phù hợp (không tính câu chung và câu đã chọn ở nhóm khác).`,
          });
          return;
        }
        matches.slice(0, r.count).forEach((q) => simulatedUsed.add(q.id));
      }
    }

    const count = Math.max(1, Math.min(20, variantCount || 1));
    const generatedVariants: ExamVariant[] = [];
    const prefix = variantPrefix.trim();

    for (let i = 1; i <= count; i++) {
      const code = prefix ? `${prefix}${i}` : `${i}`;
      const vQuestions: Partial<Record<WorkspacePart, string[]>> = {};

      for (const part of WORKSPACE_PARTS) {
        const rules = [...(autoRules[part] || [])].sort(
          (a, b) => ruleSpecificity(a) - ruleSpecificity(b),
        );
        const baseShared = (sharedRules[part] || []).map((r) => r.questionId).filter(Boolean);
        const pickedIds = new Set<string>();

        for (const rule of rules) {
          const excludeSet = new Set([...pickedIds, ...baseShared]);
          const matches = getMatchingQuestions(part, rule, excludeSet);
          const shuffled = [...matches].sort(() => Math.random() - 0.5);
          const toPick = shuffled.slice(0, rule.count);
          toPick.forEach((q) => pickedIds.add(q.id));
        }

        vQuestions[part] = [...baseShared, ...Array.from(pickedIds)];
      }

      generatedVariants.push({
        code,
        questions: vQuestions,
      });
    }

    setVariants(generatedVariants);
    setConflictSharedIds(new Set());
    if (generatedVariants.length > 0) {
      setActiveVariantCode(generatedVariants[0].code);
    }

    const firstV = generatedVariants[0];
    const newSelection: QuestionSelection = {
      ...questionSelection,
      ...firstV.questions,
      _autoRules: autoRules,
      _variants: generatedVariants,
      _variantCount: count,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: sharedRules,
      _sharedQuestions: sharedQuestions,
    };
    const activeParts = WORKSPACE_PARTS.filter(
      (p) => (firstV.questions[p] || []).length > 0
    );
    const nextParts = Array.from(new Set([...parts, ...activeParts]));

    if (onBatchChange) {
      onBatchChange({
        questionSelection: newSelection,
        workspaceParts: nextParts,
      });
    } else {
      onQuestionSelectionChange(newSelection);
      if (activeParts.length > 0 && (nextParts.length !== parts.length || nextParts.some((p, i) => p !== parts[i]))) {
        onPartsChange(nextParts);
      }
    }

    const totalQCount = WORKSPACE_PARTS.reduce(
      (sum, p) => sum + (firstV.questions[p]?.length || 0),
      0
    );
    toast.show({
      variant: "success",
      title: "Sinh mã đề thành công",
      description: `Đã tự động sinh thành công ${count} mã đề (mỗi mã gồm ${totalQCount} câu hỏi)!`,
    });
  }

  function addSharedRule(part: WorkspacePart) {
    const newRule: SharedQuestionRule = {
      id: crypto.randomUUID(),
      difficulty: "ALL",
      category: "ALL",
      questionId: "",
    };
    const updated = {
      ...sharedRules,
      [part]: [...(sharedRules[part] || []), newRule],
    };
    setSharedRules(updated);
    const updatedShared: Record<WorkspacePart, string[]> = {
      ...sharedQuestions,
      [part]: updated[part].map((r) => r.questionId).filter(Boolean),
    };
    onQuestionSelectionChange({
      ...questionSelection,
      _autoRules: autoRules,
      _variants: variants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: updated,
      _sharedQuestions: updatedShared,
    });
  }

  function updateSharedRule(
    part: WorkspacePart,
    ruleId: string,
    patch: Partial<SharedQuestionRule>
  ) {
    const updated = {
      ...sharedRules,
      [part]: (sharedRules[part] || []).map((r) =>
        r.id === ruleId ? { ...r, ...patch } : r
      ),
    };
    setSharedRules(updated);
    const updatedShared: Record<WorkspacePart, string[]> = {
      ...sharedQuestions,
      [part]: updated[part].map((r) => r.questionId).filter(Boolean),
    };
    onQuestionSelectionChange({
      ...questionSelection,
      _autoRules: autoRules,
      _variants: variants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: updated,
      _sharedQuestions: updatedShared,
    });
  }

  function deleteSharedRule(part: WorkspacePart, ruleId: string) {
    const targetRule = (sharedRules[part] || []).find((r) => r.id === ruleId);
    const removedQId = targetRule?.questionId;

    const updated = {
      ...sharedRules,
      [part]: (sharedRules[part] || []).filter((r) => r.id !== ruleId),
    };
    setSharedRules(updated);

    if (removedQId) {
      setConflictSharedIds((prev) => {
        const next = new Set(prev);
        next.delete(removedQId);
        return next;
      });
    }

    const updatedShared: Record<WorkspacePart, string[]> = {
      ...sharedQuestions,
      [part]: updated[part].map((r) => r.questionId).filter(Boolean),
    };

    const updatedVariants = variants.map((v) => {
      if (!removedQId) return v;
      const vQ = v.questions[part] || [];
      return {
        ...v,
        questions: {
          ...v.questions,
          [part]: vQ.filter((id) => id !== removedQId),
        },
      };
    });
    setVariants(updatedVariants);

    const firstV = updatedVariants[0];
    onQuestionSelectionChange({
      ...questionSelection,
      ...(firstV ? firstV.questions : {}),
      _autoRules: autoRules,
      _variants: updatedVariants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: updated,
      _sharedQuestions: updatedShared,
    });
  }

  function handleSelectSharedQuestion(part: WorkspacePart, ruleId: string, questionId: string) {
    const targetRule = (sharedRules[part] || []).find((r) => r.id === ruleId);
    const prevQId = targetRule?.questionId;

    if (prevQId && prevQId !== questionId) {
      setConflictSharedIds((prev) => {
        const next = new Set(prev);
        next.delete(prevQId);
        return next;
      });
    }

    if (!questionId) {
      updateSharedRule(part, ruleId, { questionId: "" });
      return;
    }

    const alreadyInOtherRule = (sharedRules[part] || []).some(
      (r) => r.id !== ruleId && r.questionId === questionId
    );
    if (alreadyInOtherRule) {
      toast.show({
        variant: "error",
        title: "Trùng câu chung",
        description: "Câu hỏi này đã được chọn trong cấu hình câu chung khác!",
      });
      setConflictSharedIds((prev) => new Set([...prev, questionId]));
    } else {
      const alreadyInVariants = variants.some((v) => {
        const qList = v.questions[part] || [];
        const priorShared = (sharedRules[part] || []).map((r) => r.questionId).filter(Boolean);
        return qList.includes(questionId) && !priorShared.includes(questionId);
      });

      if (alreadyInVariants) {
        toast.show({
          variant: "error",
          title: "Trùng câu hỏi với mã đề đã sinh",
          description: "Câu hỏi này đã có trong một hoặc nhiều mã đề! Đã đánh dấu viền đỏ câu bị trùng.",
        });
        setConflictSharedIds((prev) => new Set([...prev, questionId]));
      } else {
        setConflictSharedIds((prev) => {
          const next = new Set(prev);
          next.delete(questionId);
          return next;
        });
      }
    }

    const updated = {
      ...sharedRules,
      [part]: (sharedRules[part] || []).map((r) =>
        r.id === ruleId ? { ...r, questionId } : r
      ),
    };
    setSharedRules(updated);

    const updatedShared: Record<WorkspacePart, string[]> = {
      ...sharedQuestions,
      [part]: updated[part].map((r) => r.questionId).filter(Boolean),
    };

    const updatedVariants = variants.map((v) => {
      const vQ = v.questions[part] || [];
      let nextVQ: string[];
      if (prevQId && vQ.includes(prevQId)) {
        nextVQ = vQ.map((id) => (id === prevQId ? questionId : id));
      } else if (!vQ.includes(questionId)) {
        nextVQ = [questionId, ...vQ];
      } else {
        nextVQ = vQ;
      }
      return {
        ...v,
        questions: {
          ...v.questions,
          [part]: nextVQ,
        },
      };
    });
    setVariants(updatedVariants);

    const firstV = updatedVariants[0];
    onQuestionSelectionChange({
      ...questionSelection,
      ...(firstV ? firstV.questions : {}),
      _autoRules: autoRules,
      _variants: updatedVariants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: updated,
      _sharedQuestions: updatedShared,
    });
  }

  function handleSwitchVariantQuestion(
    part: WorkspacePart,
    oldQuestionId: string,
    newQuestionId: string
  ) {
    if (!activeVariant || oldQuestionId === newQuestionId) return;

    const currentVariantQuestions = activeVariant.questions[part] || [];
    const updatedQuestions = currentVariantQuestions.map((id) =>
      id === oldQuestionId ? newQuestionId : id
    );

    const priorShared = (sharedRules[part] || []).map((r) => r.questionId).filter(Boolean);
    const countInVariant = updatedQuestions.filter((id) => id === newQuestionId).length;
    const isDuplicated = countInVariant > 1 || priorShared.includes(newQuestionId);

    if (isDuplicated) {
      toast.show({
        variant: "error",
        title: "Trùng câu hỏi",
        description: "Câu hỏi này đã có trong mã đề hoặc danh sách câu chung!",
      });
      setConflictSharedIds((prev) => new Set([...prev, newQuestionId]));
    } else {
      setConflictSharedIds((prev) => {
        const next = new Set(prev);
        next.delete(oldQuestionId);
        next.delete(newQuestionId);
        return next;
      });
    }

    const updatedVariants = variants.map((v) => {
      if (v.code === activeVariant.code) {
        return {
          ...v,
          questions: {
            ...v.questions,
            [part]: updatedQuestions,
          },
        };
      }
      return v;
    });

    setVariants(updatedVariants);

    const firstV = updatedVariants[0];
    onQuestionSelectionChange({
      ...questionSelection,
      ...(firstV ? firstV.questions : {}),
      _autoRules: autoRules,
      _variants: updatedVariants,
      _variantCount: variantCount,
      _variantPrefix: variantPrefix,
      _variantSuffix: variantPrefix,
      _sharedRules: sharedRules,
      _sharedQuestions: sharedQuestions,
    });
  }

  function handleSwitchVariantCategory(
    part: WorkspacePart,
    oldQuestionId: string,
    newCategory: string
  ) {
    if (!activeVariant) return;

    const candidates = (catalog[part] || []).filter(
      (item) => item.category?.trim().toLowerCase() === newCategory.trim().toLowerCase()
    );
    const currentVariantQuestions = activeVariant.questions[part] || [];
    const pick = candidates.find((c) => !currentVariantQuestions.includes(c.id)) || candidates[0];

    if (pick) {
      handleSwitchVariantQuestion(part, oldQuestionId, pick.id);
    }
  }

  function handleSwitchSharedQuestion(
    part: WorkspacePart,
    oldQuestionId: string,
    newQuestionId: string
  ) {
    const rule = (sharedRules[part] || []).find((r) => r.questionId === oldQuestionId);
    if (rule) {
      handleSelectSharedQuestion(part, rule.id, newQuestionId);
    } else {
      const currentList = sharedQuestions[part] || [];
      const updatedList = currentList.map((id) => (id === oldQuestionId ? newQuestionId : id));
      const updatedShared = { ...sharedQuestions, [part]: updatedList };
      const updatedVariants = variants.map((v) => {
        const vQ = v.questions[part] || [];
        return {
          ...v,
          questions: {
            ...v.questions,
            [part]: vQ.map((id) => (id === oldQuestionId ? newQuestionId : id)),
          },
        };
      });
      setVariants(updatedVariants);
      onQuestionSelectionChange({
        ...questionSelection,
        _variants: updatedVariants,
        _sharedQuestions: updatedShared,
      });
    }
  }

  function handleSwitchSharedCategory(
    part: WorkspacePart,
    oldQuestionId: string,
    newCategory: string
  ) {
    const rule = (sharedRules[part] || []).find((r) => r.questionId === oldQuestionId);
    if (rule) {
      const candidates = (catalog[part] || []).filter(
        (item) => item.category?.trim().toLowerCase() === newCategory.trim().toLowerCase()
      );
      const pick = candidates[0];
      if (pick) {
        handleSelectSharedQuestion(part, rule.id, pick.id);
        updateSharedRule(part, rule.id, { category: newCategory, questionId: pick.id });
      } else {
        updateSharedRule(part, rule.id, { category: newCategory, questionId: "" });
      }
    }
  }

  const swapTargetQuestion = useMemo(() => {
    if (!swapModalTarget) return null;
    return (
      (catalog[swapModalTarget.part] || []).find(
        (q) => q.id === swapModalTarget.questionId
      ) || null
    );
  }, [swapModalTarget, catalog]);

  const candidateQuestions = useMemo(() => {
    if (!swapModalTarget) return [];
    const part = swapModalTarget.part;
    const list = catalog[part] || [];

    return list.filter((q) => {
      if (swapCategoryFilter && swapCategoryFilter !== "ALL") {
        if (
          !q.category ||
          q.category.trim().toLowerCase() !== swapCategoryFilter.trim().toLowerCase()
        ) {
          return false;
        }
      }
      if (swapDifficultyFilter && swapDifficultyFilter !== "ALL") {
        if (!q.difficulty) return false;
        const normalizedRule = swapDifficultyFilter.trim().toLowerCase();
        const normalizedQ = q.difficulty.trim().toLowerCase();
        const diffMap: Record<string, string[]> = {
          dễ: ["dễ", "easy"],
          easy: ["dễ", "easy"],
          "trung bình": ["trung bình", "medium"],
          medium: ["trung bình", "medium"],
          khó: ["khó", "hard"],
          hard: ["khó", "hard"],
        };
        const allowed = diffMap[normalizedRule] || [normalizedRule];
        if (!allowed.includes(normalizedQ)) return false;
      }
      if (swapSearchQuery.trim()) {
        const qText = `${q.name} ${q.description || ""}`.toLowerCase();
        if (!qText.includes(swapSearchQuery.trim().toLowerCase())) {
          return false;
        }
      }
      return true;
    });
  }, [
    swapModalTarget,
    catalog,
    swapCategoryFilter,
    swapDifficultyFilter,
    swapSearchQuery,
  ]);

  function handleApplyQuestionSwap(newQId: string) {
    if (!swapModalTarget || !newQId) return;
    const { part, questionId: oldQId, isShared } = swapModalTarget;

    if (isShared) {
      handleSwitchSharedQuestion(part, oldQId, newQId);
    } else {
      handleSwitchVariantQuestion(part, oldQId, newQId);
    }
    setSwapModalTarget(null);
  }

  const currentRules = autoRules[activeTab] || [];
  const currentPartLabel = tr(WORKSPACE_PART_LABELS[activeTab]);
  const currentSharedRulesForTab = sharedRules[activeTab] || [];

  return (
    <div className="builder-inline-settings">
      {!hideTrigger && (
        <div className="builder-inline-settings-header-row">
          <button
            type="button"
            className={`button teacher-small-button builder-problem-settings-button${open ? " is-active" : ""}`}
            aria-label={tr("Settings for {value0}", { value0: title })}
            aria-expanded={open}
            aria-controls={contentId}
            onClick={onToggle}
          >
            <Settings2 size={13} aria-hidden="true" />
            <TranslatedText>{tr("Problem settings")}</TranslatedText>
            <ChevronDown size={13} className={open ? "is-expanded" : ""} aria-hidden="true" />
          </button>
        </div>
      )}

      <div className="builder-settings-inline-disclosure">
        <SlidingDisclosure open={open}>
          <section
            id={contentId}
            className="builder-settings-inline-content"
            aria-label={tr("Problem settings")}
          >
            {error ? (
              <div role="alert" className="builder-settings-inline-error">
                <p>{error}</p>
                {onRetry && (
                  <button type="button" className="button" onClick={onRetry}>
                    <TranslatedText>{tr("Retry")}</TranslatedText>
                  </button>
                )}
              </div>
            ) : !detail ? (
              <p className="muted builder-settings-inline-loading">
                <TranslatedText>{tr("Loading problem details…")}</TranslatedText>
              </p>
            ) : selectionMode === "manual" ? (
              <>
                <BuilderDatabaseReference
                  schema={detail.schema}
                  datasets={detail.testCases?.length ? detail.testCases : detail.seedData ? [{ seedData: detail.seedData, isHidden: false }] : []}
                  visibility={visibility}
                  onVisibilityChange={onVisibilityChange}
                  datasetVisibility={datasetVisibility}
                  onDatasetVisibilityChange={onDatasetVisibilityChange}
                  readOnly={locked}
                />
                <BuilderManualQuestionPicker
                  detail={detail}
                  parts={parts}
                  questionSelection={questionSelection}
                  lockedQuestionSelection={lockedQuestionSelection}
                  lockedParts={lockedParts}
                  onChange={(patch) => {
                    if (onBatchChange) {
                      onBatchChange(patch);
                    } else {
                      onQuestionSelectionChange(patch.questionSelection);
                      onPartsChange(patch.workspaceParts);
                    }
                  }}
                />
              </>
            ) : (
              <>
                {/* Header Toolbar: 4 Tabs on left, Sinh đề tự động badge on right */}
                <div className="builder-settings-header-toolbar">
                  <div
                    className="builder-selected-tabs builder-settings-inline-tabs"
                    role="tablist"
                    aria-label={tr("Problem settings tabs")}
                  >
                    {WORKSPACE_PARTS.map((partKey) => {
                      const count = (autoRules[partKey] || []).reduce(
                        (sum, r) => sum + r.count,
                        0
                      ) + (sharedQuestions[partKey]?.length || 0);
                      return (
                        <button
                          type="button"
                          role="tab"
                          key={partKey}
                          id={`builder-inline-tab-${partKey}-${problemId}`}
                          aria-selected={activeTab === partKey}
                          aria-controls={`builder-inline-panel-${partKey}-${problemId}`}
                          onClick={() => {
                            setActiveTab(partKey);
                            setPreviewPart(partKey);
                          }}
                        >
                          <TranslatedText>{WORKSPACE_PART_LABELS[partKey]}</TranslatedText>
                          <span>{count}</span>
                        </button>
                      );
                    })}
                  </div>

                  <div className="builder-auto-gen-badge">
                    <Sparkles size={13} aria-hidden="true" />
                    <span><TranslatedText>Sinh đề tự động</TranslatedText></span>
                  </div>
                </div>

                {/* Auto Gen Rule Configuration Form */}
                <GlidingTooltipGroup
                  className="builder-auto-gen-container"
                  label={tr("Question group rules")}
                  role="region"
                  multiline
                  side="above"
                >
                  <div className="builder-auto-gen-header">
                    <div className="builder-auto-gen-title">
                      <Sparkles size={14} aria-hidden="true" />
                      <h4>
                        <TranslatedText>Nhóm câu hỏi</TranslatedText> · <span>{currentPartLabel}</span>
                      </h4>
                    </div>
                    <button
                      type="button"
                      className="button subtle teacher-small-button"
                      onClick={() => addGroupRule(activeTab)}
                    >
                      <Plus size={13} aria-hidden="true" />
                      <span><TranslatedText>Thêm nhóm</TranslatedText></span>
                    </button>
                  </div>

                  <div className="builder-auto-gen-rules-list">
                    {currentRules.map((rule) => {
                      const isDup = highlightedDuplicateIds.has(rule.id);
                      const excludedIds = new Set(sharedQuestions[activeTab] || []);
                      const availableCount = getMatchingQuestions(
                        activeTab,
                        rule,
                        excludedIds
                      ).length;
                      const isInsufficient = availableCount < rule.count;
                      const hasSelectedCategory = Boolean(rule.category && rule.category !== "ALL");
                      const hasSelectedDifficulty = Boolean(rule.difficulty && rule.difficulty !== "ALL");

                      const duplicateErrorMsg = isDup
                        ? `Trùng lặp nhóm câu hỏi: Đã có nhóm khác trong phần ${currentPartLabel} có cùng Loại đề '${rule.category === "ALL" ? tr("All categories") : rule.category}' và Độ khó '${rule.difficulty === "ALL" ? tr("All difficulties") : rule.difficulty}'. Vui lòng gộp số câu hoặc đổi tiêu chí.`
                        : undefined;

                      const insufficientErrorMsg = isInsufficient
                        ? `Không đủ câu hỏi: Ngân hàng chỉ có ${availableCount} câu phù hợp với tiêu chí đã chọn, không đủ để sinh ${rule.count} câu cho mỗi mã đề. Vui lòng bổ sung câu hỏi hoặc giảm số câu cần sinh.`
                        : undefined;

                      return (
                        <div
                          className="builder-auto-gen-rule-card"
                          key={rule.id}
                          data-tooltip={duplicateErrorMsg}
                          data-tooltip-type={isDup ? "error" : undefined}
                          data-tooltip-side="above"
                          title={duplicateErrorMsg}
                        >
                          <div
                            className={`builder-auto-gen-field builder-auto-gen-field-category${isDup ? " is-duplicate" : ""}`}
                            data-tooltip={duplicateErrorMsg}
                            data-tooltip-type={isDup ? "error" : undefined}
                            data-tooltip-side="above"
                            title={duplicateErrorMsg}
                          >
                            <label><TranslatedText>{tr("Category")}</TranslatedText></label>
                            <Select
                              value={rule.category}
                              onChange={(e) =>
                                updateRule(activeTab, rule.id, { category: e.target.value })
                              }
                            >
                              <option value="ALL">
                                {hasSelectedDifficulty
                                  ? `${tr("All categories")} (${getMatchingQuestions(activeTab, { difficulty: rule.difficulty, category: "ALL" }, excludedIds).length})`
                                  : tr("All categories")}
                              </option>
                              {partCategories.map((c) => {
                                const count = hasSelectedDifficulty
                                  ? getMatchingQuestions(activeTab, { difficulty: rule.difficulty, category: c.name }, excludedIds).length
                                  : null;
                                return (
                                  <option key={c.id} value={c.name}>
                                    {count !== null ? `${c.name} (${count})` : c.name}
                                  </option>
                                );
                              })}
                            </Select>
                          </div>

                          <div
                            className={`builder-auto-gen-field builder-auto-gen-field-difficulty${isDup ? " is-duplicate" : ""}`}
                            data-tooltip={duplicateErrorMsg}
                            data-tooltip-type={isDup ? "error" : undefined}
                            data-tooltip-side="above"
                            title={duplicateErrorMsg}
                          >
                            <label><TranslatedText>{tr("Difficulty")}</TranslatedText></label>
                            <Select
                              value={rule.difficulty}
                              onChange={(e) =>
                                updateRule(activeTab, rule.id, { difficulty: e.target.value })
                              }
                            >
                              <option value="ALL">
                                {hasSelectedCategory
                                  ? `${tr("All difficulties")} (${getMatchingQuestions(activeTab, { category: rule.category, difficulty: "ALL" }, excludedIds).length})`
                                  : tr("All difficulties")}
                              </option>
                              {difficulties.map((d) => {
                                const count = hasSelectedCategory
                                  ? getMatchingQuestions(activeTab, { category: rule.category, difficulty: d.name }, excludedIds).length
                                  : null;
                                return (
                                  <option key={d.id} value={d.name}>
                                    {count !== null ? `${d.name} (${count})` : d.name}
                                  </option>
                                );
                              })}
                            </Select>
                          </div>

                          <div
                            className={`builder-auto-gen-field builder-auto-gen-field-available${isInsufficient ? " is-insufficient" : ""}`}
                            data-tooltip={insufficientErrorMsg || `Ngân hàng có ${availableCount} câu phù hợp`}
                            data-tooltip-type={isInsufficient ? "error" : undefined}
                            data-tooltip-side="above"
                            title={insufficientErrorMsg || `Ngân hàng có ${availableCount} câu phù hợp`}
                          >
                            <label><TranslatedText>Số câu sẵn có</TranslatedText></label>
                            <input
                              type="text"
                              readOnly
                              value={availableCount}
                              className="builder-auto-gen-available-input"
                              tabIndex={-1}
                              data-tooltip={insufficientErrorMsg || `Ngân hàng có ${availableCount} câu phù hợp`}
                              data-tooltip-type={isInsufficient ? "error" : undefined}
                              data-tooltip-side="above"
                              title={insufficientErrorMsg || `Ngân hàng có ${availableCount} câu phù hợp`}
                            />
                          </div>

                          <div className="builder-auto-gen-field builder-auto-gen-field-count">
                            <label><TranslatedText>{tr("Questions count")}</TranslatedText></label>
                            <input
                              type="number"
                              min={1}
                              value={rule.count}
                              onChange={(e) =>
                                updateRule(activeTab, rule.id, {
                                  count: Math.max(1, parseInt(e.target.value, 10) || 1),
                                })
                              }
                            />
                          </div>

                          <div className="builder-auto-gen-rule-actions">
                            <button
                              type="button"
                              className="icon-button"
                              title={tr("Delete group")}
                              aria-label={tr("Delete group")}
                              style={{ color: "var(--danger)" }}
                              onClick={() => deleteRule(activeTab, rule.id)}
                            >
                              <Trash2 size={13} aria-hidden="true" />
                            </button>
                          </div>
                        </div>
                      );
                    })}

                    {currentRules.length === 0 && (
                      <div className="builder-auto-gen-empty">
                        <p>
                          <TranslatedText>
                            Chưa có nhóm câu hỏi nào cho phần này. Hãy bấm 'Thêm nhóm' để thiết lập quy tắc sinh đề.
                          </TranslatedText>
                        </p>
                      </div>
                    )}
                  </div>

                  {/* KHỐI CÂU CHUNG NẰM NGAY TRÊN CHỮ SINH MÃ ĐỀ */}
                  <div className="builder-shared-section-inline">
                    <div className="builder-auto-gen-header">
                      <div className="builder-auto-gen-title">
                        <CopyCheck size={14} aria-hidden="true" />
                        <h4>
                          <TranslatedText>Khởi tạo câu chung</TranslatedText> · <span>{currentPartLabel}</span>
                          {currentSharedRulesForTab.length > 0 && (
                            <span className="builder-shared-count-badge">
                              {currentSharedRulesForTab.length} câu
                            </span>
                          )}
                        </h4>
                      </div>
                      <button
                        type="button"
                        className="button subtle teacher-small-button"
                        onClick={() => addSharedRule(activeTab)}
                      >
                        <Plus size={13} aria-hidden="true" />
                        <span><TranslatedText>Thêm câu chung</TranslatedText></span>
                      </button>
                    </div>

                    {currentSharedRulesForTab.length > 0 && (
                      <div className="builder-shared-rules-list">
                        {currentSharedRulesForTab.map((rule) => {
                          const matchingOptions = getMatchingQuestions(activeTab, rule);
                          const isDup = !!rule.questionId && duplicateQuestionIds.has(rule.questionId);
                          const hasSelectedCategory = Boolean(rule.category && rule.category !== "ALL");
                          const hasSelectedDifficulty = Boolean(rule.difficulty && rule.difficulty !== "ALL");
                          const sharedDupMsg = isDup
                            ? "Trùng lặp câu hỏi chung: Câu hỏi này đã được chọn làm câu chung ở một dòng khác. Mỗi câu chỉ được đưa vào câu chung một lần."
                            : undefined;

                          return (
                            <div
                              key={rule.id}
                              className={`builder-auto-gen-rule-card builder-shared-row-card${isDup ? " is-duplicate-question" : ""}`}
                              data-tooltip={sharedDupMsg}
                              data-tooltip-type={isDup ? "error" : undefined}
                              data-tooltip-side="above"
                              title={sharedDupMsg}
                            >
                              <div className="builder-auto-gen-field builder-shared-field-cat">
                                <label><TranslatedText>{tr("Category")}</TranslatedText></label>
                                <Select
                                  value={rule.category}
                                  onChange={(e) =>
                                    updateSharedRule(activeTab, rule.id, {
                                      category: e.target.value,
                                      questionId: "",
                                    })
                                  }
                                >
                                  <option value="ALL">
                                    {hasSelectedDifficulty
                                      ? `${tr("All categories")} (${getMatchingQuestions(activeTab, { difficulty: rule.difficulty, category: "ALL" }).length})`
                                      : tr("All categories")}
                                  </option>
                                  {partCategories.map((c) => {
                                    const count = hasSelectedDifficulty
                                      ? getMatchingQuestions(activeTab, { difficulty: rule.difficulty, category: c.name }).length
                                      : null;
                                    return (
                                      <option key={c.id} value={c.name}>
                                        {count !== null ? `${c.name} (${count})` : c.name}
                                      </option>
                                    );
                                  })}
                                </Select>
                              </div>

                              <div className="builder-auto-gen-field builder-shared-field-diff">
                                <label><TranslatedText>{tr("Difficulty")}</TranslatedText></label>
                                <Select
                                  value={rule.difficulty}
                                  onChange={(e) =>
                                    updateSharedRule(activeTab, rule.id, {
                                      difficulty: e.target.value,
                                      questionId: "",
                                    })
                                  }
                                >
                                  <option value="ALL">
                                    {hasSelectedCategory
                                      ? `${tr("All difficulties")} (${getMatchingQuestions(activeTab, { category: rule.category, difficulty: "ALL" }).length})`
                                      : tr("All difficulties")}
                                  </option>
                                  {difficulties.map((d) => {
                                    const count = hasSelectedCategory
                                      ? getMatchingQuestions(activeTab, { category: rule.category, difficulty: d.name }).length
                                      : null;
                                    return (
                                      <option key={d.id} value={d.name}>
                                        {count !== null ? `${d.name} (${count})` : d.name}
                                      </option>
                                    );
                                  })}
                                </Select>
                              </div>

                              <div
                                className="builder-auto-gen-field builder-shared-field-pick"
                                data-tooltip={sharedDupMsg}
                                data-tooltip-type={isDup ? "error" : undefined}
                                data-tooltip-side="above"
                                title={sharedDupMsg}
                              >
                                <label><TranslatedText>Chọn câu hỏi thủ công</TranslatedText></label>
                                <Select
                                  value={rule.questionId}
                                  className={isDup ? "is-duplicate-question" : ""}
                                  onChange={(e) =>
                                    handleSelectSharedQuestion(activeTab, rule.id, e.target.value)
                                  }
                                >
                                  <option value="">
                                    {matchingOptions.length === 0
                                      ? "-- Không có câu hỏi nào phù hợp --"
                                      : `-- Chọn câu hỏi (${matchingOptions.length} câu) --`}
                                  </option>
                                  {matchingOptions.map((q, qIdx) => (
                                    <option key={q.id} value={q.id}>
                                      {String(qIdx + 1).padStart(2, "0")}. {q.name}
                                      {q.description
                                        ? ` - ${q.description.replace(/[\r\n]+/g, " ").slice(0, 50)}`
                                        : ""}
                                    </option>
                                  ))}
                                </Select>
                              </div>

                              <div className="builder-auto-gen-rule-actions">
                                <button
                                  type="button"
                                  className="icon-button"
                                  title="Xóa câu chung"
                                  aria-label="Xóa câu chung"
                                  style={{ color: "var(--danger)" }}
                                  onClick={() => deleteSharedRule(activeTab, rule.id)}
                                >
                                  <Trash2 size={13} aria-hidden="true" />
                                </button>
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>



                  {/* Actions Footer */}
                  <div className="builder-auto-gen-footer">
                    <div className="builder-auto-gen-footer-left">
                      <button
                        type="button"
                        className="button teacher-small-button builder-auto-gen-check-btn"
                        onClick={handleValidateAllRules}
                      >
                        <CheckCircle2 size={13} aria-hidden="true" />
                        <span><TranslatedText>{tr("Check configuration")}</TranslatedText></span>
                      </button>
                    </div>

                    <div className="builder-auto-gen-footer-right">
                      <div className="builder-variant-inputs">
                        <label className="builder-variant-input-group">
                          <span><TranslatedText>Số mã đề</TranslatedText></span>
                          <input
                            type="number"
                            min={1}
                            max={20}
                            className="builder-variant-count-input"
                            value={variantCount}
                            onChange={(e) => {
                              const nextCount = Math.max(1, Math.min(20, parseInt(e.target.value, 10) || 1));
                              setVariantCount(nextCount);
                              onQuestionSelectionChange({
                                ...questionSelection,
                                _variantCount: nextCount,
                                _variantPrefix: variantPrefix,
                                _variantSuffix: variantPrefix,
                                _variants: variants,
                              });
                            }}
                          />
                        </label>
                        <label className="builder-variant-input-group">
                          <span><TranslatedText>Tiền tố</TranslatedText></span>
                          <input
                            type="text"
                            placeholder="VD: DE_, IT_"
                            className="builder-variant-suffix-input"
                            value={variantPrefix}
                            onChange={(e) => {
                              const nextPrefix = e.target.value;
                              setVariantPrefix(nextPrefix);
                              onQuestionSelectionChange({
                                ...questionSelection,
                                _variantCount: variantCount,
                                _variantPrefix: nextPrefix,
                                _variantSuffix: nextPrefix,
                                _variants: variants,
                              });
                            }}
                          />
                        </label>
                      </div>

                      <button
                        type="button"
                        className="button primary teacher-small-button builder-auto-gen-generate-btn"
                        onClick={handleGenerateVariants}
                      >
                        <Sparkles size={13} aria-hidden="true" />
                        <span><TranslatedText>{tr("Sinh mã đề")}</TranslatedText></span>
                      </button>
                    </div>
                  </div>
                </GlidingTooltipGroup>

                {/* Preview Form ("Form bên dưới thấy trước") */}
                <GlidingTooltipGroup
                  className="builder-preview-form"
                  label={tr("Variant questions preview")}
                  role="region"
                  multiline
                  side="above"
                >
                  <div className="builder-preview-header-bar">
                    {/* Variant Tabs */}
                    {variants.length > 0 ? (
                      <div className="builder-variants-tab-bar" role="tablist" aria-label="Danh sách mã đề">
                        {variants.map((v) => {
                          const qCount = WORKSPACE_PARTS.reduce(
                            (sum, p) => sum + (v.questions[p]?.length || 0),
                            0
                          );
                          return (
                            <button
                              key={v.code}
                              type="button"
                              role="tab"
                              className={`builder-variant-tab${activeVariant?.code === v.code ? " is-active" : ""}`}
                              aria-selected={activeVariant?.code === v.code}
                              onClick={() => setActiveVariantCode(v.code)}
                            >
                              <span>Mã đề {v.code}</span>
                              <span className="builder-variant-qcount">{qCount} câu</span>
                            </button>
                          );
                        })}
                      </div>
                    ) : (
                      <div className="builder-preview-heading-title">
                        <Layers size={14} aria-hidden="true" />
                        <strong>Bản xem trước đề thi</strong>
                      </div>
                    )}
                  </div>

                  {/* 4 Part Tabs in Preview Form */}
                  <div className="builder-preview-tabs-row">
                    <div className="builder-selected-tabs builder-preview-part-tabs" role="tablist">
                      {WORKSPACE_PARTS.map((p) => {
                        const sharedCount = (sharedRules[p] || []).length;
                        const generatedCount = activeVariant
                          ? (activeVariant.questions[p] || []).length
                          : 0;
                        const displayCount = Math.max(sharedCount, generatedCount);
                        return (
                          <button
                            type="button"
                            role="tab"
                            key={p}
                            aria-selected={previewPart === p}
                            onClick={() => setPreviewPart(p)}
                          >
                            <TranslatedText>{WORKSPACE_PART_LABELS[p]}</TranslatedText>
                            <span>{displayCount}</span>
                          </button>
                        );
                      })}
                    </div>
                  </div>

                  {/* Questions Preview for active previewPart: Gom chung tất cả câu hỏi theo nhãn */}
                  {(() => {
                    const currentSharedSet = new Set(
                      (sharedRules[previewPart] || []).map((r) => r.questionId).filter(Boolean)
                    );

                    const currentVariantIds = activeVariant
                      ? activeVariant.questions[previewPart] || []
                      : Array.from(currentSharedSet);

                    // Combine variant questions and shared questions (shared questions are always part of the exam)
                    const allIds = Array.from(new Set([...currentVariantIds, ...Array.from(currentSharedSet)]));
                    const questionMap = new Map((catalog[previewPart] || []).map((q) => [q.id, q]));
                    const fullQuestions = allIds.map((id) => questionMap.get(id)).filter(Boolean) as QuestionOption[];

                    // Sort by Category to group questions with the same label together!
                    const sortedQuestions = [...fullQuestions].sort((a, b) => {
                      const idxA = a.category ? previewCategories.indexOf(a.category) : 999;
                      const idxB = b.category ? previewCategories.indexOf(b.category) : 999;
                      return (idxA === -1 ? 999 : idxA) - (idxB === -1 ? 999 : idxB);
                    });

                    const sharedCountInPart = sortedQuestions.filter((q) => currentSharedSet.has(q.id)).length;

                    return (
                      <div className="builder-variant-detail">
                        <div className="builder-variant-part-header">
                          <div className="builder-frame-header-title">
                            <Layers size={13} aria-hidden="true" />
                            <strong>
                              {activeVariant
                                ? `Đề thi mã ${activeVariant.code} · ${tr(WORKSPACE_PART_LABELS[previewPart])}`
                                : `Câu hỏi phần ${tr(WORKSPACE_PART_LABELS[previewPart])}`}
                            </strong>
                          </div>
                          <span className="builder-variant-part-count">
                            {sortedQuestions.length} câu
                            {sharedCountInPart > 0 ? ` (${sharedCountInPart} câu chung)` : ""}
                          </span>
                        </div>

                        <div className="builder-variant-questions-list">
                          {(() => {
                            const categoryGroups: { category: string; questions: QuestionOption[] }[] = [];
                            const map = new Map<string, QuestionOption[]>();
                            for (const cat of previewCategories) {
                              map.set(cat, []);
                            }
                            for (const q of sortedQuestions) {
                              const cat = q.category?.trim() || "Chưa phân loại";
                              if (!map.has(cat)) {
                                map.set(cat, []);
                              }
                              map.get(cat)!.push(q);
                            }
                            for (const [category, questions] of map.entries()) {
                              if (questions.length > 0) {
                                categoryGroups.push({ category, questions });
                              }
                            }

                            let questionCounter = 0;

                            return categoryGroups.map((group) => {
                              const isCollapsed = collapsedCategories.has(group.category);
                              const sharedCountInGroup = group.questions.filter((q) =>
                                currentSharedSet.has(q.id)
                              ).length;

                              return (
                                <div key={group.category} className="builder-category-panel">
                                  <button
                                    type="button"
                                    className="builder-category-panel-header"
                                    aria-expanded={!isCollapsed}
                                    onClick={() => toggleCategoryCollapse(group.category)}
                                  >
                                    <div className="builder-category-title">
                                      <ChevronDown
                                        size={14}
                                        className={`builder-category-chevron${!isCollapsed ? " is-expanded" : ""}`}
                                        aria-hidden="true"
                                      />
                                      <Tag size={13} aria-hidden="true" />
                                      <span className="builder-category-name">{group.category}</span>
                                    </div>

                                    <div className="builder-category-badges">
                                      <span className="builder-category-count-pill">
                                        {group.questions.length} câu
                                        {sharedCountInGroup > 0 ? ` (${sharedCountInGroup} câu chung)` : ""}
                                      </span>
                                    </div>
                                  </button>

                                  <SlidingDisclosure open={!isCollapsed}>
                                    <div className="builder-category-panel-content">
                                      {group.questions.map((q) => {
                                        questionCounter++;
                                        const currentIdx = questionCounter;
                                        const isShared = currentSharedSet.has(q.id);
                                        const isDup = duplicateQuestionIds.has(q.id);
                                        const variantDupMsg = isDup
                                          ? `Câu hỏi '${q.name}' bị trùng lặp trong mã đề. Vui lòng kiểm tra lại cấu hình nhóm câu hỏi hoặc câu chung.`
                                          : undefined;

                                        return (
                                          <div
                                            key={q.id}
                                            className={`builder-variant-question-card${isShared ? " is-shared-card" : ""}${isDup ? " is-duplicate-question" : ""}`}
                                            data-tooltip={variantDupMsg}
                                            data-tooltip-type={isDup ? "error" : undefined}
                                            data-tooltip-side="above"
                                            title={variantDupMsg}
                                          >
                                            <div className="builder-q-title-row">
                                              <div className="builder-q-title-left">
                                                <span className="builder-variant-question-num">
                                                  {String(currentIdx).padStart(2, "0")}.
                                                </span>
                                                <span className="builder-variant-question-name">{q.name}</span>
                                              </div>

                                              <div className="builder-q-header-badges">
                                                {isShared && (
                                                  <span className="builder-q-tag shared">
                                                    <TranslatedText>Câu chung</TranslatedText>
                                                  </span>
                                                )}
                                                {isDup && (
                                                  <span className="builder-q-duplicate-badge">
                                                    <TranslatedText>Trùng câu hỏi</TranslatedText>
                                                  </span>
                                                )}
                                                {q.difficulty && (
                                                  <span className="builder-q-tag difficulty">{q.difficulty}</span>
                                                )}

                                                <div className="builder-q-menu-container">
                                                  <button
                                                    type="button"
                                                    className={`builder-q-settings-btn${openMenuQuestionId === q.id ? " is-active" : ""}`}
                                                    title="Cài đặt câu hỏi"
                                                    aria-label="Cài đặt câu hỏi"
                                                    onClick={(e) => {
                                                      e.stopPropagation();
                                                      setOpenMenuQuestionId(openMenuQuestionId === q.id ? null : q.id);
                                                    }}
                                                  >
                                                    <Settings size={12} aria-hidden="true" />
                                                  </button>

                                                  {openMenuQuestionId === q.id && (
                                                    <div className="examination-context-menu builder-q-context-menu" ref={contextMenuRef}>
                                                      <button
                                                        type="button"
                                                        className="examination-context-item"
                                                        onClick={(e) => {
                                                          e.stopPropagation();
                                                          setOpenMenuQuestionId(null);
                                                          setSwapModalTarget({
                                                            part: previewPart,
                                                            questionId: q.id,
                                                            isShared,
                                                          });
                                                        }}
                                                      >
                                                        <RefreshCw size={12} aria-hidden="true" />
                                                        <span><TranslatedText>Đổi câu hỏi</TranslatedText></span>
                                                      </button>
                                                    </div>
                                                  )}
                                                </div>
                                              </div>
                                            </div>

                                            {q.description && (
                                              <div className="builder-variant-question-desc">
                                                <ProblemMarkdown>{tr(q.description)}</ProblemMarkdown>
                                              </div>
                                            )}
                                          </div>
                                        );
                                      })}
                                    </div>
                                  </SlidingDisclosure>
                                </div>
                              );
                            });
                          })()}

                          {sortedQuestions.length === 0 && (
                            <p className="muted" style={{ padding: "8px 4px", fontSize: "10.5px" }}>
                              Chưa có câu hỏi nào cho phần này. Hãy thiết lập nhóm câu hỏi và bấm <strong>Sinh mã đề</strong> ở trên.
                            </p>
                          )}
                        </div>
                      </div>
                    );
                  })()}
                </GlidingTooltipGroup>
              </>
            )}
          </section>
        </SlidingDisclosure>
      </div>

      {swapModalTarget && (
        <Dialog
          title="Đổi câu hỏi"
          onClose={() => setSwapModalTarget(null)}
          className="builder-swap-dialog"
        >
          <div className="builder-swap-modal-body">
            {swapTargetQuestion && (
              <div className="builder-swap-subtitle">
                <span>
                  <TranslatedText>Đang thay thế:</TranslatedText> <strong>{swapTargetQuestion.name}</strong>
                </span>
                {swapTargetQuestion.difficulty && (
                  <span className="builder-q-tag difficulty">{swapTargetQuestion.difficulty}</span>
                )}
                {swapModalTarget.isShared && (
                  <span className="builder-q-tag shared"><TranslatedText>Câu chung</TranslatedText></span>
                )}
              </div>
            )}

            <div className="builder-swap-modal-filters">
              <div className="builder-swap-search-wrap">
                <Search size={13} aria-hidden="true" />
                <input
                  type="text"
                  placeholder="Tìm kiếm câu hỏi..."
                  value={swapSearchQuery}
                  onChange={(e) => setSwapSearchQuery(e.target.value)}
                />
              </div>

              <Select
                value={swapCategoryFilter}
                onChange={(e) => setSwapCategoryFilter(e.target.value)}
                className="builder-swap-filter-select"
              >
                <option value="ALL">Tất cả chủ đề</option>
                {previewCategories.map((cat) => (
                  <option key={cat} value={cat}>
                    {cat}
                  </option>
                ))}
              </Select>

              <Select
                value={swapDifficultyFilter}
                onChange={(e) => setSwapDifficultyFilter(e.target.value)}
                className="builder-swap-filter-select"
              >
                <option value="ALL">Tất cả độ khó</option>
                {difficulties.map((d) => (
                  <option key={d.id} value={d.name}>
                    {d.name}
                  </option>
                ))}
              </Select>
            </div>

            <div className="builder-swap-modal-list">
              {candidateQuestions.map((c) => {
                const isCurrent = c.id === swapModalTarget.questionId;
                const isAlreadyInVariant = (activeVariant?.questions[swapModalTarget.part] || []).includes(c.id);
                const isSharedInPart = (sharedRules[swapModalTarget.part] || []).some((r) => r.questionId === c.id);

                return (
                  <div
                    key={c.id}
                    className={`builder-swap-candidate-card${isCurrent ? " is-current" : ""}`}
                  >
                    <div className="builder-swap-candidate-info">
                      <div className="builder-swap-candidate-header">
                        <span className="builder-swap-candidate-name">{c.name}</span>
                        {c.difficulty && (
                          <span className="builder-q-tag difficulty">{c.difficulty}</span>
                        )}
                        {swapCategoryFilter === "ALL" && c.category && (
                          <span className="builder-swap-candidate-cat">{c.category}</span>
                        )}
                        {isSharedInPart && (
                          <span className="builder-q-tag shared"><TranslatedText>Câu chung</TranslatedText></span>
                        )}
                        {!isCurrent && isAlreadyInVariant && (
                          <span className="builder-swap-badge warning">
                            <TranslatedText>Đã có trong đề</TranslatedText>
                          </span>
                        )}
                      </div>
                      {c.description && (
                        <p className="builder-swap-candidate-desc">
                          {c.description.replace(/[\r\n]+/g, " ")}
                        </p>
                      )}
                    </div>

                    <div className="builder-swap-candidate-action">
                      {isCurrent ? (
                        <span className="builder-swap-status-current">
                          <TranslatedText>Đang chọn</TranslatedText>
                        </span>
                      ) : (
                        <button
                          type="button"
                          className="button subtle teacher-small-button builder-swap-btn"
                          onClick={() => handleApplyQuestionSwap(c.id)}
                        >
                          <TranslatedText>Chọn</TranslatedText>
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}

              {candidateQuestions.length === 0 && (
                <div className="builder-swap-empty">
                  <p><TranslatedText>Không tìm thấy câu hỏi phù hợp.</TranslatedText></p>
                </div>
              )}
            </div>

            <div className="builder-swap-modal-footer">
              <span className="builder-swap-candidate-count">
                {candidateQuestions.length} <TranslatedText>câu hỏi phù hợp</TranslatedText>
              </span>
              <button
                type="button"
                className="button subtle teacher-small-button"
                onClick={() => setSwapModalTarget(null)}
              >
                <TranslatedText>Đóng</TranslatedText>
              </button>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  );
}
