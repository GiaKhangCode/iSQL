export type DataTable = {
  name: string;
  columns: string[];
  rows: (string | number)[][];
  rowCount?: number;
};

export type WorkspacePart = "DDL" | "DML" | "SQL" | "TRIGGER";
