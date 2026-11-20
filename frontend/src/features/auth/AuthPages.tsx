import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import { authService, validEmail } from "@/features/auth/authService";
import { APP_NAME } from "@/data/models";
import { LoginLayout } from "@/features/auth/LoginLayout";
import { AuthLayout, PasswordField, type Errors } from "@/features/auth/AuthParts";

export function LoginPage() {
  const { t: tr } = useLanguage("auth");
  const auth = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
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
    setMessage("");
    const next: Errors = {};
    if (!email.trim()) {
      next.email = "Enter an email or exam account.";
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
      setMessage(e instanceof Error ? e.message : "Sign-in failed. Try again.");
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
          <TranslatedText>{tr("Email or account")}</TranslatedText>
          <input
            id="login-email"
            type="text"
            autoComplete="username"
            placeholder="name@example.com hoặc tài khoản dự thi"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-invalid={!!errors.email}
            aria-describedby={errors.email ? "email-error" : undefined}
          />
          {errors.email && (
            <small id="email-error" className="field-error">
              {errors.email}
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
        {message && (
          <p role="alert" className="form-message">
            <TranslatedText>{tr(message)}</TranslatedText>
          </p>
        )}
        <button className="button primary full" disabled={busy}>
          <TranslatedText>{busy ? tr("Signing in…") : tr("Sign in")}</TranslatedText>
        </button>
        
      </form>
    </LoginLayout>
  );
}

export function RegisterPage() {
  const { t: tr } = useLanguage("auth");
  const auth = useAuth();
  const navigate = useNavigate();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    const next: Errors = {};
    if (!name.trim()) next.name = "Enter your full name.";
    if (!validEmail(email.trim())) next.email = "Enter a valid school email.";
    if (password.length < 8) next.password = "Use at least 8 characters.";
    if (!confirm || confirm !== password)
      next.confirm = "Passwords must match.";
    setErrors(next);
    setMessage("");
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await auth.register(name.trim(), email, password);
      navigate("/dashboard", { replace: true, state: { welcome: true } });
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Registration failed.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <AuthLayout register showTeacherNote={false}>
      <form onSubmit={(e) => void submit(e)} noValidate>
        <label className="field" htmlFor="register-name">
          <TranslatedText>{tr("Full name")}</TranslatedText>
          <input
            id="register-name"
            type="text"
            autoComplete="name"
            placeholder="Nguyen Van A"
            value={name}
            onChange={(e) => setName(e.target.value)}
            aria-invalid={!!errors.name}
          />
          {errors.name && (
            <small className="field-error">{errors.name}</small>
          )}
        </label>
        <label className="field" htmlFor="register-email"><TranslatedText>{tr("Email")}</TranslatedText><input
            id="register-email"
            type="email"
            autoComplete="email"
            placeholder="name@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-invalid={!!errors.email}
          />
          {errors.email && (
            <small className="field-error">{errors.email}</small>
          )}
        </label>
        <PasswordField
          id="register-password"
          label={tr("Password")}
          value={password}
          onChange={setPassword}
          error={errors.password}
          autoComplete="new-password"
          placeholder={tr("Create a password")}
        />
        <p className="password-requirements"><TranslatedText>{tr("At least 8 characters.")}</TranslatedText></p>
        <PasswordField
          id="register-confirm"
          label={tr("Confirm password")}
          value={confirm}
          onChange={setConfirm}
          error={errors.confirm}
          autoComplete="new-password"
          placeholder={tr("Re-enter your password")}
        />
        {message && (
          <p role="alert" className="form-message">
            <TranslatedText>{tr(message)}</TranslatedText>
          </p>
        )}
        <button className="button primary full" disabled={busy}>
          <TranslatedText>{busy ? tr("Creating your account…") : tr("Continue")}</TranslatedText>
        </button>
        <p className="auth-switch" style={{ marginBottom: "0.5rem" }}>
          <TranslatedText>{tr("Already have an account?")}</TranslatedText> <Link to="/login"><TranslatedText>{tr("Sign in")}</TranslatedText></Link>
        </p>
      </form>
    </AuthLayout>
  );
}

export function RegisterLecturerPage() {
  const { t: tr } = useLanguage("auth");
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [name, setName] = useState("");
  const [department, setDepartment] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [success, setSuccess] = useState(false);
  
  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    const next: Errors = {};
    if (!name.trim()) next.name = "Enter your full name.";
    if (!department.trim()) next.department = "Enter your department.";
    if (!validEmail(email.trim())) next.email = "Enter a valid email address.";
    if (password.length < 8) next.password = "Use at least 8 characters.";
    if (!confirm || confirm !== password) next.confirm = "Passwords must match.";
    
    setErrors(next);
    setMessage("");
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await authService.registerLecturer(name, email, password, department);
      setSuccess(true);
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Registration failed.");
    } finally {
      setBusy(false);
    }
  }

  if (success) {
    return (
      <AuthLayout register title={tr("Request Submitted")} subtitle="Your lecturer account request is awaiting approval." showTeacherNote={false}>
        <div style={{ textAlign: "center", margin: "2rem 0" }}>
          <p style={{ marginBottom: "2rem" }}><TranslatedText>{tr("An administrator will review your request. You will be able to log in once it has been approved.")}</TranslatedText></p>
          <button className="button primary full" onClick={() => navigate("/login")}><TranslatedText>{tr("Back to Sign in")}</TranslatedText></button>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout register title={tr("Register as Lecturer")} subtitle={`Submit a request to become a lecturer on ${APP_NAME}.`} showTeacherNote={false}>
      <form onSubmit={(e) => void submit(e)} noValidate>
        <label className="field" htmlFor="reg-name">
          <TranslatedText>{tr("Full Name")}</TranslatedText>
          <input id="reg-name" type="text" placeholder="Nguyen Van A" value={name} onChange={(e) => setName(e.target.value)} aria-invalid={!!errors.name} />
          {errors.name && <small className="field-error">{errors.name}</small>}
        </label>
        
        <label className="field" htmlFor="reg-department">
          <TranslatedText>{tr("Department / Faculty")}</TranslatedText>
          <input id="reg-department" type="text" placeholder={tr("Computer Science")} value={department} onChange={(e) => setDepartment(e.target.value)} aria-invalid={!!errors.department} />
          {errors.department && <small className="field-error">{errors.department}</small>}
        </label>
        
        <label className="field" htmlFor="reg-email"><TranslatedText>{tr("Email")}</TranslatedText><input id="reg-email" type="email" autoComplete="email" placeholder="name@example.edu.vn" value={email} onChange={(e) => setEmail(e.target.value)} aria-invalid={!!errors.email} />
          {errors.email && <small className="field-error">{errors.email}</small>}
        </label>
        
        <PasswordField id="reg-password" label={tr("Password")} value={password} onChange={setPassword} error={errors.password} autoComplete="new-password" placeholder={tr("Create a password")} />
        <p className="password-requirements"><TranslatedText>{tr("At least 8 characters.")}</TranslatedText></p>
        
        <PasswordField id="reg-confirm" label={tr("Confirm password")} value={confirm} onChange={setConfirm} error={errors.confirm} autoComplete="new-password" placeholder={tr("Re-enter your password")} />
        
        {message && <p role="alert" className="form-message"><TranslatedText>{tr(message)}</TranslatedText></p>}
        
        <button className="button primary full" disabled={busy}><TranslatedText>{busy ? tr("Submitting…") : tr("Submit Request")}</TranslatedText></button>
        <p className="auth-switch" style={{ marginBottom: "0.5rem" }}><TranslatedText>{tr("Already have an account?")}</TranslatedText> <Link to="/login"><TranslatedText>{tr("Sign in")}</TranslatedText></Link></p>
      </form>
    </AuthLayout>
  );
}

export { ForgotPasswordPage, VerifyOtpPage, ResetPasswordPage } from "@/features/auth/AuthRecoveryPages";
