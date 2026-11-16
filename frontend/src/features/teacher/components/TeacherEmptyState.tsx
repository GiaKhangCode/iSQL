import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { Link } from "react-router-dom";
import "@/styles/problem-library-filters.css";

export function TeacherEmptyState({ title, description, primaryLabel, primaryTo, secondaryLabel, secondaryTo }: { title: string; description: string; primaryLabel: string; primaryTo: string; secondaryLabel?: string; secondaryTo?: string }) {
  const { t: tr } = useLanguage("teacher");
  return <div className="empty-state"><h2><TranslatedText>{title}</TranslatedText></h2><p><TranslatedText>{description}</TranslatedText></p><div className="teacher-empty-actions"><Link className="button primary" to={primaryTo}><TranslatedText>{tr(primaryLabel)}</TranslatedText></Link>{secondaryLabel && secondaryTo && <Link className="button" to={secondaryTo}><TranslatedText>{tr(secondaryLabel)}</TranslatedText></Link>}</div></div>;
}
