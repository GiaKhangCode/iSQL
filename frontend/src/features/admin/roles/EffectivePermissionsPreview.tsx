import React from "react";
import type { PermissionGroup } from "./permissionCatalog";

interface EffectivePermissionsPreviewProps {
  groups: PermissionGroup[];
  /** Permission codes the user will hold. */
  granted: string[];
  /** code -> names of the roles that provide it. */
  sources: Map<string, string[]>;
  fullAccess?: boolean;
  title?: string;
}

/** What the user can actually do, grouped by feature, with the role each permission comes from. */
export const EffectivePermissionsPreview: React.FC<EffectivePermissionsPreviewProps> = ({ groups, granted, sources, fullAccess = false, title = "Quyền hiệu lực" }) => {
  const held = new Set(granted);
  const total = groups.reduce((sum, group) => sum + group.permissions.length, 0);
  const visible = groups
    .map((group) => ({ ...group, permissions: group.permissions.filter((permission) => held.has(permission.code)) }))
    .filter((group) => group.permissions.length);

  return (
    <section className="effective-preview-card" aria-label={title}>
      <header className="effective-preview-header">
        <h4 className="effective-preview-title">{title}</h4>
        <span className="effective-preview-sub">{fullAccess ? "Toàn quyền" : `${held.size}/${total} quyền`}</span>
      </header>
      {visible.length === 0 ? (
        <p className="effective-preview-empty">Chưa có quyền nào.</p>
      ) : (
        <div className="effective-groups">
          {visible.map((group) => (
            <div key={group.id} className="effective-group">
              <span className="effective-group-name">{group.label}</span>
              <ul className="effective-items">
                {group.permissions.map((permission) => (
                  <li key={permission.code} className="effective-item">
                    <span className="effective-item-label">{permission.label}</span>
                    {!fullAccess && (
                      <span className="effective-item-sources">
                        {(sources.get(permission.code) || []).map((name) => <span key={name} className="effective-source">{name}</span>)}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
    </section>
  );
};
