import type { SchemaColumn, SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";

const strip = (value: string) => value.trim().replace(/^[\["`]|[\]"`]$/g, "");

/** Bỏ các cặp ngoặc bao ngoài mà SQL Server tự thêm vào giá trị mặc định: ((0)) -> 0, ('abc') -> 'abc'. */
function unwrap(value: string): string {
  let result = value.trim();
  for (;;) {
    if (!result.startsWith("(") || !result.endsWith(")")) return result;
    let depth = 0;
    let wraps = true;
    let quote = false;
    for (let index = 0; index < result.length; index += 1) {
      const char = result[index];
      if (char === "'") quote = !quote;
      if (quote) continue;
      if (char === "(") depth += 1;
      else if (char === ")") depth -= 1;
      if (depth === 0 && index < result.length - 1) { wraps = false; break; }
    }
    if (!wraps) return result;
    result = result.slice(1, -1).trim();
  }
}

/** Các nhóm cột có ràng buộc UNIQUE ở mức bảng (cả UNIQUE đặt riêng và UNIQUE đọc từ sys.indexes). */
function uniqueGroups(table: SchemaTable): string[][] {
  const groups: string[][] = [];
  for (const constraint of table.constraints) {
    if (/\|disabled:1\b/.test(constraint)) continue;
    const match = constraint.match(/^(?:CONSTRAINT\s+\S+\s+)?UNIQUE\b(?:\s+(?:NON)?CLUSTERED)?\s*\(([^)]+)\)/i);
    if (match) groups.push(match[1].split(",").map(strip));
  }
  return groups;
}

/** Giá trị DEFAULT của cột: đọc từ khai báo ngay trên cột hoặc từ ràng buộc mức bảng "DEFAULT [cột] = giá trị". */
function defaultOf(table: SchemaTable, column: SchemaColumn): string | null {
  const lower = column.name.toLowerCase();
  for (const constraint of table.constraints) {
    const match = constraint.match(/^DEFAULT\s+\[([^\]]+)\]\s*=\s*([\s\S]+)$/i);
    if (match && match[1].toLowerCase() === lower) return unwrap(match[2]);
  }
  const inline = (column.constraints || "").match(/\bDEFAULT\s+([\s\S]+?)(?=\s+(?:NOT\s+NULL|NULL|PRIMARY\s+KEY|UNIQUE|REFERENCES|CHECK|CONSTRAINT|COLLATE|IDENTITY)\b|$)/i);
  return inline ? unwrap(inline[1]) : null;
}

export type ColumnTraits = {
  isPk: boolean;
  /** NOT NULL được khai báo rõ (cột khóa chính mặc định là NOT NULL nên không tính ở đây để khỏi lặp huy hiệu PK). */
  notNull: boolean;
  /** UNIQUE trên riêng cột này (không tính khi cột đã là khóa chính một cột). */
  unique: boolean;
  /** UNIQUE gồm nhiều cột: danh sách cột của nhóm (rỗng nếu không có). */
  uniqueWith: string[];
  /** Giá trị DEFAULT (null nếu không có). */
  defaultValue: string | null;
};

export function columnTraits(table: SchemaTable, column: SchemaColumn): ColumnTraits {
  const lower = column.name.toLowerCase();
  const isPk = table.primaryKeys.some((name) => name.toLowerCase() === lower);
  const soleKey = isPk && table.primaryKeys.length === 1;
  const declared = column.constraints || "";
  const groups = uniqueGroups(table).filter((group) => group.some((name) => name.toLowerCase() === lower));
  const single = groups.some((group) => group.length === 1);
  const composite = groups.find((group) => group.length > 1);
  return {
    isPk,
    notNull: !isPk && /\bNOT\s+NULL\b/i.test(declared),
    unique: !soleKey && (/\bUNIQUE\b/i.test(declared) || single),
    uniqueWith: composite ?? [],
    defaultValue: defaultOf(table, column),
  };
}

export type ConstraintTag = { kind: "not-null" | "unique" | "default"; text: string };

/** Các ràng buộc của cột, viết đầy đủ: NOT NULL, UNIQUE, DEFAULT <giá trị>. */
export function constraintTags(traits: ColumnTraits): ConstraintTag[] {
  const tags: ConstraintTag[] = [];
  if (traits.notNull) tags.push({ kind: "not-null", text: "NOT NULL" });
  if (traits.unique) tags.push({ kind: "unique", text: "UNIQUE" });
  else if (traits.uniqueWith.length) tags.push({ kind: "unique", text: `UNIQUE (${traits.uniqueWith.join(", ")})` });
  if (traits.defaultValue !== null) tags.push({ kind: "default", text: `DEFAULT ${traits.defaultValue}` });
  return tags;
}
