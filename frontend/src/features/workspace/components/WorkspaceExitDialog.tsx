import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Dialog } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";

/**
 * Hộp thoại của nút Thoát (luyện tập, bài tập; chế độ thi có luồng riêng).
 * Bài chưa nộp: Ở lại · Thoát, giữ bản nháp (khi có onLeaveDraft) · Nộp bài & thoát.
 * Đã nộp và chưa sửa, hoặc không nộp thêm được: chỉ Ở lại · Thoát.
 * Bản nháp luôn được lưu trong trình duyệt khi gõ, nên "giữ bản nháp" chỉ cần rời trang.
 */
export function WorkspaceExitDialog({ saved, blockedReason, busy, onClose, onConfirm, onLeaveDraft }: {
  saved: boolean; blockedReason?: string; busy: boolean; onClose: () => void; onConfirm: () => void; onLeaveDraft?: () => void;
}) {
  const { t } = useLanguage("workspace");
  const canKeepDraft = Boolean(onLeaveDraft) && !saved && !blockedReason;
  return <Dialog title={t(saved || blockedReason || canKeepDraft ? "Exit workspace?" : "Submit & Exit")} onClose={() => { if (!busy) onClose(); }}>
    <p><TranslatedText>{t(saved ? "Your work is already submitted. Exit without submitting it again?" : blockedReason ? "You cannot submit more changes. Your latest submission is saved." : canKeepDraft ? "Submit your work now, or leave and keep your draft in this browser to continue later. A draft is not graded until you submit it." : "Exiting will submit your current work and lock the editor. Continue?")}</TranslatedText></p>
    {blockedReason && <p className="muted"><TranslatedText>{t(blockedReason)}</TranslatedText></p>}
    <div className="workspace-finish-actions">
      <button type="button" className="button" disabled={busy} onClick={onClose}><TranslatedText>{t("Stay here")}</TranslatedText></button>
      {canKeepDraft && <button type="button" className="button" disabled={busy} onClick={onLeaveDraft}><TranslatedText>{t("Leave, keep draft")}</TranslatedText></button>}
      <button type="button" className="button primary" disabled={busy} onClick={onConfirm}><TranslatedText>{t(busy ? "Submitting…" : saved ? "Exit" : blockedReason ? "Exit without resubmitting" : "Submit & Exit")}</TranslatedText></button>
    </div>
  </Dialog>;
}
