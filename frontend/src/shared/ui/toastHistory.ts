import { storage } from "@/shared/api/storage";
import { apiFetch } from "@/shared/api/apiClient";

export type ToastLog = {
  id: string;
  title: string;
  description?: string;
  variant: string;
  createdAt: string;
  path: string;
  read?: boolean;
  starred?: boolean;
  /** true = thông báo lấy từ server (có id thật trong DB); không có = toast chỉ tồn tại ở trình duyệt. */
  server?: boolean;
};

export type ServerNotification = {
  id: string;
  title: string;
  message: string;
  type?: string;
  isRead?: boolean;
  link?: string | null;
  createdAt: string;
};

let owner = "", entries: ToastLog[] = [];
const listeners = new Set<() => void>();

function commit(next: ToastLog[]) {
  entries = next;
  if (owner) storage.set(`toast-history:${owner}`, JSON.stringify(entries));
  listeners.forEach(listener => listener());
}

/**
 * Gửi thay đổi lên server theo kiểu "lạc quan": giao diện đã đổi ngay, request chạy nền.
 * Lỗi mạng/server không được làm hỏng giao diện nên không ném ra ngoài, nhưng vẫn ghi
 * console.warn để còn biết đường gỡ lỗi (trước đây lỗi bị nuốt hoàn toàn).
 * Cố ý KHÔNG hiện toast báo lỗi: toast mới sẽ lại được thêm vào chính danh sách chuông này.
 */
function syncToServer(endpoint: string, method: "PATCH" | "DELETE") {
  void apiFetch(endpoint, { method }).catch(error => {
    console.warn(`[notifications] ${method} ${endpoint} failed:`, error);
  });
}

function getDismissed(ownerId: string): Set<string> {
  if (!ownerId) return new Set();
  try {
    const raw = storage.get(`toast-dismissed:${ownerId}`) || "[]";
    const arr = JSON.parse(raw);
    return new Set(Array.isArray(arr) ? arr : []);
  } catch {
    return new Set();
  }
}

function saveDismissed(ownerId: string, set: Set<string>) {
  if (!ownerId) return;
  try {
    storage.set(`toast-dismissed:${ownerId}`, JSON.stringify([...set].slice(-500)));
  } catch {}
}

export const toastHistory = {
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  },
  snapshot: () => entries,
  currentOwner: () => owner,
  setOwner(id: string) {
    if (owner === id) return;
    owner = id;
    let saved: ToastLog[] = [];
    try {
      const value = JSON.parse(storage.get(`toast-history:${id}`) || "[]");
      if (Array.isArray(value)) {
        saved = value.filter(
          item =>
            item &&
            typeof item.id === "string" &&
            typeof item.title === "string" &&
            typeof item.createdAt === "string" &&
            typeof item.variant === "string" &&
            typeof item.path === "string" &&
            (item.description === undefined || typeof item.description === "string") &&
            (item.read === undefined || typeof item.read === "boolean") &&
            (item.starred === undefined || typeof item.starred === "boolean") &&
            (item.server === undefined || typeof item.server === "boolean")
        );
      }
    } catch {
      /* Corrupt local history must not interrupt the page. */
    }
    entries = id ? saved.slice(0, 200) : [];
    listeners.forEach(listener => listener());
  },
  add(item: { id: string; title: string; description?: string; variant?: string }) {
    if (!owner || item.variant === "loading" || entries.some(entry => entry.id === item.id)) return;
    commit([
      {
        id: item.id,
        title: item.title,
        description: item.description,
        variant: item.variant || "info",
        createdAt: new Date().toISOString(),
        path: window.location.pathname,
      },
      ...entries,
    ].slice(0, 200));
  },
  syncServer(serverItems: ServerNotification[]) {
    if (!owner || !Array.isArray(serverItems)) return;
    const dismissed = getDismissed(owner);
    let changed = false;
    const currentMap = new Map(entries.map(e => [e.id, { ...e }]));

    for (const item of serverItems) {
      if (!item || !item.id || dismissed.has(item.id)) continue;
      const existing = currentMap.get(item.id);
      if (existing) {
        if (!existing.server) {
          existing.server = true;
          changed = true;
        }
        if (item.isRead && !existing.read) {
          existing.read = true;
          changed = true;
        }
        if (item.title && existing.title !== item.title) {
          existing.title = item.title;
          changed = true;
        }
        if (item.message && existing.description !== item.message) {
          existing.description = item.message;
          changed = true;
        }
        if (item.link && existing.path !== item.link) {
          existing.path = item.link;
          changed = true;
        }
      } else {
        const variant =
          item.type === "error"
            ? "error"
            : item.type === "success"
            ? "success"
            : item.type === "warning"
            ? "warning"
            : "info";
        currentMap.set(item.id, {
          id: item.id,
          title: item.title,
          description: item.message,
          variant,
          createdAt: item.createdAt || new Date().toISOString(),
          path: item.link || "",
          read: Boolean(item.isRead),
          starred: false,
          server: true,
        });
        changed = true;
      }
    }

    if (changed) {
      const next = Array.from(currentMap.values());
      next.sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime());
      commit(next.slice(0, 200));
    }
  },
  markRead(id: string) {
    const isServer = entries.some(item => item.id === id && item.server);
    commit(entries.map(item => (item.id === id ? { ...item, read: true } : item)));
    if (isServer) syncToServer(`/api/notifications/${encodeURIComponent(id)}/read`, "PATCH");
  },
  toggleStar(id: string) {
    commit(entries.map(item => (item.id === id ? { ...item, starred: !item.starred } : item)));
  },
  remove(id: string) {
    const isServer = entries.some(item => item.id === id && item.server);
    if (owner) {
      const dismissed = getDismissed(owner);
      dismissed.add(id);
      saveDismissed(owner, dismissed);
    }
    commit(entries.filter(item => item.id !== id));
    if (isServer) syncToServer(`/api/notifications/${encodeURIComponent(id)}`, "DELETE");
  },
  clear() {
    const hasServer = entries.some(item => item.server);
    if (owner) {
      const dismissed = getDismissed(owner);
      entries.forEach(e => dismissed.add(e.id));
      saveDismissed(owner, dismissed);
    }
    commit([]);
    // Xóa hết là đủ; gọi read-all trước khi xóa là thừa vì dữ liệu sắp biến mất.
    if (hasServer) syncToServer("/api/notifications", "DELETE");
  },
};
