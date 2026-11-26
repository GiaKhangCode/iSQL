const SESSION_PREFIX = "sqluit:tab-session:";

/**
 * Checks whether a given pathname belongs to a tab's base path.
 * Supports exact match, prefix match (/path/...), and special aliases
 * (such as /office/sessions belonging to examinations).
 */
export function isPathMatchingTab(pathname: string, tabBasePath: string): boolean {
  if (!pathname || !tabBasePath) return false;
  if (pathname === tabBasePath || pathname.startsWith(tabBasePath + "/")) {
    return true;
  }
  // Alias for examination sessions in office
  if (tabBasePath === "/office/examinations") {
    if (pathname === "/office/sessions" || pathname.startsWith("/office/sessions/")) {
      return true;
    }
  }
  // Admin course subroutes
  if (tabBasePath === "/admin/courses") {
    if (pathname === "/admin/lecturers" || pathname.startsWith("/admin/lecturers/")) {
      return true;
    }
  }
  // Teacher exams alias
  if (tabBasePath === "/teacher/exams") {
    if (pathname === "/proctor" || pathname.startsWith("/proctor/")) {
      return true;
    }
  }
  return false;
}

/**
 * Finds the best matching tab base path for a pathname from a list of tab paths.
 * Returns the one with the longest base path to prevent false prefix matches.
 */
export function findMatchingTab(pathname: string, tabBasePaths: string[]): string | null {
  const matching = tabBasePaths
    .filter((basePath) => isPathMatchingTab(pathname, basePath))
    .sort((a, b) => b.length - a.length);

  return matching[0] || null;
}

/**
 * Retrieves the saved last-visited path for a tab.
 * Validates that the saved path still belongs to this tab.
 */
export function getTabSession(userId: string | undefined, tabBasePath: string): string | null {
  try {
    const key = `${SESSION_PREFIX}${userId || "anon"}:${tabBasePath}`;
    const saved = sessionStorage.getItem(key);
    if (!saved) return null;

    const savedPathname = saved.split("?")[0].split("#")[0];
    if (isPathMatchingTab(savedPathname, tabBasePath)) {
      return saved;
    }
    // Discard stale or mismatched entry
    sessionStorage.removeItem(key);
    return null;
  } catch {
    return null;
  }
}

/**
 * Saves the last-visited path for a tab.
 */
export function setTabSession(userId: string | undefined, tabBasePath: string, fullPath: string): void {
  try {
    const key = `${SESSION_PREFIX}${userId || "anon"}:${tabBasePath}`;
    sessionStorage.setItem(key, fullPath);
  } catch {}
}

/**
 * Clears the saved path for a tab.
 */
export function clearTabSession(userId: string | undefined, tabBasePath: string): void {
  try {
    const key = `${SESSION_PREFIX}${userId || "anon"}:${tabBasePath}`;
    sessionStorage.removeItem(key);
  } catch {}
}
