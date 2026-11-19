import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import { homeForArea, type Area } from "@/shared/lib/access/areas";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import {useEffect, type ReactNode} from "react";
export function CapabilityGuard({permission,children}:{permission:string;children:ReactNode}) {
  const {t}=useLanguage();

  const {permissions,permissionsError}=useAuth();
  if(permissions===null)return <p role="status"><TranslatedText>{t("Checking access…")}</TranslatedText></p>;
  if(permissionsError)return <p role="alert">{permissionsError}</p>;
  if(!permissions.includes(permission))return <p role="alert"><TranslatedText>{t("You do not have permission to use this feature.")}</TranslatedText></p>;
  return <>{children}</>;
}
function Checking() {
  const {t}=useLanguage();
  return <p role="status"><TranslatedText>{t("Checking access…")}</TranslatedText></p>;
}

function Failed({message}:{message:string}) {
  return <p role="alert">{message}</p>;
}

/** Tài khoản chưa được gán vai trò nào (không có khu vực): không có nơi nào để chuyển tới. */
function NoArea() {
  const {t}=useLanguage();
  return <p role="alert"><TranslatedText>{t("Your account has no role assigned yet. Please contact an administrator.")}</TranslatedText></p>;
}

function useRememberArea(area: Area, allowed: boolean) {
  const {chooseArea}=useAuth();
  useEffect(()=>{ if(allowed) chooseArea(area); },[area,allowed]);
}

/** Trang chủ: chuyển tới khu vực ưu tiên của người dùng (đường dẫn "/" và sau khi đăng nhập). */
export function HomeRedirect() {
  const { session, areas, preferredArea, permissionsError } = useAuth();
  if (!session) return <Navigate to="/login" replace />;
  if (session.examSessionId) return <Navigate to="/examinations" replace />;
  if (permissionsError) return <Failed message={permissionsError} />;
  if (areas === null) return <Checking />;
  if (!preferredArea) return <NoArea />;
  return <Navigate to={homeForArea(preferredArea)} replace />;
}

function RequireSession() {
  const { session } = useAuth();
  const location = useLocation();
  if (session) return null;
  return <Navigate to="/login" replace state={{ from: location.pathname + location.search + location.hash }} />;
}

/** Khu vực sinh viên. Người không có khu vực sinh viên bị chuyển sang khu vực của họ. */
export function ProtectedRoute() {
  const { session, areas, permissions, permissionsError } = useAuth();
  const location = useLocation();
  const allowed = Boolean(areas?.includes("student"));
  useRememberArea("student", allowed && !session?.examSessionId);
  if (!session) return <RequireSession />;
  if (session.examSessionId) return <Outlet />; // tài khoản thi tạm: không có khu vực, chỉ vào phần thi
  if (permissionsError) return <Failed message={permissionsError} />;
  if (areas === null) return <Checking />;
  if (allowed) return <Outlet />;
  if (areas.includes("teacher")) {
    // Giảng viên có quyền luyện tập (practice.solve) vẫn dùng được trang luyện tập và workspace.
    if (location.pathname.startsWith("/workspace/")) return <CapabilityGuard permission="practice.solve"><Outlet/></CapabilityGuard>;
    if (location.pathname === "/dashboard" && permissions?.includes("practice.solve")) return <Navigate to="/teacher/practice" replace />;
  }
  return <Navigate to="/" replace />;
}

export function PublicOnlyRoute() {
  const session = useAuth().session;
  return session ? <Navigate to="/" replace /> : <Outlet />;
}

function AreaRoute({ area }: { area: Area }) {
  const { session, areas, permissionsError } = useAuth();
  const allowed = Boolean(areas?.includes(area));
  useRememberArea(area, allowed);
  if (!session) return <RequireSession />;
  if (permissionsError) return <Failed message={permissionsError} />;
  if (areas === null) return <Checking />;
  return allowed ? <Outlet /> : <Navigate to="/" replace />;
}

export function AdminRoute() {
  return <AreaRoute area="admin" />;
}

export function TeacherRoute() {
  return <AreaRoute area="teacher" />;
}

/** Khu vực giáo vụ: cần khu vực office (hoặc siêu quản trị) và ít nhất một quyền quản lý kỳ thi. */
export function OfficeRoute() {
  const {t}=useLanguage();

  const {session,areas,permissions,permissionsError,isSuperAdmin}=useAuth();
  const allowed=Boolean(areas?.includes("office"));
  useRememberArea("office",allowed);
  if(!session) return <RequireSession/>;
  if(permissionsError)return <Failed message={permissionsError}/>;
  if(areas===null||permissions===null)return <Checking/>;
  if(!allowed&&!isSuperAdmin) return <Navigate to="/" replace/>;
  return isSuperAdmin || permissions.some(p=>['examination.manage','exam.credentials','results.grade'].includes(p)) ? <Outlet/> : <p role="alert"><TranslatedText>{t("You do not have examination management permission.")}</TranslatedText></p>;
}
