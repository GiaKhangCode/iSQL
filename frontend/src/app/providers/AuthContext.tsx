import { createContext, useContext, useState, useEffect, type ReactNode } from "react";
import {
  authService,
  type StudentSession,
} from "@/features/auth/authService";
import {apiFetch} from "@/shared/api/apiClient";
type Auth = {
  session: StudentSession | null;
  permissions: string[] | null;
  permissionsError: string;
  login: (email: string, password: string) => Promise<StudentSession>;
  examLogin: (username:string,password:string) => Promise<StudentSession>;
  register: (name: string, email: string, password: string) => Promise<void>;
  logout: () => void;
};
const AuthContext = createContext<Auth | null>(null);
export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState(authService.restoreSession);
  const [access,setAccess]=useState<{key:string;permissions:string[];error:string}|null>(null);
  const accessKey=session?`${session.id}:${session.role}:${session.examSessionId||''}`:'';
  useEffect(()=>{
    if(!session||session.examSessionId){setAccess(null);return;}
    let active=true;let running=false;
    const refresh=async()=>{if(running)return;running=true;try{const value=await apiFetch<{permissions:string[]}>('/api/auth/permissions');if(active)setAccess({key:accessKey,permissions:value.permissions,error:''});}catch(e){if(active)setAccess({key:accessKey,permissions:[],error:e instanceof Error?e.message:'Không tải được quyền truy cập.'});}finally{running=false;}};
    void refresh();const timer=setInterval(()=>void refresh(),30000);window.addEventListener('focus',refresh);
    return()=>{active=false;clearInterval(timer);window.removeEventListener('focus',refresh);};
  },[accessKey]);

  // Background session verify
  useEffect(() => {
    authService.restoreSessionAsync().then(user => {
      if (!user && session && !authService.restoreSession()) setSession(null);
      if (user) setSession(user);
    });
  }, []);

  const auth: Auth = {
    session,
    permissions:session?.examSessionId?[]:access?.key===accessKey?access.permissions:null,
    permissionsError:access?.key===accessKey?access.error:'',
    examLogin: async (username,password)=>{
      const user=await authService.examLogin(username,password);
      setSession(user);
      return user;
    },
    login: async (e, p) => {
      const user = await authService.login(e, p);
      setSession(user);
      return user;
    },
    register: async (n, e, p) =>
      setSession(await authService.register(n, e, p)),
    logout: () => {
      authService.logout();
      setSession(null);
    },
  };
  return <AuthContext.Provider value={auth}>{children}</AuthContext.Provider>;
}
export function useAuth() {
  const auth = useContext(AuthContext);
  if (!auth) throw new Error("AuthProvider is required");
  return auth;
}
