import "@/features/teacher/problem-editor/SqlFileNameInput.css";

export function SqlFileNameInput({ value, label, onChange, disabled = false }: {
  value: string;
  label: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  const base = value.replace(/\.sql$/i, "");
  return <span className="teacher-sql-filename">
    <span className="teacher-sql-filename-base">
      <span className="teacher-sql-filename-measure" aria-hidden="true">{base || "\u200b"}</span>
      <input className="teacher-sql-filename-input" aria-label={label} value={base} disabled={disabled}
        spellCheck={false} autoComplete="off"
        onChange={event => onChange(`${event.target.value.replace(/\.sql$/i, "")}.sql`)} />
    </span>
    <span className="teacher-sql-filename-extension">.sql</span>
  </span>;
}
