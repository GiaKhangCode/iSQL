import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState, type FormEvent } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import { LoginLayout } from "@/features/auth/LoginLayout";
import { PasswordField, type Errors } from "@/features/auth/AuthParts";
import { notify } from "@/shared/ui/notify";

export function LoginPage() {
  const { t: tr } = useLanguage("auth");
  const auth = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);
  const intended = (location.state as { from?: string } | null)?.from;
  const teacherDestination =
    intended && /^\/teacher(?:\/|\?|#|$)/.test(intended) ? intended : null;
  const adminDestination =
    intended && /^\/admin(?:\/|\?|#|$)/.test(intended) ? intended : null;
  const intendedStudentPath =
    intended &&
    /^\/(dashboard|practice|workspace|assignments|submissions)(\/|\?|#|$)/.test(
      intended,
    )
      ? intended
      : null;
  async function signIn() {
    if (busy) return;
    setErrors({});
    const next: Errors = {};
    if (!email.trim()) {
      next.email = "Enter your email or exam username.";
    }
    if (!password.trim()) next.password = "Enter a password.";
    if (Object.keys(next).length) {
      setErrors(next);
      return;
    }
    setBusy(true);
    try {
      const user = await auth.login(email.trim(), password);
      if (user.examSessionId) {
        navigate("/examinations", { replace: true });
        return;
      }
      // Khu vực của người dùng do vai trò quyết định (tải sau đăng nhập): nếu đích mong muốn không thuộc
      // khu vực họ được vào, route guard sẽ chuyển về trang chủ của khu vực ưu tiên.
      navigate(teacherDestination || adminDestination || intendedStudentPath || "/", { replace: true });
    } catch (e) {
      notify.fail(tr("Sign-in failed. Try again."), e, tr);
    } finally {
      setBusy(false);
    }
  }
  function submit(e: FormEvent) {
    e.preventDefault();
    void signIn();
  }
  return (
    <LoginLayout>
      <form onSubmit={submit} noValidate>
        <label className="field" htmlFor="login-email">
          <TranslatedText>{tr("Email or exam username")}</TranslatedText>
          <input
            id="login-email"
            type="text"
            autoComplete="username"
            placeholder={tr("Enter your email or exam username")}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-invalid={!!errors.email}
            aria-describedby={errors.email ? "email-error" : undefined}
          />
          {errors.email && (
            <small id="email-error" className="field-error">
              <TranslatedText>{tr(errors.email)}</TranslatedText>
            </small>
          )}
        </label>
        <PasswordField
          id="login-password"
          label={tr("Password")}
          value={password}
          onChange={setPassword}
          error={errors.password}
          autoComplete="current-password"
          placeholder={tr("Enter your password")}
        />
        <button
          className="forgot-link"
          type="button"
          onClick={() => navigate("/forgot-password", { state: { email } })}
        >
          <TranslatedText>{tr("Forgot password?")}</TranslatedText>
        </button>
        <button className="button primary full" disabled={busy}>
          <TranslatedText>{busy ? tr("Signing in…") : tr("Sign in")}</TranslatedText>
        </button>
        
      </form>
    </LoginLayout>
  );
}

export { ForgotPasswordPage, VerifyOtpPage, ResetPasswordPage } from "@/features/auth/AuthRecoveryPages";
