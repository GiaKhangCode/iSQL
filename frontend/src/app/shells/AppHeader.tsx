import {useAuth} from "@/app/providers/AuthContext";
import type { ReactNode } from "react";
import { RoleHeader } from "@/app/shells/RoleHeader";
import type { Area } from "@/shared/lib/access/areas";
export function AppHeader({ workspace, workspaceActions, workspaceProblemNavigation }: { workspaceActions?: ReactNode; workspaceProblemNavigation?: ReactNode; workspace?: { title: string; number: string; topic: string; source?: string; context?: string; backTo?: string } }) { const {areas,preferredArea}=useAuth();
  // Khu vực sinh viên nếu được vào; nếu không (vd giảng viên luyện tập) dùng khu vực ưu tiên của họ.
  const area:Area=areas?.includes("student")?"student":(preferredArea??"student");
  return <RoleHeader role={area} workspace={workspace} workspaceActions={workspaceActions} workspaceProblemNavigation={workspaceProblemNavigation} />; }
