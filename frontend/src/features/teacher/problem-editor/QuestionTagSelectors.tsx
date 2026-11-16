import { useEffect, useState } from "react";
import { exerciseTagsApi, type ExerciseTag } from "@/shared/api/exerciseTagsApi";
import { useLanguage } from "@/shared/context/LanguageContext";

let cachedTags: ExerciseTag[] | null = null;
let fetchingPromise: Promise<ExerciseTag[]> | null = null;

export function invalidateExerciseTagsCache() {
  cachedTags = null;
  fetchingPromise = null;
}

export function useExerciseTags() {
  const [tags, setTags] = useState<ExerciseTag[]>(cachedTags || []);
  const [loading, setLoading] = useState(!cachedTags);

  const fetchTags = async (force = false) => {
    if (force) {
      invalidateExerciseTagsCache();
    }
    if (cachedTags && !force) {
      setTags(cachedTags);
      setLoading(false);
      return cachedTags;
    }
    if (typeof window === "undefined" || !window.location) {
      setLoading(false);
      return [];
    }
    if (!fetchingPromise) {
      fetchingPromise = exerciseTagsApi.getTags().then((res) => {
        cachedTags = res;
        return res;
      }).catch(() => {
        fetchingPromise = null;
        return [];
      });
    }
    const res = await fetchingPromise;
    setTags(res);
    setLoading(false);
    return res;
  };

  useEffect(() => {
    void fetchTags();
  }, []);

  return { tags, loading, refetch: () => fetchTags(true) };
}

export function QuestionTagSelectors({
  part,
  difficulty,
  category,
  onDifficultyChange,
  onCategoryChange,
  disabled
}: {
  part: "DDL" | "DML" | "DQL" | "TRIGGER";
  difficulty?: string;
  category?: string;
  onDifficultyChange: (val: string) => void;
  onCategoryChange: (val: string) => void;
  disabled?: boolean;
}) {
  const { t: tr } = useLanguage("teacher");
  const { tags } = useExerciseTags();

  const difficulties = tags.filter((t) => t.kind === "difficulty");
  const categories = tags.filter(
    (t) => t.kind === "category" && (t.part === "ALL" || t.part === part)
  );

  return (
    <>
      <select
        className="teacher-question-tag-select"
        value={difficulty || ""}
        disabled={disabled}
        onChange={(e) => onDifficultyChange(e.target.value)}
        title={tr("Difficulty")}
        aria-label={tr("Difficulty")}
      >
        <option value="">{tr("-- Difficulty --")}</option>
        {difficulty && !difficulties.some((d) => d.name === difficulty) && (
          <option value={difficulty}>{difficulty}</option>
        )}
        {difficulties.map((d) => (
          <option key={d.id} value={d.name}>
            {d.name}
          </option>
        ))}
      </select>

      <select
        className="teacher-question-tag-select"
        value={category || ""}
        disabled={disabled}
        onChange={(e) => onCategoryChange(e.target.value)}
        title={tr("Category")}
        aria-label={tr("Category")}
      >
        <option value="">{tr("-- Category --")}</option>
        {category && !categories.some((c) => c.name === category) && (
          <option value={category}>{category}</option>
        )}
        {categories.map((c) => (
          <option key={c.id} value={c.name}>
            {c.name}
          </option>
        ))}
      </select>
    </>
  );
}
