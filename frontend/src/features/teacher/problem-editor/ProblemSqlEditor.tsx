import CodeMirror from "@uiw/react-codemirror";
import { sqlEditorExtensions, sqlEditorHeight } from "@/shared/components/sqlEditorExtensions";

export function ProblemSqlEditor({ value, label, onChange, placeholder, className = "", readOnly = false }: {
  value: string;
  label: string;
  onChange: (value: string) => void;
  placeholder?: string;
  className?: string;
  readOnly?: boolean;
}) {
  return (
    <div className={`teacher-code-section teacher-schema-code-input ${className}`.trim()}>
      <CodeMirror
        aria-label={label}
        value={value}
        height={sqlEditorHeight(value, 8)}
        extensions={sqlEditorExtensions}
        basicSetup={{ lineNumbers: true, foldGutter: false, highlightActiveLine: true }}
        onChange={onChange}
        placeholder={placeholder}
        readOnly={readOnly}
        editable={!readOnly}
      />
    </div>
  );
}
