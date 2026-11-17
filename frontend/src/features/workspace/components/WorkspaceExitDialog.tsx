import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Dialog } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";

export function WorkspaceExitDialog({ saved, blockedReason, busy, error, onClose, onConfirm }: {
  saved: boolean; blockedReason?: string; busy: boolean; error: string; onClose: () => void; onConfirm: () => void;
}) {
  const { t } = useLanguage("workspace");
  return <Dialog title={t(saved || blockedReason ? "Exit workspace?" : "Submit & Exit")} onClose={() => { if (!busy) onClose(); }}>
    <p><TranslatedText>{t(saved ? "Your work is already submitted. Exit without submitting it again?" : blockedReason ? "You cannot submit more changes. Your latest submission is saved." : "Exiting will submit your current work and lock the editor. Continue?")}</TranslatedText></p>
    {blockedReason && <p className="muted"><TranslatedText>{t(blockedReason)}</TranslatedText></p>}
    {error && <p role="alert" className="form-message">{error}</p>}
    <div className="workspace-finish-actions"><button type="button" className="button" disabled={busy} onClick={onClose}><TranslatedText>{t("Stay here")}</TranslatedText></button><button type="button" className="button primary" disabled={busy} onClick={onConfirm}><TranslatedText>{t(busy ? "Submitting…" : saved ? "Exit" : blockedReason ? "Exit without resubmitting" : "Submit & Exit")}</TranslatedText></button></div>
  </Dialog>;
}
