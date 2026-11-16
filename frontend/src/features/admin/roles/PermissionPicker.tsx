import React, { useMemo, useState } from "react";
import type { PermissionGroup } from "./permissionCatalog";

export interface PermissionPickerProps {
  groups: PermissionGroup[];
  value: string[];
  onChange: (next: string[]) => void;
  readOnly?: boolean;
}

const normalize = (text: string) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d").toLowerCase();

/** Permissions grouped by feature, with a search box and one switch per group. */
export const PermissionPicker: React.FC<PermissionPickerProps> = ({ groups, value, onChange, readOnly = false }) => {
  const [query, setQuery] = useState("");
  const selected = useMemo(() => new Set(value), [value]);

  const visible = useMemo(() => {
    const q = normalize(query.trim());
    if (!q) return groups;
    return groups
      .map((group) => ({
        ...group,
        permissions: group.permissions.filter((p) => normalize(`${p.label} ${p.description} ${p.code} ${group.label}`).includes(q)),
      }))
      .filter((group) => group.permissions.length);
  }, [groups, query]);

  const toggle = (code: string) => {
    if (readOnly) return;
    onChange(selected.has(code) ? value.filter((item) => item !== code) : [...value, code]);
  };

  const toggleGroup = (group: PermissionGroup) => {
    if (readOnly) return;
    const codes = group.permissions.map((p) => p.code);
    const allOn = codes.every((code) => selected.has(code));
    onChange(allOn ? value.filter((code) => !codes.includes(code)) : [...new Set([...value, ...codes])]);
  };

  return (
    <div className="permission-picker">
      <div className="permission-picker-toolbar">
        <label className="permission-picker-search">
          <input
            type="search"
            value={query}
            placeholder="Tìm quyền, ví dụ: chấm bài, chia sẻ, kỳ thi…"
            aria-label="Tìm quyền"
            onChange={(event) => setQuery(event.target.value)}
          />
        </label>
        {!readOnly && (
          <div className="permission-picker-bulk">
            <button type="button" className="button" onClick={() => onChange(groups.flatMap((g) => g.permissions.map((p) => p.code)))}>
              Chọn tất cả
            </button>
            <button type="button" className="button" disabled={!value.length} onClick={() => onChange([])}>
              Bỏ chọn hết
            </button>
          </div>
        )}
      </div>

      {visible.length === 0 && <p className="permission-picker-empty">Không có quyền nào khớp “{query}”.</p>}

      {visible.map((group) => {
        const all = groups.find((g) => g.id === group.id) || group;
        const codes = all.permissions.map((p) => p.code);
        const count = codes.filter((code) => selected.has(code)).length;
        return (
          <section key={group.id} className="permission-group">
            <header className="permission-group-header">
              <label className="permission-group-title">
                <input
                  type="checkbox"
                  className="permission-checkbox"
                  checked={count === codes.length && codes.length > 0}
                  ref={(node) => { if (node) node.indeterminate = count > 0 && count < codes.length; }}
                  disabled={readOnly}
                  aria-label={`Chọn toàn bộ nhóm ${group.label}`}
                  onChange={() => toggleGroup(all)}
                />
                <span>
                  <strong>{group.label}</strong>
                  <small>{group.hint}</small>
                </span>
              </label>
              <span className={`permission-group-count${count ? " has-selection" : ""}`}>{count}/{codes.length}</span>
            </header>
            <ul className="permission-list">
              {group.permissions.map((permission) => {
                const checked = selected.has(permission.code);
                return (
                  <li key={permission.code}>
                    <label className={`permission-row${checked ? " is-on" : ""}${readOnly ? " is-readonly" : ""}`}>
                      <input
                        type="checkbox"
                        className="permission-checkbox"
                        checked={checked}
                        disabled={readOnly}
                        onChange={() => toggle(permission.code)}
                      />
                      <span className="permission-row-text">
                        <span className="permission-row-label">
                          {permission.label}
                          {permission.sensitive && <em className="permission-badge-sensitive">Nhạy cảm</em>}
                        </span>
                        <span className="permission-row-desc">{permission.description}</span>
                      </span>
                      <code className="permission-row-code">{permission.code}</code>
                    </label>
                  </li>
                );
              })}
            </ul>
          </section>
        );
      })}
    </div>
  );
};
