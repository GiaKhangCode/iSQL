import { StageEditor, type StageEditorProps } from "@/features/workspace/components/StageEditor";
import { useLanguage } from "@/shared/context/LanguageContext";

export function TriggerWorkspace(props:StageEditorProps) {
  const {t}=useLanguage("workspace");
  return <StageEditor {...props} part="TRIGGER" description={t("Database triggers")}
    placeholder={t("-- Write CREATE TRIGGER name ON table AFTER INSERT AS ...")}/>;
}
