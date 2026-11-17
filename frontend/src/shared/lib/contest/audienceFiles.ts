import { parseAudienceCsv } from "./audienceCsv";
export type AudienceFileMode = "classes" | "students";
export type AudienceFileData = ReturnType<typeof parseAudienceCsv>;

export async function readAudienceFile(file: File, classIds: string[], mode: AudienceFileMode): Promise<AudienceFileData> {
  if (file.size > 1024 * 1024) throw new Error("Choose a file smaller than 1 MB.");
  if (!/\.csv$/i.test(file.name)) throw new Error("Choose a CSV file.");
  return parseAudienceCsv(await file.text(), classIds, mode);
}

export function audienceTemplateText(mode: AudienceFileMode, classIds: string[] = []): string {
  const header = mode === "classes" ? "class_id" : "student_id";
  const examples = mode === "classes" ? (classIds.length ? classIds.slice(0, 2) : ["MA_LOP_01", "MA_LOP_02"]) : ["23520001", "23520002"];
  return [header, ...examples].map(value => /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value).join("\r\n");
}

export function createAudienceTemplate(mode: AudienceFileMode, classIds: string[] = []): Blob {
  return new Blob(["\uFEFF", audienceTemplateText(mode, classIds)], { type: "text/csv;charset=utf-8" });
}
