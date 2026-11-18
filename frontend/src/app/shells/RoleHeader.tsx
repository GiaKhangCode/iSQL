import {apiFetch} from "@/shared/api/apiClient";
import { AppearanceSettings } from "@/shared/ui/AppearanceSettings";
import { ToastHistoryBell } from "@/shared/ui/ToastHistoryBell";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { LanguageText } from "@/shared/ui/LanguageSwitch";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { Link, NavLink, useLocation, useNavigate } from "react-router-dom";
import { LogOut } from "lucide-react";
import { useAuth } from "@/app/providers/AuthContext";
import { APP_NAME } from "@/data/models";
import { findMatchingTab, getTabSession, isPathMatchingTab, setTabSession } from "@/shared/lib/tabSession";

type Role = "student" | "teacher" | "office" | "admin";
const links: Record<Role, [string, string][]> = {
  student: [["Dashboard", "/dashboard"], ["Assignments", "/assignments"], ["Submissions", "/submissions"]],
  teacher: [["Problems", "/teacher/problems"], ["Database library", "/teacher/database-library"], ["Assignments", "/teacher/assignments"], ["Classes", "/teacher/classes"], ["Examinations", "/teacher/exams"], ["Results", "/teacher/results"], ["Statistics", "/teacher/statistics"], ["Regrade", "/teacher/regrade"]],
  office: [["Examinations", "/office/examinations"], ["Results", "/office/results"], ["Statistics", "/office/statistics"], ["Regrade assignment", "/office/regrade"]],
  admin: [["Overview", "/admin/overview"], ["Users", "/admin/users"], ["Courses", "/admin/courses"], ["Assignments", "/admin/assignments"], ["Examinations", "/admin/examinations"], ["Regrade assignment", "/admin/regrade"], ["Roles", "/admin/roles"]],
};

export function RoleHeader({ role, workspace, workspaceActions, workspaceProblemNavigation }: { role: Role; workspaceActions?: ReactNode; workspaceProblemNavigation?: ReactNode; workspace?: { title: string; number: string; topic: string; source?: string; context?: string; backTo?: string } }) {
  const { t: tr } = useLanguage("common");
  const { t } = useLanguage();
  const { session, logout, permissions } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [hasProctorDuty,setHasProctorDuty]=useState(false);
  useEffect(()=>{let active=true;
    const refresh=async()=>{
      if(session?.examSessionId||!permissions?.includes('exam.proctor')){if(active)setHasProctorDuty(false);return;}
      try{
        const duties=await apiFetch<unknown[]>("/api/proctor/sessions");
        if(active)setHasProctorDuty(duties.length>0);
      }catch{if(active)setHasProctorDuty(false);}
    };
    void refresh();const timer=setInterval(()=>void refresh(),30000);return()=>{active=false;clearInterval(timer);};
  },[session?.id,permissions?.join("|")]);
  const required:Record<string,string>={"/teacher/problems":"problem.create","/teacher/database-library":"library.manage","/teacher/assignments":"assignment.create","/teacher/results":"results.grade","/teacher/statistics":"results.grade","/office/examinations":"examination.manage","/office/sessions":"examination.manage","/office/results":"results.grade","/office/statistics":"results.grade","/office/regrade":"examination.manage","/teacher/regrade":"results.grade","/teacher/regrade-assignments":"examination.manage","/admin/regrade":"examination.manage"};
  const baseLinks=links[role].filter(([,path])=>!required[path]||permissions?.includes(required[path]));
  const extraLinks:[string,string][]=[];
  if(role==="teacher" && session?.technical_role==="department_head" && permissions?.includes("examination.manage")){
    extraLinks.push(["Regrade assignment","/teacher/regrade-assignments"]);
  }
  const proctorLink: [string,string][] = role!=='teacher'&&hasProctorDuty?[["Proctoring","/proctor"]]:[];
  const orderedLinks: [string,string][] = [...baseLinks,...extraLinks];
  // Admins see Proctoring right after Examinations (before Roles); other roles get it at the end.
  const proctorAt = role==='admin' ? orderedLinks.findIndex(([label])=>label==='Examinations')+1 : 0;
  if(proctorLink.length) orderedLinks.splice(proctorAt>0?proctorAt:orderedLinks.length,0,...proctorLink);
  const navigationLinks: [string,string][] = session?.examSessionId?[["Examinations","/examinations"]]:orderedLinks;
  const [menuOpen, setMenuOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const accountRef = useRef<HTMLDivElement>(null);
  const accountButton = useRef<HTMLButtonElement>(null);
  const navRef = useRef<HTMLElement>(null);
  const [indicator, setIndicator] = useState<{ left: number; width: number } | null>(null);

  const currentUserId = session?.id;
  const fullPath = location.pathname + location.search + location.hash;

  useEffect(() => {
    const tabPaths = navigationLinks.map(([, p]) => p);
    const matchedTab = findMatchingTab(location.pathname, tabPaths);
    if (matchedTab) {
      setTabSession(currentUserId, matchedTab, fullPath);
    }
  }, [location.pathname, location.search, location.hash, navigationLinks, currentUserId, fullPath]);

  useEffect(() => { setMenuOpen(false); setAccountOpen(false); }, [location.pathname]);
  useEffect(() => {
    if (!accountOpen) return;
    accountRef.current?.querySelector<HTMLButtonElement>("#role-account-menu button")?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") { setAccountOpen(false); accountButton.current?.focus(); }
    };
    const onPointer = (event: PointerEvent) => {
      if (!accountRef.current?.contains(event.target as Node)) setAccountOpen(false);
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);
    return () => { document.removeEventListener("keydown", onKey); document.removeEventListener("pointerdown", onPointer); };
  }, [accountOpen]);
  useEffect(() => {
    if (!menuOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setMenuOpen(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [menuOpen]);
  useLayoutEffect(() => {
    const nav = navRef.current;
    if (!nav) return;
    const updateIndicator = () => {
      if (getComputedStyle(nav).display === "none") return;
      const active = nav.querySelector<HTMLElement>("a.active");
      if (!active) return;
      const navBounds = nav.getBoundingClientRect();
      const activeBounds = active.getBoundingClientRect();
      const next = {
        left: activeBounds.left - navBounds.left,
        width: activeBounds.width,
      };
      setIndicator((current) => current?.left === next.left && current.width === next.width ? current : next);
    };
    updateIndicator();
    const observer = new ResizeObserver(updateIndicator);
    observer.observe(nav);
    nav.querySelectorAll("a").forEach((link) => observer.observe(link));
    window.addEventListener("resize", updateIndicator);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", updateIndicator);
    };
  }, [location.pathname, role, menuOpen, workspace,hasProctorDuty,permissions?.join("|")]);
  const accountRole = session?.role === "instructor" ? "Lecturer" : session?.role === "faculty_office" ? "Faculty office" : session?.role === "admin" ? "Admin" : "Student";
  const home = role === "admin" ? "/admin/overview" : links[role][0][1];
  return <header className={`global-header role-header u267-header role-${role}${workspace ? " workspace-header" : ""}`}>
    <Link className="brand" to={home} aria-label={`${APP_NAME} · ${t("Home")}`}><img src="/favicon.svg" width="40" height="40" alt="" /><span className="brand-name">{APP_NAME}</span></Link>
    {workspace ? <><div className="workspace-context-title" title={workspace.context || workspace.title}>{workspace.context || workspace.title}</div>{workspaceProblemNavigation}</> : <>
      <button type="button" className="mobile-menu" aria-label={t(menuOpen ? "Close navigation" : "Open navigation")} aria-expanded={menuOpen} aria-controls={`${role}-navigation`} onClick={() => setMenuOpen(value => !value)}><TranslatedText>{t(menuOpen ? "Close" : "Menu")}</TranslatedText></button>
      <nav ref={navRef} id={`${role}-navigation`} className={`${menuOpen ? "open " : ""}${indicator ? "has-indicator" : ""}`} aria-label={t("Navigation")}>
        {indicator && <span className="nav-active-indicator" aria-hidden="true" style={{ transform: `translateX(${indicator.left}px)`, width: indicator.width }} />}
        {navigationLinks.map(([label, path], index) => {
          const isCurrentActive = isPathMatchingTab(location.pathname, path);
          const savedPath = getTabSession(currentUserId, path);
          const targetPath = savedPath || path;

          return (
            <NavLink
              key={path}
              to={targetPath}
              className={isCurrentActive ? "active" : undefined}
              onClick={(e) => {
                setMenuOpen(false);
                if (isCurrentActive) {
                  if (location.pathname !== path) {
                    e.preventDefault();
                    setTabSession(currentUserId, path, path);
                    navigate(path);
                  }
                }
              }}
            >
              <LanguageText text={label} order={index} />
            </NavLink>
          );
        })}
      </nav>
    </>}
    <TranslatedText>{workspace && tr(workspaceActions)}</TranslatedText>
    <div className="role-account" ref={accountRef}>
      {session?.id && !workspace && <ToastHistoryBell userId={session.id} />}
      <button ref={accountButton} type="button" className="account-trigger" aria-label={t("Account and appearance")} aria-expanded={accountOpen} aria-controls="role-account-menu" onClick={() => { setAccountOpen(value => !value); }}>
        <span className="avatar">{session?.initials || "?"}</span>{!workspace && <span className="account-identity"><b><TranslatedText>{session?.name || t("Account")}</TranslatedText></b><small><TranslatedText>{t(accountRole)}</TranslatedText></small></span>}
      </button>
      {accountOpen && <div id="role-account-menu" className="role-account-menu u219-account-menu" role="region" aria-label={t("Account settings")}>
        <b><TranslatedText>{session?.name || t("Account")}</TranslatedText></b>
        {role === "student" && !session?.examSessionId && <Link to="/profile"><TranslatedText>{tr("Profile")}</TranslatedText></Link>}
        <AppearanceSettings />
        {!workspace && <button type="button" data-workspace-exit onClick={() => { logout(); navigate("/login", { replace: true }); }}><LogOut size={16} /><TranslatedText>{t("Log out")}</TranslatedText></button>}
      </div>}
    </div>
  </header>;
}
