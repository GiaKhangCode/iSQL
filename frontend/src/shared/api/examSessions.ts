import {apiFetch} from "@/shared/api/apiClient";

/** A lecturer account that can proctor a class of an examination session. */
export type ProctorOption = {id:string;name:string;email:string};
export type SessionPlan = {
  sessionId:string;examinationId:string;title:string;confirmedAt:string|null;
  students:{id:string;name:string;email:string;studentCode:string|null;classIds:string[]}[];
  classes:{classId:string;studentIds:string[];proctorIds:string[]}[];
};
const base=(id:string)=>`/api/exam-sessions/${encodeURIComponent(id)}`;
export const examSessions = {
  proctors:()=>apiFetch<ProctorOption[]>("/api/exam-sessions/proctors"),
  plan:(id:string)=>apiFetch<SessionPlan>(`${base(id)}/plan`),
  confirm:(id:string)=>apiFetch<SessionPlan>(`${base(id)}/confirm`,{method:"POST"}),
  reopen:(id:string,reason:string)=>apiFetch<SessionPlan>(`${base(id)}/reopen`,{method:"POST",body:JSON.stringify({reason})}),
};
