import {useAuth} from "@/app/providers/AuthContext";
import type { ReactNode } from "react";
import { RoleHeader } from "@/app/shells/RoleHeader";
export function AppHeader({ workspace, workspaceActions, workspaceProblemNavigation }: { workspaceActions?: ReactNode; workspaceProblemNavigation?: ReactNode; workspace?: { title: string; number: string; topic: string; source?: string; context?: string; backTo?: string } }) { const {session}=useAuth(); return <RoleHeader role={session?.role==="instructor"?"teacher":session?.role==="admin"?"admin":session?.role==="faculty_office"?"office":"student"} workspace={workspace} workspaceActions={workspaceActions} workspaceProblemNavigation={workspaceProblemNavigation} />; }
