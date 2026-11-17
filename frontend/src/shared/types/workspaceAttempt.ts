import type { WorkspacePart } from "@/data/models";
import type { QueryResult } from "@/shared/types/queryResult";

export type WorkspaceAttempt = { id: number; kind: "run" | "submit"; part?: WorkspacePart; result: QueryResult; script?: string };
