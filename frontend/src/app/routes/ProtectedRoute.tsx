import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import { homeForRole } from "@/features/auth/authService";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import type {ReactNode} from "react";
export function CapabilityGuard({permission,children}:{permission:string;children:ReactNode}) {
  const {t}=useLanguage();

  const {permissions,permissionsError}=useAuth();
  if(permissions===null)return <p role="status"><TranslatedText>{t("Checking access…")}</TranslatedText></p>;
  if(permissionsError)return <p role="alert">{permissionsError}</p>;
  if(!permissions.includes(permission))return <p role="alert"><TranslatedText>{t("You do not have permission to use this feature.")}</TranslatedText></p>;
  return <>{children}</>;
}
export function ProtectedRoute() {
  const { session,permissions } = useAuth();
  const location = useLocation();
  if (session?.role === "faculty_office") return <Navigate to="/office/examinations" replace />;
  if (session?.role === "instructor") {
    if(location.pathname.startsWith('/workspace/'))return <CapabilityGuard permission="practice.solve"><Outlet/></CapabilityGuard>;
    if(location.pathname==='/dashboard'&&permissions?.includes('practice.solve'))return <Navigate to="/teacher/practice" replace/>;
    return <Navigate to="/teacher/problems" replace />;
  }
  if (session?.role === "admin") {
    return <Navigate to="/admin/overview" replace />;
  }
  return session ? <Outlet /> : (
    <Navigate
      to="/login"
      replace
      state={{ from: location.pathname + location.search + location.hash }}
    />
  );
}
export function PublicOnlyRoute() {
  const session = useAuth().session;
  return session ? (
    <Navigate
      to={homeForRole(session.role)}
      replace
    />
  ) : (
    <Outlet />
  );
}

export function AdminRoute() {
  const { session } = useAuth();
  const location = useLocation();
  if (!session) {
    return (
      <Navigate
        to="/login"
        replace
        state={{ from: location.pathname + location.search + location.hash }}
      />
    );
  }
  return session.role === "admin" ? (
    <Outlet />
  ) : (
    <Navigate
      to={homeForRole(session.role)}
      replace
    />
  );
}

export function TeacherRoute() {
  const { session } = useAuth();
  const location = useLocation();
  if (!session) {
    return (
      <Navigate
        to="/login"
        replace
        state={{ from: location.pathname + location.search + location.hash }}
      />
    );
  }
  return session.role === "instructor" ? (
    <Outlet />
  ) : (
    <Navigate to={homeForRole(session.role)} replace />
  );
}

export function OfficeRoute() {
  const {t}=useLanguage();

  const {session,permissions,permissionsError}=useAuth();
  const location=useLocation();
  if(!session) return <Navigate to="/login" replace state={{from:location.pathname+location.search}}/>;
  if(permissions===null)return <p role="status"><TranslatedText>{t("Checking access…")}</TranslatedText></p>;
  if(permissionsError)return <p role="alert">{permissionsError}</p>;
  return session.role==="admin" || permissions.some(p=>['examination.manage','exam.credentials','results.grade'].includes(p)) ? <Outlet/> : <p role="alert"><TranslatedText>{t("You do not have examination management permission.")}</TranslatedText></p>;
}
