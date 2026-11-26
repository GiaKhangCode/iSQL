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
import { useActiveArea } from "@/app/providers/useActiveArea";
import { APP_NAME } from "@/data/models";
import { EXTRA_AREA, homeOfArea } from "@/shared/lib/access/areas";
import { findMatchingTab, getTabSession, isPathMatchingTab, setTabSession } from "@/shared/lib/tabSession";

/** Header dùng chung cho mọi khu vực (kể cả sinh viên): khu vực đang xem và các tab của nó lấy từ vai trò, xem useActiveArea. */
export function RoleHeader({ workspace, workspaceActions, workspaceProblemNavigation, workspaceExit, onWorkspaceExit }: { workspaceExit?: ReactNode; onWorkspaceExit?: () => void; workspaceActions?: ReactNode; workspaceProblemNavigation?: ReactNode; workspace?: { title: string; number: string; topic: string; source?: string; context?: string; backTo?: string } }) {
  const { t: tr } = useLanguage("common");
  const { t } = useLanguage();
  const { session, logout, permissions, areas, chooseArea } = useAuth();
  const area = useActiveArea();
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
  // Tab của khu vực = tab mà quyền của vai trò đó mở ra (NAV_TABS); khu vực Quyền bổ sung lấy tab từ quyền gán riêng.
  const baseLinks: [string,string][]=(area?.tabs??[]).map(tab=>[tab.label,tab.path]);
  // Người có ca coi thi mà khu vực này chưa có tab Proctoring thì thêm lối vào phòng thi ở cuối thanh điều hướng.
  const hasProctorTab=baseLinks.some(([,path])=>path==='/teacher/exams');
  const proctorLink: [string,string][] = !hasProctorTab&&hasProctorDuty?[["Proctoring","/proctor"]]:[];
  const orderedLinks: [string,string][] = [...baseLinks,...proctorLink];
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
  }, [location.pathname, area?.id, menuOpen, workspace,hasProctorDuty,permissions?.join("|")]);
  const accountRole = area?.label ?? "";
  const home = area ? homeOfArea(area) : "/";
  // Kiểu giao diện của header theo nhóm trang của tab đầu tiên (student | teacher | office | admin), hoặc extra.
  const headerKind = area?.id === EXTRA_AREA ? "extra" : (area?.tabs[0]?.group ?? "student");
  return <header className={`global-header role-header u267-header role-${headerKind}${workspace ? " workspace-header" : ""}`}>
    <Link className="brand" to={home} aria-label={`${APP_NAME} · ${t("Home")}`} onClick={event => {
      // Trong workspace, bấm logo cũng đi qua hộp thoại Thoát (giữ bản nháp / nộp bài) thay vì rời trang ngay.
      if (workspace && onWorkspaceExit) { event.preventDefault(); onWorkspaceExit(); }
    }}><img src="/favicon.svg" width="40" height="40" alt="" /><span className="brand-name">{APP_NAME}</span></Link>
    {/* Workspace: logo · nút Thoát · chuyển phần/câu ··· Nộp bài. Tên bài tập nằm ở đầu cột đề bài (WorkspacePaneTitle), không có avatar. */}
    {workspace ? <>{workspaceExit}{workspaceProblemNavigation}</> : <>
      <button type="button" className="mobile-menu" aria-label={t(menuOpen ? "Close navigation" : "Open navigation")} aria-expanded={menuOpen} aria-controls="role-navigation" onClick={() => setMenuOpen(value => !value)}><TranslatedText>{t(menuOpen ? "Close" : "Menu")}</TranslatedText></button>
      <nav ref={navRef} id="role-navigation" className={`${menuOpen ? "open " : ""}${indicator ? "has-indicator" : ""}`} aria-label={t("Navigation")}>
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
    {!workspace && <div className="role-account" ref={accountRef}>
      {session?.id && !workspace && <ToastHistoryBell userId={session.id} />}
      <button ref={accountButton} type="button" className="account-trigger" aria-label={t("Account and appearance")} aria-expanded={accountOpen} aria-controls="role-account-menu" onClick={() => { setAccountOpen(value => !value); }}>
        <span className="avatar">{session?.initials || "?"}</span>{!workspace && <span className="account-identity"><b><TranslatedText>{session?.name || t("Account")}</TranslatedText></b><small><TranslatedText>{t(accountRole)}</TranslatedText></small></span>}
      </button>
      {accountOpen && <div id="role-account-menu" className="role-account-menu u219-account-menu" role="region" aria-label={t("Account settings")}>
        <b><TranslatedText>{session?.name || t("Account")}</TranslatedText></b>
        {!workspace && !session?.examSessionId && areas && areas.length > 1 && <div className="area-switch" role="group" aria-label={t("Switch area")}>
          {areas.map(item => <button key={item.id} type="button" aria-pressed={item.id === area?.id} disabled={item.id === area?.id} onClick={() => { chooseArea(item.id); navigate(homeOfArea(item)); }}><TranslatedText>{t(item.label)}</TranslatedText></button>)}
        </div>}
        {permissions?.includes("practice.solve") && !session?.examSessionId && <Link to="/profile"><TranslatedText>{tr("Profile")}</TranslatedText></Link>}
        <AppearanceSettings />
        {!workspace && <button type="button" data-workspace-exit onClick={() => { logout(); navigate("/login", { replace: true }); }}><LogOut size={16} /><TranslatedText>{t("Log out")}</TranslatedText></button>}
      </div>}
    </div>}
  </header>;
}
