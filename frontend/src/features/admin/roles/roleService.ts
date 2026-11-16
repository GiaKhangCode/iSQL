import { legacyRoleService } from "./legacyRoleAdapter";
import type { IRoleService } from "./types";

/** Facade used by the role pages; swap the backing implementation when the API changes. */
export const roleService: IRoleService = legacyRoleService;
export * from "./types";
export * from "./permissionCatalog";
