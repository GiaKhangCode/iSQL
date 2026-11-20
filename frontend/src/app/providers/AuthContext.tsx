import { createContext, useContext, useState, useEffect, type ReactNode } from "react";
import {
  authService,
  type StudentSession,
} from "@/features/auth/authService";
import {apiFetch} from "@/shared/api/apiClient";
import {normalizeAreas, pickArea, readPreferredArea, writePreferredArea, type Area} from "@/shared/lib/access/areas";
type Auth = {
  session: StudentSession | null;
  permissions: string[] | null;
  /** Khu vực giao diện được vào (thuộc tính của các vai trò); null = chưa tải xong. */
  areas: Area[] | null;
  /** Khu vực dùng làm trang chủ: lựa chọn đã lưu nếu còn hợp lệ, ngược lại theo thứ tự ưu tiên. */
  preferredArea: Area | null;
  isSuperAdmin: boolean;
  permissionsError: string;
  /** Ghi nhớ khu vực người dùng vừa chọn/vừa vào (chỉ ảnh hưởng trang chủ). */
  chooseArea: (area: Area) => void;
  login: (email: string, password: string) => Promise<StudentSession>;
  examLogin: (username:string,password:string) => Promise<StudentSession>;
  register: (name: string, email: string, password: string) => Promise<void>;
  logout: () => void;
};
type Access={key:string;permissions:string[];areas:Area[];isSuperAdmin:boolean;error:string};
const AuthContext = createContext<Auth | null>(null);
export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState(authService.restoreSession);
  const [access,setAccess]=useState<Access|null>(null);
  const [chosen,setChosen]=useState<{userId:string;area:Area}|null>(null);
  const accessKey=session?`${session.id}:${session.account_type}:${session.examSessionId||''}`:'';
  useEffect(()=>{
    if(!session||session.examSessionId){setAccess(null);return;}
    let active=true;let running=false;
    const refresh=async()=>{if(running)return;running=true;try{const value=await apiFetch<{permissions:string[];areas?:string[];isSuperAdmin?:boolean}>('/api/auth/permissions');if(active)setAccess({key:accessKey,permissions:value.permissions,areas:normalizeAreas(value.areas),isSuperAdmin:Boolean(value.isSuperAdmin),error:''});}catch(e){if(active)setAccess({key:accessKey,permissions:[],areas:[],isSuperAdmin:false,error:e instanceof Error?e.message:'Không tải được quyền truy cập.'});}finally{running=false;}};
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

  const current=access?.key===accessKey?access:null;
  const areas=session?.examSessionId?[]:current?.areas??null;
  const stored=session?.id&&chosen?.userId===session.id?chosen.area:readPreferredArea(session?.id);

  const auth: Auth = {
    session,
    permissions:session?.examSessionId?[]:current?.permissions??null,
    areas,
    preferredArea:areas?pickArea(areas,stored):null,
    isSuperAdmin:current?.isSuperAdmin??false,
    permissionsError:current?.error??'',
    chooseArea:(area)=>{
      if(!session?.id)return;
      writePreferredArea(session.id,area);
      setChosen({userId:session.id,area});
    },
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
