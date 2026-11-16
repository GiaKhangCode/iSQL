import { PencilLine } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import "@/shared/ui/UnsavedChangesBadge.css";

export function UnsavedChangesBadge({ visible }: { visible: boolean }) {
  const { t } = useLanguage("teacher");
  if (!visible) return null;
  return <div className="unsaved-changes-badge" role="status">
    <PencilLine size={11} strokeWidth={1.6} aria-hidden="true" />
    <TranslatedText>{t("Unsaved")}</TranslatedText>
  </div>;
}
