import {Select} from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";

export function WorkspaceSolutionSwitcher({ solutions = [], selectedId, onChange, disabled = false, label = "Solution" }: {
  solutions?: { id: string; name: string }[]; selectedId?: string; onChange: (id: string) => void; disabled?: boolean; label?: string;
}) {
  const { t: tr } = useLanguage("workspace");
  if (!solutions.length) return null;
  const index = Math.max(0, solutions.findIndex(solution => solution.id === selectedId));
  return <label className="workspace-problem-switcher workspace-solution-switcher">
    <span><TranslatedText>{tr(label)}</TranslatedText> {String(index + 1).padStart(2, "0")} / {String(solutions.length).padStart(2, "0")}</span>
    <Select value={solutions[index].id} aria-label={tr(label === "Solution" ? "Switch solution" : "Switch question")} disabled={disabled || solutions.length < 2} onChange={event => onChange(event.target.value)}>
      {solutions.map((solution, number) => <option key={solution.id} value={solution.id}>{String(number + 1).padStart(2, "0")} · {solution.name}</option>)}
    </Select>
  </label>;
}
