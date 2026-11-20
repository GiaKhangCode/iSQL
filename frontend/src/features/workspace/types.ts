export type ExamSessionSchema = {
  schemaName: string;
  tableCount: number;
  tables: Array<{
    tableName: string;
    rowCount: number;
    columns: Array<{
      name: string;
      type: string;
      maxLen: number | null;
      nullable: boolean;
      isPk: boolean;
      isFk: boolean;
      fkTarget?: string | null;
    }>;
  }>;
  relationships?: Array<{
    fromTable: string;
    fromCol: string;
    toTable: string;
    toCol: string;
  }>;
};
