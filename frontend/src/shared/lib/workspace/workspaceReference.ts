import type { Problem } from "@/data/models";
import { parseDatabaseSchema } from "@/shared/lib/sql/parseDatabaseSchema";
import { parseSeedData } from "@/shared/lib/sql/parseSeedData";

/** Only uses the instructor data already made visible by the problem API. */
export function workspaceReference(problem: Problem) {
  const sample = problem.testCases?.find(testCase => !testCase.isHidden);
  const schemaTables = parseDatabaseSchema(problem.schema || sample?.schema || "");
  const tables = problem.tables?.length ? problem.tables : parseSeedData(problem.seedData || sample?.seedData || "", schemaTables);
  return { schemaTables, tables };
}

/** The same starting-state view is used by student workspaces and teacher preview. */
export function questionReference<T extends { schemaTables: unknown[]; tables: unknown[] }>(database: T, startFrom?: "empty" | "structure" | "provided"): T {
  return startFrom === "empty" ? { ...database, schemaTables: [], tables: [] }
    : startFrom === "structure" ? { ...database, tables: [] } : database;
}
