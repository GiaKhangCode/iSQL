export type ExamSessionPlan = {
  id?: string | null; examDate?: string; index: number; sessionNumber?: number; title?: string; starts: string; ends: string; classIds: string[];
  studentEmails: string[]; problems?: any[]; proctors: string[]; room: string; capacity: number | null;
  /** class id -> lecturer (user) ids who proctor that class in this session. */
  classProctors?: Record<string, string[]>;
  eligibleStudents?: number; published?: boolean;
};
export type ExaminationPlan = { examDate: string; sessions: ExamSessionPlan[]; expectedRevision?: number };
export type Examination = ExaminationPlan & { id: string; title: string; revision: number; createdAt: string; timezone: "Asia/Ho_Chi_Minh"; template: any };
export const SESSION_HOURS = [["07:30","09:00"],["09:30","11:00"],["13:30","15:00"],["15:30","17:00"]] as const;
export const DEFAULT_SESSION_HOURS = [
  { slotNum: 1, label: "Ca 1", starts: "07:30", ends: "09:00" },
  { slotNum: 2, label: "Ca 2", starts: "09:30", ends: "11:00" },
  { slotNum: 3, label: "Ca 3", starts: "13:30", ends: "15:00" },
  { slotNum: 4, label: "Ca 4", starts: "15:30", ends: "17:00" },
];
export function formatDisplayDate(dateStr?: string): string {
  if (!dateStr) return "";
  const parts = dateStr.slice(0, 10).split("-");
  if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
  return dateStr;
}
export function nextExamDate(date:string, days=1) {
  const value=new Date(`${date}T12:00:00Z`);
  value.setUTCDate(value.getUTCDate()+days);
  return value.toISOString().slice(0,10);
}
export function initialExaminationPlan(now=new Date()): ExaminationPlan {
  const today = new Intl.DateTimeFormat("en-CA", {timeZone:"Asia/Ho_Chi_Minh",year:"numeric",month:"2-digit",day:"2-digit"}).format(now);
  return {examDate:nextExamDate(today),sessions:[]};
}
export function appendExaminationSession(plan:ExaminationPlan):ExaminationPlan {
  const last=plan.sessions[plan.sessions.length-1];
  const index=(last?.index ?? 0)+1;
  const examDate=last ? nextExamDate(last.examDate || plan.examDate,last.index%4===0?1:0) : initialExaminationPlan().examDate;
  const [starts,ends]=SESSION_HOURS[(index-1)%4];
  return {...plan,examDate:plan.sessions[0]?.examDate || (last?plan.examDate:examDate),sessions:[...plan.sessions,{id:null,index,sessionNumber:((index-1)%4)+1,examDate,starts,ends,classIds:[],studentEmails:[],problems:[],proctors:[],classProctors:{},room:"",capacity:null}]};
}
export function sessionNumber(index:number) { return (index-1)%4+1; }
export function sessionWeekday(date:string, locale:string) {
  const day=new Date(`${date}T12:00:00Z`).getUTCDay();
  return locale.startsWith("vi") ? (day===0?"Chủ nhật":`Thứ ${day+1}`) : new Intl.DateTimeFormat(locale,{weekday:"long",timeZone:"UTC"}).format(new Date(`${date}T12:00:00Z`));
}
export function distributeClasses(plan:ExaminationPlan, classes:string[]):ExaminationPlan {
  const unique=[...new Set(classes)];
  let cursor=0;
  return {...plan,sessions:plan.sessions.map((session,index)=>{
    const size=Math.floor(unique.length/plan.sessions.length)+(index<unique.length%plan.sessions.length?1:0);
    const classIds=unique.slice(cursor,cursor+size);cursor+=size;
    return {...session,classIds};
  })};
}
export const parseStudentEmails = (value:string) => [...new Set(value.split(/[\s,;]+/).map(email=>email.trim().toLowerCase()).filter(Boolean))];
export const validStudentEmail = (email:string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && email.length<=255;
export const validStudentIdentifier = (value:string) => /^[0-9]{6,12}$/.test(value) || validStudentEmail(value);
export function examinationSchedule(plan:ExaminationPlan) {
  const first=plan.sessions[0],last=plan.sessions[plan.sessions.length-1];
  return {opens:first?`${first.examDate || plan.examDate}T${first.starts}:00`:"",closes:last?`${last.examDate || plan.examDate}T${last.ends}:00`:""};
}
