import { storage } from "@/shared/api/storage";
import { apiFetch } from "@/shared/api/apiClient";

export type StudentSession = {
  id: string;
  name: string;
  initials: string;
  email: string;
  /** Loại danh tính (không phải quyền): quyền và khu vực lấy từ /api/auth/permissions. */
  account_type: "student" | "staff";
  /** Tên các vai trò đang giữ, chỉ để hiển thị. */
  roles?: { id: string; name: string }[];
  examSessionId?: string | null;
};

export const SESSION_KEY = "sql-practice:session:v1";
export const TOKEN_KEY = "sql-practice:access-token";
let sessionVersion = 0;

export const validEmail = (email: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

function persist(user: StudentSession, token: string) {
  sessionVersion += 1;
  storage.set(SESSION_KEY, JSON.stringify(user));
  storage.set(TOKEN_KEY, token);
  return user;
}

export const authService = {
  async examLogin(username:string,password:string) {
    const data=await apiFetch("/api/exam-auth/login",{method:"POST",body:JSON.stringify({username,password})});
    return persist(data.user,data.access_token);
  },
  async login(email: string, password: string) {
    if (!email.trim() || !password.trim())
      throw new Error("Enter an email/account and a password.");
    const data = await apiFetch("/api/auth/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    return persist(data.user, data.access_token);
  },
  
  async requestPasswordReset(email: string) {
    await apiFetch("/api/auth/forgot-password", {
      method: "POST",
      body: JSON.stringify({ email: email.trim() }),
    });
  },

  async verifyResetOtp(email: string, otp: string) {
    await apiFetch("/api/auth/verify-otp", {
      method: "POST",
      body: JSON.stringify({ email: email.trim(), otp }),
    });
  },

  async resetPassword(email: string, otp: string, password: string) {
    await apiFetch("/api/auth/reset-password", {
      method: "POST",
      body: JSON.stringify({ email: email.trim(), otp, password }),
    });
  },

  logout() {
    sessionVersion += 1;
    storage.remove(SESSION_KEY);
    storage.remove(TOKEN_KEY);
  },
  
  async restoreSessionAsync(): Promise<StudentSession | null> {
    const token = storage.get(TOKEN_KEY);
    if (!token) {
      storage.remove(SESSION_KEY);
      return null;
    }
    const version = sessionVersion;
    try {
      const user = await apiFetch("/api/auth/me");
      if (sessionVersion !== version || storage.get(TOKEN_KEY) !== token) return null;
      storage.set(SESSION_KEY, JSON.stringify(user));
      return user;
    } catch {
      if (sessionVersion === version && storage.get(TOKEN_KEY) === token) authService.logout();
      return null;
    }
  },
  
  restoreSession(): StudentSession | null {
    try {
      const value = JSON.parse(storage.get(SESSION_KEY) || "null");
      if (value && value.id) {
        return value as StudentSession;
      }
    } catch {
      /* Ignore malformed sessions. */
    }
    return null;
  }
};
