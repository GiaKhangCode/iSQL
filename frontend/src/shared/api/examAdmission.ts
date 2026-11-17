import {apiFetch} from "@/shared/api/apiClient";
import {storage} from "@/shared/api/storage";

export type ExamCredential = {id:string|null;studentId:string;student:string;email:string;username:string|null;status:"not_issued"|"issued"|"claimed"|"revoked";generation:number;claimedAt:string|null};
const path=(exam:string,session:string)=>`/api/examinations/${encodeURIComponent(exam)}/sessions/${encodeURIComponent(session)}/credentials`;
export const examAdmission = {
  issueAll:(exam:string,prefix:string,suffix:string)=>apiFetch<string>(`/api/examinations/${encodeURIComponent(exam)}/credentials`,{method:"POST",body:JSON.stringify({prefix,suffix})}),
  list:(exam:string,session:string)=>apiFetch<ExamCredential[]>(path(exam,session)),
  issue:(exam:string,session:string,prefix:string,suffix:string)=>apiFetch<string>(path(exam,session),{method:"POST",body:JSON.stringify({prefix,suffix})}),
  manage:(exam:string,session:string,id:string,action:"revoke"|"reissue"|"transfer",reason:string)=>apiFetch<string|{ok:boolean}>(`${path(exam,session)}/${encodeURIComponent(id)}`,{method:"POST",body:JSON.stringify({action,reason})}),
  unlock:(activity:string,username:string,password:string)=>apiFetch<{admissionUnlocked:boolean;serverTime:string}>(`/api/exam-admission/${encodeURIComponent(activity)}/unlock`,{method:"POST",body:JSON.stringify({username,password})}),
  async exportClass(exam:string,session:string,classId:string|null,reissue:boolean){
    const token=storage.get("sql-practice:access-token");
    const response=await fetch(`${import.meta.env.VITE_API_URL||""}${path(exam,session)}-export`,{method:"POST",credentials:"include",headers:{"Content-Type":"application/json",...(token?{Authorization:`Bearer ${token}`}:{})},body:JSON.stringify({classId,reissue})});
    if(!response.ok){const data=await response.json().catch(()=>null);throw new Error(data?.detail||"Không thể xuất tài khoản thi.");}
    const blob=await response.blob();const filename=/filename="([^"]+)"/.exec(response.headers.get("Content-Disposition")||"")?.[1]||"tai-khoan-thi";
    const url=URL.createObjectURL(blob);const anchor=document.createElement("a");anchor.href=url;anchor.download=filename;anchor.click();window.setTimeout(()=>URL.revokeObjectURL(url),1000);
  },
};

export function downloadExamCredentials(csv:string,session?:number) {
  const url=URL.createObjectURL(new Blob(["\uFEFF",csv.replace(/^\uFEFF/,"")],{type:"text/csv;charset=utf-8"}));
  const anchor=document.createElement("a");anchor.href=url;anchor.download=session?`thong-tin-du-thi-ca-${session}.csv`:"thong-tin-du-thi.csv";anchor.click();
  window.setTimeout(()=>URL.revokeObjectURL(url),1000);
}
