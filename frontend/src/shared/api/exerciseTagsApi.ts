import { apiFetch } from "@/shared/api/apiClient";

export type ExerciseTag = {
  id: string;
  kind: "difficulty" | "category";
  name: string;
  part: "ALL" | "DDL" | "DML" | "DQL" | "TRIGGER";
  orderIndex: number;
  createdAt?: string;
};

export const exerciseTagsApi = {
  getTags(): Promise<ExerciseTag[]> {
    return apiFetch<ExerciseTag[]>("/api/exercise-tags");
  },
  createTag(data: { kind: string; name: string; part?: string; orderIndex?: number }): Promise<ExerciseTag> {
    return apiFetch<ExerciseTag>("/api/admin/exercise-tags", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },
  updateTag(id: string, data: { name?: string; part?: string; orderIndex?: number }): Promise<ExerciseTag> {
    return apiFetch<ExerciseTag>(`/api/admin/exercise-tags/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },
  deleteTag(id: string): Promise<{ message: string }> {
    return apiFetch<{ message: string }>(`/api/admin/exercise-tags/${encodeURIComponent(id)}`, {
      method: "DELETE",
    });
  },
};
