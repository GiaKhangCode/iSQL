import type { WorkspacePart } from "@/data/models";

export const WORKSPACE_PARTS: WorkspacePart[] = ["DDL", "DML", "SQL", "TRIGGER"];
// Keep the persisted SQL part key; SELECT exercises are displayed as DQL.
export const WORKSPACE_PART_LABELS: Record<WorkspacePart, string> = { DDL: "DDL", DML: "DML", SQL: "DQL", TRIGGER: "Trigger" };
export type StagePart = Exclude<WorkspacePart,"SQL">;
export const stageScriptKey = (part:StagePart) => part.toLowerCase() as "ddl"|"dml"|"trigger";

export const WORKSPACE_PART_TOOLTIPS: Record<WorkspacePart, string> = {
  TRIGGER: "Create database triggers. Graded against the exact reference code.",
  DDL: "DDL — Data Definition Language\nDefine database structures with CREATE, ALTER and DROP.",
  DML: "DML — Data Manipulation Language\nAdd, update and delete data with INSERT, UPDATE and DELETE.",
  SQL: "DQL — Data Query Language\nRetrieve data with SELECT, JOIN and aggregation.",
};
export const WORKSPACE_PART_DETAILS: Record<WorkspacePart, { title: string; description: string }> = {
  TRIGGER: { title: "Database triggers", description: "CREATE TRIGGER; exact reference code is required" },
  DDL: { title: "Schema design", description: "CREATE TABLE, ALTER TABLE, primary keys and foreign keys" },
  DML: { title: "Data import", description: "Write INSERT statements to recreate the target dataset" },
  SQL: { title: "Data queries", description: "SELECT, JOIN, aggregation and result checking" },
};

export function workspaceParts(value: unknown): WorkspacePart[] {
  if (!Array.isArray(value)) return ["SQL"];
  return WORKSPACE_PARTS.filter(part => value.includes(part));
}

export function workspaceDraftKey(userId: string, problemId: string, context: string) {
  return `sql-practice:workspace:${encodeURIComponent(userId)}:${encodeURIComponent(context)}:${encodeURIComponent(problemId)}`;
}
