import { useLocation, Outlet } from "react-router-dom";
import { RoleHeader } from "@/app/shells/RoleHeader";
import { AppHeader } from "@/app/shells/AppHeader";
import { TeacherHeader } from "@/app/shells/TeacherHeader";
import { AdminHeader } from "@/app/shells/AdminHeader";

export function Shell() {
  return (
    <>
      <AppHeader />
      <main id="main-content" className="student-main">
        <RouteContent />
      </main>
    </>
  );
}

export function TeacherShell() {
  return (
    <>
      <TeacherHeader />
      <main id="main-content" className="teacher-main">
        <RouteContent />
      </main>
    </>
  );
}

export function OfficeShell() {
  return <><RoleHeader role="office"/><main id="main-content" className="teacher-main"><RouteContent/></main></>;
}

export function AdminShell() {
  return (
    <>
      <AdminHeader />
      <main id="main-content" className="admin-main">
        <RouteContent />
      </main>
    </>
  );
}

function RouteContent() {
  const { pathname } = useLocation();
  return <div className="route-content" key={pathname}><Outlet /></div>;
}
