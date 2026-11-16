import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import {useEffect,useState,lazy,Suspense,type FormEvent} from "react";
import {Link,Navigate,Route,Routes,useLocation,useNavigate} from "react-router-dom";
import {apiFetch,ApiError} from "@/shared/api/apiClient";
import {useAuth} from "@/app/providers/AuthContext";
import {Loading} from "@/shared/ui/ui";
import {parseServerDateTime} from "@/shared/lib/datetime/serverDateTime";
import "@/styles/exam-portal.css";
const Workspace=lazy(()=>import("@/features/workspace/WorkspacePage").then(module=>({default:module.WorkspacePage})));
type Status={sessionId:string;title:string;locked:boolean;violationLocked?:boolean;browserCode:string;opens:string;closes:string;serverTime:string};

export function ExamLoginPage(){
  const {t}=useLanguage();

  const {examLogin,session,logout}=useAuth();
  const navigate=useNavigate();
  const [username,setUsername]=useState("");const [password,setPassword]=useState("");
  const [busy,setBusy]=useState(false);const [error,setError]=useState("");
  const [status,setStatus]=useState<Status|null>(null);
  useEffect(()=>{
    if(!session?.examSessionId){setStatus(null);return;}
    let active=true;let running=false;
    const refresh=async()=>{
      if(running)return;running=true;
      try{const result=await apiFetch<Status>("/api/exam-auth/status");if(active){setStatus(result);setError("");}}
      catch(e){if(active&&e instanceof ApiError&&[401,403].includes(e.status)){setStatus(null);}}
      finally{running=false;}
    };
    void refresh();
    const timer=window.setInterval(()=>void refresh(),2500);
    return()=>{active=false;clearInterval(timer);};
  },[session?.examSessionId]);
  async function submit(event:FormEvent){event.preventDefault();setBusy(true);setError("");
    try{await examLogin(username,password);setPassword("");navigate("/examinations",{replace:true});}
    catch(e){setError(e instanceof Error?e.message:"Không thể đăng nhập dự thi.");}finally{setBusy(false);}}
  return <main className="exam-login"><form onSubmit={submit}><h1><TranslatedText>{t("Exam sign-in")}</TranslatedText></h1><p><TranslatedText>{t("Use the credentials provided by your examination room.")}</TranslatedText></p>
    {session?.examSessionId&&status&&<div role="status" className="exam-login-status">
      <p><TranslatedText>{t("Session code:")}</TranslatedText> <strong>{status.browserCode || "—"}</strong></p>
      <p><TranslatedText>{status.violationLocked?t("Bài thi đã bị khóa do vi phạm quy chế. Vui lòng liên hệ giám thị."):status.locked?t("A proctor needs to check this browser session."):t("Browser session approved.")}</TranslatedText></p>
      {!status.locked&&<Link className="button primary" to="/examinations"><TranslatedText>{t("Resume exam session")}</TranslatedText></Link>}
    </div>}
    {session?.examSessionId&&!status&&<p role="status"><TranslatedText>{t("A proctor needs to check this browser session.")}</TranslatedText> <Link to="/examinations"><TranslatedText>{t("View session status and code")}</TranslatedText></Link></p>}
    {error&&<p role="alert">{error}</p>}
    <label><TranslatedText>{t("Exam credentials")}</TranslatedText><input required autoComplete="username" value={username} disabled={busy} onChange={e=>setUsername(e.target.value)}/></label>
    <label><TranslatedText>{t("Password")}</TranslatedText><input required type="password" autoComplete="current-password" value={password} disabled={busy} onChange={e=>setPassword(e.target.value)}/></label>
    <button className="button primary" disabled={busy}><TranslatedText>{busy?t("Checking…"):t("Exam sign-in")}</TranslatedText></button>
    {session?.examSessionId?<button type="button" className="button" onClick={()=>{logout();navigate('/exam-login',{replace:true});}}><TranslatedText>{t("Switch account")}</TranslatedText></button>:<Link to="/login"><TranslatedText>{t("Regular account sign-in")}</TranslatedText></Link>}
  </form></main>;
}

export function ExamSessionApp(){
  const {t}=useLanguage();

  const {session,logout}=useAuth();const location=useLocation();const navigate=useNavigate();
  const [status,setStatus]=useState<Status|null>(null);const [error,setError]=useState("");
  useEffect(()=>{let active=true;let running=false;
    const refresh=async()=>{if(running)return;running=true;try{const result=await apiFetch<Status>("/api/exam-auth/status");if(active){setStatus(result);setError("");if(result.locked&&location.pathname!=='/exam'&&location.pathname!=='/exam-login'){window.dispatchEvent(new Event('exam-access-locked'));navigate('/exam-login',{replace:true});}}}catch(e){if(active){setError(e instanceof Error?e.message:"Chưa kết nối được máy chủ.");if(e instanceof ApiError&&[401,403].includes(e.status)){window.dispatchEvent(new Event('exam-access-locked'));setStatus(null);if(location.pathname!=='/exam-login')navigate('/exam-login',{replace:true});}}}finally{running=false;}};
    void refresh();const timer=window.setInterval(()=>void refresh(),2000);return()=>{active=false;clearInterval(timer);};
  },[session?.examSessionId,location.pathname,navigate]);
  const sessionId=session?.examSessionId;
  const start=async()=>{try{await apiFetch(`/api/student/activity-attempts/${sessionId}/start`,{method:'POST',body:JSON.stringify({source:'Contests'})});const activity=await apiFetch<{problemList:{id:string}[]}>(`/api/assignments/${sessionId}`);const first=activity.problemList[0];if(!first)throw new Error('Ca thi chưa có bài toán.');navigate(`/workspace/${encodeURIComponent(first.id)}?source=Contests&context=${encodeURIComponent(sessionId!)}`);}catch(e){setError(e instanceof Error?e.message:'Chưa thể vào làm bài.');}};
  const summary=<main className="exam-portal"><header><strong><TranslatedText>{t("iSQL")}</TranslatedText></strong><span><TranslatedText>{t("Exam session")}</TranslatedText></span><button className="button" onClick={()=>{logout();navigate('/exam-login',{replace:true});}}><TranslatedText>{t("Sign out")}</TranslatedText></button></header><section><h1><TranslatedText>{status?.title||t("Your exam session")}</TranslatedText></h1>
    {error&&<p role="alert">{error}</p>}
    {status?<><p><TranslatedText>{t("Browser session code:")}</TranslatedText> <strong>{status.browserCode}</strong></p>{status.locked?<div role="alert"><h2><TranslatedText>{t(status.violationLocked?"Ca thi đã bị khóa do vi phạm":"Exam account locked")}</TranslatedText></h2><p><TranslatedText>{t(status.violationLocked?"Hệ thống đã khóa bài thi do vi phạm quy chế. Vui lòng giữ nguyên máy và báo ngay cho cán bộ coi thi.":"Another browser signed in. Contact your proctor and show this browser session code for verification.")}</TranslatedText></p></div>:<><p><TranslatedText>{t("This browser session is authorized.")}</TranslatedText></p><p><TranslatedText>{t("Starts:")}</TranslatedText> {parseServerDateTime(status.opens)?.toLocaleString('vi-VN')}</p><button className="button primary" disabled={parseServerDateTime(status.serverTime)!<parseServerDateTime(status.opens)!||parseServerDateTime(status.serverTime)!>=parseServerDateTime(status.closes)!} onClick={()=>void start()}><TranslatedText>{t("Enter or resume exam")}</TranslatedText></button></>}</>:<p role="status"><TranslatedText>{t("Checking exam session…")}</TranslatedText></p>}
  </section></main>;
  return <Routes><Route path="/exam" element={summary}/><Route path="/exam-login" element={<><ExamLoginPage/>{error&&<p role="alert" className="exam-login-status">{error}</p>}{status&&<div className="exam-login-status" role="status"><p><TranslatedText>{t("Session code:")}</TranslatedText> <strong>{status.browserCode}</strong></p><p><TranslatedText>{status.locked?t("Waiting for proctor verification."):t("Browser session approved.")}</TranslatedText></p>{!status.locked&&<Link className="button primary" to="/exam"><TranslatedText>{t("Resume exam session")}</TranslatedText></Link>}</div>}</>}/><Route path="/workspace/:problemId" element={status&&!status.locked&&new URLSearchParams(location.search).get("context")===sessionId&&new URLSearchParams(location.search).get("source")==="Contests"?<Suspense fallback={<Loading/>}><Workspace/></Suspense>:status?.locked?<Navigate to="/exam-login" replace/>:<Loading/>}/><Route path="*" element={<Navigate to="/exam" replace/>}/></Routes>;
}
