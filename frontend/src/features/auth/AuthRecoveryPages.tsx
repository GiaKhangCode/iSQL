import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { Link, Navigate, useLocation, useNavigate } from "react-router-dom";
import { authService, validEmail } from "@/features/auth/authService";
import { AuthLayout, PasswordField } from "@/features/auth/AuthParts";

const RESEND_SECONDS = 60;

const errorText = (error: unknown, fallback: string) =>
  error instanceof Error && error.message ? error.message : fallback;

export function ForgotPasswordPage() {
  const { t: tr } = useLanguage("auth");
  const location = useLocation();
  const navigate = useNavigate();
  const [email, setEmail] = useState(
    (location.state as { email?: string } | null)?.email ?? "",
  );
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    const value = email.trim();
    if (!validEmail(value)) {
      setError("Enter a valid email address.");
      return;
    }
    setError("");
    setBusy(true);
    try {
      await authService.requestPasswordReset(value);
      navigate("/verify-otp", { state: { email: value, sentAt: Date.now() } });
    } catch (err) {
      setError(errorText(err, "Could not send the verification code. Try again."));
      setBusy(false);
    }
  }

  return (
    <AuthLayout
      title={tr("Forgot your password?")}
      compact
      subtitle="Enter your email and we’ll send you a verification code."
      showTeacherNote={false}
    >
      <form onSubmit={submit} noValidate>
        <label className="field" htmlFor="recovery-email"><TranslatedText>{tr("Email")}</TranslatedText><input
            id="recovery-email"
            type="email"
            autoComplete="email"
            placeholder="name@example.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            aria-invalid={!!error}
          />
          {error && <small className="field-error" role="alert"><TranslatedText>{tr(error)}</TranslatedText></small>}
        </label>
        <button className="button primary full" type="submit" disabled={busy}>
          <TranslatedText>{tr("Send code")}</TranslatedText>
        </button>
      </form>
      <p className="auth-switch"><Link to="/login"><TranslatedText>{tr("← Back to sign in")}</TranslatedText></Link></p>
    </AuthLayout>
  );
}

export function VerifyOtpPage() {
  const { t: tr } = useLanguage("auth");
  const location = useLocation();
  const navigate = useNavigate();
  const state = location.state as { email?: string; sentAt?: number } | null;
  const email = state?.email ?? "";
  const [code, setCode] = useState(["", "", "", "", "", ""]);
  const cells = useRef<Array<HTMLInputElement | null>>([]);
  const [error, setError] = useState("");
  const [info, setInfo] = useState("");
  const [busy, setBusy] = useState(false);
  const [cooldown, setCooldown] = useState(() => {
    const elapsed = state?.sentAt ? Math.floor((Date.now() - state.sentAt) / 1000) : RESEND_SECONDS;
    return Math.max(0, RESEND_SECONDS - elapsed);
  });

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setTimeout(() => setCooldown((value) => value - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [cooldown]);

  // Không có email (vào thẳng trang) thì quay về bước nhập email.
  if (!email) return <Navigate to="/forgot-password" replace />;

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    const otp = code.join("");
    if (otp.length !== 6) {
      setInfo("");
      setError("Enter the 6-digit code.");
      return;
    }
    setError("");
    setInfo("");
    setBusy(true);
    try {
      await authService.verifyResetOtp(email, otp);
      navigate("/reset-password", { state: { email, otp }, replace: true });
    } catch (err) {
      setError(errorText(err, "Could not verify the code. Try again."));
      setBusy(false);
    }
  }

  async function resend() {
    if (busy || cooldown > 0) return;
    setError("");
    setInfo("");
    setBusy(true);
    try {
      await authService.requestPasswordReset(email);
      setCode(["", "", "", "", "", ""]);
      setCooldown(RESEND_SECONDS);
      setInfo("A new verification code has been sent.");
      cells.current[0]?.focus();
    } catch (err) {
      setError(errorText(err, "Could not send the verification code. Try again."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthLayout
      title={tr("Verify your email")}
      subtitle={`Enter the 6-digit code sent to ${email}.`}
      showTeacherNote={false}
      centered
    >
      <form onSubmit={submit}>
        <fieldset className="otp-fieldset">
          <legend className="sr-only"><TranslatedText>{tr("Verification code")}</TranslatedText></legend>
          <div className="otp-cells">
            {code.map((digit, index) => (
              <input
                key={index}
                ref={(node) => { cells.current[index] = node; }}
                aria-label={tr("Verification code digit {value0} of 6", { value0: index + 1 })}
                inputMode="numeric"
                autoComplete={index === 0 ? "one-time-code" : "off"}
                maxLength={1}
                value={digit}
                onChange={(event) => {
                  const next = [...code];
                  next[index] = event.target.value.replace(/\D/g, "").slice(-1);
                  setCode(next);
                  if (next[index] && index < 5) cells.current[index + 1]?.focus();
                }}
                onKeyDown={(event: KeyboardEvent<HTMLInputElement>) => {
                  if (event.key === "Backspace" && !digit && index > 0) {
                    cells.current[index - 1]?.focus();
                  }
                }}
                onPaste={(event) => {
                  event.preventDefault();
                  const pasted = event.clipboardData
                    .getData("text")
                    .replace(/\D/g, "")
                    .slice(0, 6);
                  if (!pasted) return;
                  const next = [...code];
                  pasted.split("").forEach((value, digitIndex) => {
                    next[digitIndex] = value;
                  });
                  setCode(next);
                  cells.current[Math.min(pasted.length, 5)]?.focus();
                }}
              />
            ))}
          </div>
        </fieldset>
        <p className="tiny muted">
          {cooldown > 0
            ? <TranslatedText>{tr("Resend code in {value0}s", { value0: cooldown })}</TranslatedText>
            : <button type="button" className="forgot-link" onClick={resend} disabled={busy}><TranslatedText>{tr("Resend code")}</TranslatedText></button>}
        </p>
        {error && <p className="form-message" role="alert"><TranslatedText>{tr(error)}</TranslatedText></p>}
        {info && <p className="form-message" role="status"><TranslatedText>{tr(info)}</TranslatedText></p>}
        <button className="button primary full" type="submit" disabled={busy}>
          <TranslatedText>{tr("Verify")}</TranslatedText>
        </button>
      </form>
      <p className="auth-switch"><Link to="/forgot-password" state={{ email }}><TranslatedText>{tr("← Back")}</TranslatedText></Link></p>
    </AuthLayout>
  );
}

export function ResetPasswordPage() {
  const { t: tr } = useLanguage("auth");
  const location = useLocation();
  const state = location.state as { email?: string; otp?: string } | null;
  const email = state?.email ?? "";
  const otp = state?.otp ?? "";
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

  // Chưa qua bước xác thực OTP thì không được đặt lại mật khẩu.
  if (!email || !otp) return <Navigate to="/forgot-password" replace />;

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    if (password.length < 8) return setError("Use at least 8 characters.");
    if (password !== confirm) return setError("Passwords must match.");
    setError("");
    setBusy(true);
    try {
      await authService.resetPassword(email, otp, password);
      setDone(true);
    } catch (err) {
      setError(errorText(err, "Could not update the password. Try again."));
      setBusy(false);
    }
  }

  if (done) {
    return (
      <AuthLayout title={tr("Set a new password")} showTeacherNote={false} centered>
        <p className="form-message" role="status"><TranslatedText>{tr("Your password has been updated. Sign in with your new password.")}</TranslatedText></p>
        <Link className="button primary full" to="/login"><TranslatedText>{tr("Back to sign in")}</TranslatedText></Link>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title={tr("Set a new password")}
      subtitle="Choose a new password for your account."
      showTeacherNote={false}
    >
      <form onSubmit={submit} noValidate>
        <PasswordField
          id="new-password"
          label={tr("New password")}
          value={password}
          onChange={setPassword}
          autoComplete="new-password"
          placeholder={tr("Enter a new password")}
        />
        <p className="password-requirements"><TranslatedText>{tr("At least 8 characters.")}</TranslatedText></p>
        <PasswordField
          id="confirm-new-password"
          label={tr("Confirm password")}
          value={confirm}
          onChange={setConfirm}
          autoComplete="new-password"
          placeholder={tr("Re-enter the new password")}
        />
        {error && (
          <p className="field-error" role="alert">
            <TranslatedText>{tr(error)}</TranslatedText>
          </p>
        )}
        <button className="button primary full" type="submit" disabled={busy}>
          <TranslatedText>{tr("Update password")}</TranslatedText>
        </button>
      </form>
      <p className="auth-switch"><Link to="/login"><TranslatedText>{tr("← Back to sign in")}</TranslatedText></Link></p>
    </AuthLayout>
  );
}
