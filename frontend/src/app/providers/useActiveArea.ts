import { useLocation } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import { resolveArea, type Area } from "@/shared/lib/access/areas";

/** Khu vực đang xem, suy từ đường dẫn hiện tại (xem resolveArea); null khi chưa tải xong hoặc không có khu vực nào. */
export function useActiveArea(): Area | null {
  const { areas, preferredArea } = useAuth();
  const { pathname } = useLocation();
  return areas ? resolveArea(areas, pathname, preferredArea?.id) : null;
}
