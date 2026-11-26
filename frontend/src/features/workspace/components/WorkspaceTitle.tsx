import { createContext, useContext } from "react";

/**
 * Tên bài tập / kỳ thi đang làm, hiện ở đầu cột đề bài (thay cho tiêu đề trên thanh trên cùng của workspace).
 * Luyện tập tự do không có tên hoạt động nên để trống: tab Mô tả đã có tên bài toán.
 */
const WorkspaceTitleContext = createContext("");

export const WorkspaceTitleProvider = WorkspaceTitleContext.Provider;
export const useWorkspaceTitle = () => useContext(WorkspaceTitleContext);

export function WorkspacePaneTitle() {
  const title = useWorkspaceTitle();
  if (!title) return null;
  return <div className="workspace-pane-title"><h2 title={title}>{title}</h2></div>;
}
