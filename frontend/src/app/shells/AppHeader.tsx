import type { ReactNode } from "react";
import { RoleHeader } from "@/app/shells/RoleHeader";
export function AppHeader({ workspace, workspaceActions, workspaceProblemNavigation, workspaceExit, onWorkspaceExit }: { workspaceExit?: ReactNode; onWorkspaceExit?: () => void; workspaceActions?: ReactNode; workspaceProblemNavigation?: ReactNode; workspace?: { title: string; number: string; topic: string; source?: string; context?: string; backTo?: string } }) {
  // Mọi khu vực dùng chung một header; khu vực đang xem lấy theo đường dẫn.
  return <RoleHeader workspace={workspace} workspaceActions={workspaceActions} workspaceProblemNavigation={workspaceProblemNavigation} workspaceExit={workspaceExit} onWorkspaceExit={onWorkspaceExit} />; }
