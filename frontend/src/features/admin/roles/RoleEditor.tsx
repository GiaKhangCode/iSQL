import React, { useEffect, useState } from "react";
import type { PermissionGroup } from "./permissionCatalog";
import { sameSet } from "./permissionCatalog";
import { PermissionPicker } from "./PermissionPicker";
import type { RoleForm, RoleGroup } from "./types";

export interface RoleEditorProps {
  /** null = creating a new role. */
  role: RoleGroup | null;
  groups: PermissionGroup[];
  /** Permissions to start a new role with (when duplicating). */
  template?: string[];
  onSave: (form: RoleForm) => Promise<void>;
  onDuplicate: (permissions: string[]) => void;
  onDirtyChange: (dirty: boolean) => void;
  busy?: boolean;
}

export const RoleEditor: React.FC<RoleEditorProps> = ({ role, groups, template, onSave, onDuplicate, onDirtyChange, busy = false }) => {
  const readOnly = Boolean(role?.readOnly);
  const isSystem = Boolean(role?.isSystem);
  const initialName = role?.name ?? "";
  const initialPermissions = role?.permissions ?? template ?? [];

  const [name, setName] = useState(initialName);
  const [permissions, setPermissions] = useState<string[]>(initialPermissions);

  // Reset when another role is opened or the saved version changes.
  useEffect(() => {
    setName(initialName);
    setPermissions(initialPermissions);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [role?.id, role?.name, role?.permissions.join("|"), template]);

  const dirty = name.trim() !== initialName || !sameSet(permissions, initialPermissions) || (!role && Boolean(template?.length));
  useEffect(() => { onDirtyChange(dirty); }, [dirty, onDirtyChange]);

  const total = groups.reduce((sum, group) => sum + group.permissions.length, 0);
  const canSave = !readOnly && !busy && name.trim().length > 0 && dirty;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!canSave) return;
    if (isSystem && !window.confirm(`Thay đổi này áp dụng cho MỌI tài khoản thuộc nhóm “${initialName}”. Bạn có chắc muốn lưu?`)) return;
    await onSave({ id: role?.id, name: name.trim(), permissions });
  };

  const percent = total ? Math.round((permissions.length / total) * 100) : 0;

  return (
    <form onSubmit={submit} className="role-editor-panel">
      <header className="role-editor-header">
        <div className="role-editor-heading">
          {isSystem ? (
            <h3 className="role-editor-title">{role?.name}</h3>
          ) : (
            <input
              required
              className="role-name-input"
              maxLength={100}
              value={name}
              disabled={busy}
              aria-label="Tên vai trò"
              placeholder="Đặt tên vai trò — ví dụ: Trợ giảng, Trưởng bộ môn…"
              onChange={(event) => setName(event.target.value)}
            />
          )}
          <p className="role-editor-sub">
            {readOnly
              ? "Quản trị viên luôn có toàn bộ quyền và không thể chỉnh sửa."
              : isSystem
                ? "Vai trò có sẵn: áp dụng cho mọi tài khoản cùng loại, cộng với các vai trò khác được gán thêm."
                : role
                  ? "Người được gán vai trò này nhận thêm các quyền được bật bên dưới."
                  : "Đặt tên, rồi bật những việc vai trò này được phép làm."}
          </p>
        </div>
        {isSystem && <span className="role-pill-system">Mặc định</span>}
        <div className="role-meter" aria-label={`Đã bật ${permissions.length} trên ${total} quyền`}>
          <span className="role-meter-text"><strong>{permissions.length}</strong>/{total} quyền</span>
          <span className="role-meter-bar"><span style={{ width: `${percent}%` }} /></span>
        </div>
      </header>

      {isSystem && !readOnly && (
        <p className="role-warning" role="note">
          <span>
            Đang chỉnh vai trò mặc định: thay đổi ảnh hưởng đến tất cả tài khoản {initialName.toLowerCase()}. Muốn cấp thêm quyền cho vài người, hãy
            <button type="button" className="role-link" onClick={() => onDuplicate(permissions)}> nhân bản thành vai trò mới</button>.
          </span>
        </p>
      )}

      <PermissionPicker groups={groups} value={permissions} onChange={setPermissions} readOnly={readOnly || busy} />

      <p className="role-footnote">
        Quản lý người dùng, lớp học và duyệt giảng viên hiện chỉ dành cho Quản trị viên nên chưa thể cấp qua vai trò.
      </p>

      <div className="role-editor-actions" role="group" aria-label="Thao tác vai trò">
        <span className="role-editor-summary">
          Đã chọn <strong>{permissions.length}</strong>/{total} quyền{dirty && <em className="role-dirty"> · Chưa lưu</em>}
        </span>
        <span className="role-editor-buttons">
          {role && !readOnly && !isSystem && (
            <button type="button" className="button" disabled={busy} onClick={() => onDuplicate(permissions)}>Nhân bản</button>
          )}
          {readOnly && role && (
            <button type="button" className="button" onClick={() => onDuplicate(permissions)}>Nhân bản thành vai trò mới</button>
          )}
          {!readOnly && (
            <>
              <button type="button" className="button" disabled={busy || !dirty} onClick={() => { setName(initialName); setPermissions(initialPermissions); }}>
                Hoàn tác
              </button>
              <button type="submit" className="button primary" disabled={!canSave}>
                {busy ? "Đang lưu…" : role ? "Lưu thay đổi" : "Tạo vai trò"}
              </button>
            </>
          )}
        </span>
      </div>
    </form>
  );
};
