import type { DataTable } from "@/data/models";
import type { SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";

export type WorkspaceDatabaseState = {
  schemaTables: SchemaTable[];
  tables: DataTable[];
  stale?: boolean;
};
