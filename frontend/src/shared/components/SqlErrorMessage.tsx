import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { describeSqlError } from "@/shared/lib/sql/sqlErrors";

export function SqlErrorMessage({ message }: { message: string }) {
  const { t: tr } = useLanguage("workspace");
  const error = describeSqlError(message);
  return <div className="workspace-sql-error">
    <strong><TranslatedText>{tr(error.title, error.values)}</TranslatedText></strong>
    {error.detail && <p><TranslatedText>{tr(error.detail, error.values)}</TranslatedText></p>}
  </div>;
}
