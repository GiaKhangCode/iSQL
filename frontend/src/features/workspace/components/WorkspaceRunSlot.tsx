import { createContext, useContext, type ReactNode } from "react";
import { createPortal } from "react-dom";

/**
 * Chỗ đặt nút "Chạy phần đã chọn / Chạy tất cả" trên thanh trên cùng của workspace (bên trái đồng hồ).
 * Header tạo một thẻ rỗng (node); editor của phần đang mở đưa nút của nó vào đó bằng portal, vì nút cần trạng thái
 * riêng của editor (đoạn đang chọn, đang chạy). Không có header (vd. nhúng trong trang khác) thì nút nằm dưới editor.
 */
type RunSlot = { node: HTMLElement | null; activePart?: string };

const WorkspaceRunSlotContext = createContext<RunSlot>({ node: null });
export const WorkspaceRunSlotProvider = WorkspaceRunSlotContext.Provider;
export const useWorkspaceRunSlot = () => useContext(WorkspaceRunSlotContext);

/** Đưa nút chạy lên header khi phần này đang mở; nếu không có header thì giữ ở dòng dưới editor. */
export function WorkspaceRunActions({ part, children }: { part: string; children: ReactNode }) {
  const { node, activePart } = useWorkspaceRunSlot();
  if (node) return activePart === undefined || activePart === part ? createPortal(children, node) : null;
  return <div className="editor-actions stage-editor-actions">{children}</div>;
}
