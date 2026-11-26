import { useEffect, useState } from "react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { APP_NAME } from "@/data/models";
import "@/shared/ui/AccessCheckScreen.css";

/** Sau chừng này mà chưa xong thì báo là đang lâu hơn bình thường và cho tải lại trang. */
const SLOW_AFTER_MS = 8000;

/**
 * Màn hình chờ khi đang hỏi backend quyền của người dùng. `compact` dùng khi nằm bên trong khung trang
 * (header đã hiển thị); mặc định chiếm cả cửa sổ. Câu lệnh SQL chỉ để trang trí.
 */
export function AccessCheckScreen({ compact = false }: { compact?: boolean }) {
  const { t } = useLanguage();
  const [slow, setSlow] = useState(false);
  useEffect(() => {
    const timer = window.setTimeout(() => setSlow(true), SLOW_AFTER_MS);
    return () => window.clearTimeout(timer);
  }, []);

  return (
    <div className={`access-check${compact ? " access-check--compact" : ""}`} role="status">
      <div className="access-check__card">
        {!compact && (
          <div className="access-check__brand">
            <span className="access-check__logo">
              <img src="/favicon.svg" width="56" height="56" alt="" />
            </span>
            <strong>{APP_NAME}</strong>
          </div>
        )}
        <div className="access-check__terminal" aria-hidden="true">
          <div className="access-check__bar"><i /><i /><i /><span>access.sql</span></div>
          <code>
            <span className="access-check__line"><b>SELECT</b> permission</span>
            <span className="access-check__line"><b>FROM</b> access_roles</span>
            <span className="access-check__line"><b>WHERE</b> user_id = <em>@current_user</em>;</span>
          </code>
        </div>
        <div className="access-check__progress" aria-hidden="true"><span /></div>
        <p className="access-check__title"><TranslatedText>{t("Checking access…")}</TranslatedText></p>
        {slow && (
          <div className="access-check__slow">
            <p><TranslatedText>{t("This is taking longer than usual.")}</TranslatedText></p>
            <button type="button" className="button" onClick={() => window.location.reload()}>
              <TranslatedText>{t("Reload page")}</TranslatedText>
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
