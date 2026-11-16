import type { DatabaseRelationDescription } from "@/data/models";

// Preserve authored predicates from older problems while retiring the duplicate
// editor. Schema-only rows were generated automatically and need no prose copy.
export function mergeLegacyDatabaseDescription(statement: string, rows: DatabaseRelationDescription[] = [], visible = true) {
  if (!visible) return statement;
  const normalize = (value: string) => value.replace(/[*_`]/g, "").replace(/\s+/g, " ").trim();
  let result = statement;
  for (const row of rows) {
    const predicate = row.predicate?.trim();
    if (!predicate || normalize(result).includes(normalize(predicate))) continue;
    const heading = [row.tableName?.trim() ? `**${row.tableName.trim()}**` : "",row.attributes?.trim() ? `(${row.attributes.trim()})` : ""].filter(Boolean).join(" ");
    result += `${result.trim() ? "\n\n" : ""}${heading}${heading ? "\n\n" : ""}*Tân từ:* ${predicate}`;
  }
  return result;
}
