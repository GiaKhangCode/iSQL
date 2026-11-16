import { TranslatedText } from "@/shared/ui/TranslatedText";
import { LockKeyhole } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/features/workspace/components/WorkspaceSubmissionLock.css";

export function WorkspaceSubmissionLock() {
  const { t } = useLanguage("workspace");
  return <span className="workspace-submission-lock" role="status" title={t("Choose Edit again to unlock the editor.")}><LockKeyhole size={14} aria-hidden="true" /><TranslatedText>{t("Submitted · Editor locked")}</TranslatedText></span>;
}
