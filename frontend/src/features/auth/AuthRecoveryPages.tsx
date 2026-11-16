import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { Link, useLocation } from "react-router-dom";
import { validEmail } from "@/features/auth/authService";
import { AuthLayout, PasswordField, RecoveryUnavailable, type Errors } from "@/features/auth/AuthParts";

export function ForgotPasswordPage() {
  const { t: tr } = useLanguage("auth");
  const location = useLocation();
  const [email, setEmail] = useState(
    (location.state as { email?: string } | null)?.email ?? "",
  );
  const [error, setError] = useState("");
  const [unavailable, setUnavailable] = useState(false);

  function submit(event: FormEvent) {
    event.preventDefault();
    setUnavailable(false);
    if (!validEmail(email.trim())) {
      setError("Enter a valid email address.");
      return;
    }
    setError("");
    setUnavailable(true);
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
          {error && <small className="field-error"><TranslatedText>{tr(error)}</TranslatedText></small>}
        </label>
        {unavailable && <RecoveryUnavailable />}
        <button className="button primary full" type="submit">
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
  const email =
    (location.state as { email?: string } | null)?.email ?? "name@example.com";
  const [code, setCode] = useState(["", "", "", "", "", ""]);
  const cells = useRef<Array<HTMLInputElement | null>>([]);
  const [unavailable, setUnavailable] = useState(false);
  return (
    <AuthLayout
      title={tr("Verify your email")}
      subtitle={`Enter the 6-digit code sent to ${email}.`}
      showTeacherNote={false}
      centered
    >
      <form
        onSubmit={(event) => {
          event.preventDefault();
          setUnavailable(true);
        }}
      >
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
        <p className="tiny muted"><TranslatedText>{tr("Resend code in 42s")}</TranslatedText></p>
        {unavailable && <RecoveryUnavailable />}
        <button className="button primary full" type="submit">
          <TranslatedText>{tr("Verify")}</TranslatedText>
        </button>
      </form>
      <p className="auth-switch"><Link to="/forgot-password" state={{ email }}><TranslatedText>{tr("← Back")}</TranslatedText></Link></p>
    </AuthLayout>
  );
}

export function ResetPasswordPage() {
  const { t: tr } = useLanguage("auth");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState("");
  const [unavailable, setUnavailable] = useState(false);
  function submit(event: FormEvent) {
    event.preventDefault();
    setUnavailable(false);
    if (password.length < 8) return setError("Use at least 8 characters.");
    if (password !== confirm) return setError("Passwords must match.");
    setError("");
    setUnavailable(true);
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
        {unavailable && <RecoveryUnavailable />}
        <button className="button primary full" type="submit">
          <TranslatedText>{tr("Update password")}</TranslatedText>
        </button>
      </form>
      <p className="auth-switch"><Link to="/login"><TranslatedText>{tr("← Back to sign in")}</TranslatedText></Link></p>
    </AuthLayout>
  );
}
