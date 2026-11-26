import { createContext, useContext, useState, useEffect, type ReactNode } from "react";
import {
  authService,
  type StudentSession,
} from "@/features/auth/authService";
import {apiFetch} from "@/shared/api/apiClient";
import {buildAreas, pickArea, readPreferredArea, writePreferredArea, type AccessRole, type Area} from "@/shared/lib/access/areas";
type Auth = {
  session: StudentSession | null;
  permissions: string[] | null;
  /** Khu vực giao diện = mỗi vai trò có tab (tên khu vực là tên vai trò), thêm "extra" khi có tab chỉ mở được nhờ quyền gán riêng; null = chưa tải xong. */
  areas: Area[] | null;
  /** Khu vực dùng làm trang chủ: lựa chọn đã lưu nếu còn tồn tại, ngược lại khu vực đầu tiên. */
  preferredArea: Area | null;
  permissionsError: string;
  /** Ghi nhớ khu vực người dùng vừa chọn/vừa vào (chỉ ảnh hưởng trang chủ). */
  chooseArea: (areaId: string) => void;
  login: (email: string, password: string) => Promise<StudentSession>;
  examLogin: (username:string,password:string) => Promise<StudentSession>;
  logout: () => void;
};
type Access={key:string;permissions:string[];roles:AccessRole[];direct:string[];error:string};
const AuthContext = createContext<Auth | null>(null);
export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState(authService.restoreSession);
  const [access,setAccess]=useState<Access|null>(null);
  const [chosen,setChosen]=useState<{userId:string;areaId:string}|null>(null);
  const accessKey=session?`${session.id}:${session.account_type}:${session.examSessionId||''}`:'';
  useEffect(()=>{
    if(!session||session.examSessionId){setAccess(null);return;}
    let active=true;let running=false;
    const refresh=async()=>{if(running)return;running=true;try{const value=await apiFetch<{permissions:string[];roles?:AccessRole[];directPermissions?:string[]}>('/api/auth/permissions');if(active)setAccess({key:accessKey,permissions:value.permissions,roles:Array.isArray(value.roles)?value.roles:[],direct:Array.isArray(value.directPermissions)?value.directPermissions:[],error:''});}catch(e){if(active)setAccess({key:accessKey,permissions:[],roles:[],direct:[],error:e instanceof Error?e.message:'Không tải được quyền truy cập.'});}finally{running=false;}};
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
  // Tài khoản thi tạm không có vai trò nên không có khu vực.
  const areas:Area[]|null=session?.examSessionId?[]:current?buildAreas(current.roles,current.direct):null;
  const stored=session?.id&&chosen?.userId===session.id?chosen.areaId:readPreferredArea(session?.id);

  const auth: Auth = {
    session,
    permissions:session?.examSessionId?[]:current?.permissions??null,
    areas,
    preferredArea:areas?pickArea(areas,stored):null,
    permissionsError:current?.error??'',
    chooseArea:(areaId)=>{
      if(!session?.id)return;
      writePreferredArea(session.id,areaId);
      setChosen({userId:session.id,areaId});
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
