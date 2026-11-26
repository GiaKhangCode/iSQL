import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import { canOpenGroup, EXTRA_AREA, firstTabOfGroup, homeOfArea, type TabGroup } from "@/shared/lib/access/areas";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import { useActiveArea } from "@/app/providers/useActiveArea";
import {useEffect, type ReactNode} from "react";
import { AccessCheckScreen } from "@/shared/ui/AccessCheckScreen";
/** Chặn trang theo mã quyền; `permission` có thể là nhiều mã cách nhau bằng dấu phẩy (đạt khi có ít nhất một). */
export function CapabilityGuard({permission,children}:{permission:string;children:ReactNode}) {
  const {t}=useLanguage();

  const {permissions,permissionsError}=useAuth();
  if(permissions===null)return <AccessCheckScreen compact />;
  if(permissionsError)return <p role="alert">{permissionsError}</p>;
  if(!permission.split(",").some(code=>permissions.includes(code.trim())))return <p role="alert"><TranslatedText>{t("You do not have permission to use this feature.")}</TranslatedText></p>;
  return <>{children}</>;
}
function Checking() {
  return <AccessCheckScreen />;
}

function Failed({message}:{message:string}) {
  return <p role="alert">{message}</p>;
}

/** Tài khoản chưa được gán vai trò nào (không có khu vực): không có nơi nào để chuyển tới. */
function NoArea() {
  const {t}=useLanguage();
  return <p role="alert"><TranslatedText>{t("Your account has no role assigned yet. Please contact an administrator.")}</TranslatedText></p>;
}

/** Ghi nhớ khu vực đang xem (chỉ ảnh hưởng trang chủ khi vào "/" hoặc sau khi đăng nhập). */
function useRememberArea(enabled: boolean) {
  const {chooseArea}=useAuth();
  const area=useActiveArea();
  const areaId=area?.id;
  useEffect(()=>{ if(enabled&&areaId) chooseArea(areaId); },[enabled,areaId]);
}

/** Trang chủ: chuyển tới khu vực ưu tiên của người dùng (đường dẫn "/" và sau khi đăng nhập). */
export function HomeRedirect() {
  const { session, areas, preferredArea, permissionsError } = useAuth();
  if (!session) return <Navigate to="/login" replace />;
  if (session.examSessionId) return <Navigate to="/examinations" replace />;
  if (permissionsError) return <Failed message={permissionsError} />;
  if (areas === null) return <Checking />;
  if (!preferredArea) return <NoArea />;
  return <Navigate to={homeOfArea(preferredArea)} replace />;
}

function RequireSession() {
  const { session } = useAuth();
  const location = useLocation();
  if (session) return null;
  return <Navigate to="/login" replace state={{ from: location.pathname + location.search + location.hash }} />;
}

/**
 * Các trang của sinh viên (/dashboard, /assignments, /workspace/...). Mỗi trang tự chặn bằng mã quyền của nó
 * (CapabilityGuard); ở đây chỉ cần đã đăng nhập và có ít nhất một khu vực.
 */
export function ProtectedRoute() {
  const { session, areas, permissionsError } = useAuth();
  useRememberArea(Boolean(session && !session.examSessionId));
  if (!session) return <RequireSession />;
  if (session.examSessionId) return <Outlet />; // tài khoản thi tạm: không có khu vực, chỉ vào phần thi
  if (permissionsError) return <Failed message={permissionsError} />;
  if (areas === null) return <Checking />;
  if (areas.length === 0) return <Navigate to="/" replace />;
  return <Outlet />;
}

export function PublicOnlyRoute() {
  const session = useAuth().session;
  return session ? <Navigate to="/" replace /> : <Outlet />;
}

/**
 * Cụm trang /admin, /office hoặc /teacher: vào được khi người dùng có ít nhất một tab của nhóm đó. Trang cụ thể
 * vẫn bị chặn bằng mã quyền riêng của nó (CapabilityGuard), nên khu vực không còn quyết định việc vào trang.
 */
function GroupRoute({ group }: { group: TabGroup }) {
  const { session, permissions, permissionsError } = useAuth();
  useRememberArea(Boolean(session && permissions && canOpenGroup(permissions, group)));
  if (!session) return <RequireSession />;
  if (permissionsError) return <Failed message={permissionsError} />;
  if (permissions === null) return <Checking />;
  return canOpenGroup(permissions, group) ? <Outlet /> : <Navigate to="/" replace />;
}

/** Trang đầu của một nhóm trang (route index): tab đầu tiên của nhóm mà người dùng được thấy. */
export function AreaIndex({ area }: { area: TabGroup }) {
  const { permissions } = useAuth();
  if (permissions === null) return <Checking />;
  const first = firstTabOfGroup(permissions, area);
  return <Navigate to={first ? first.path : "/"} replace />;
}

/** Trang chủ của khu vực Quyền bổ sung: tab đầu tiên. */
export function ExtraHome() {
  const { session, areas, permissionsError } = useAuth();
  if (!session) return <RequireSession />;
  if (permissionsError) return <Failed message={permissionsError} />;
  if (areas === null) return <Checking />;
  const extra = areas.find((area) => area.id === EXTRA_AREA);
  return <Navigate to={extra ? homeOfArea(extra) : "/"} replace />;
}

export function AdminRoute() {
  return <GroupRoute group="admin" />;
}

export function TeacherRoute() {
  return <GroupRoute group="teacher" />;
}

export function OfficeRoute() {
  return <GroupRoute group="office" />;
}
