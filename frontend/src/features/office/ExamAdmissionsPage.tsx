import {useLocation as useManagementLocation} from "react-router-dom";
import {examSessions,type SessionPlan} from '@/shared/api/examSessions';
import {useEffect,useState} from 'react';
import {Link,useParams,useSearchParams} from 'react-router-dom';
import {useLoad} from '@/shared/hooks/useLoad';
import {Dialog,Loading,ErrorState} from '@/shared/ui/ui';
import {teacherService} from '@/shared/api/teacherService';
import {examAdmission,downloadExamCredentials,type ExamCredential} from '@/shared/api/examAdmission';
import '@/styles/exam-admission.css';
import '@/styles/examinations.css';
import { notify } from "@/shared/ui/notify";

type Allocation=SessionPlan;
/** Credentials are exported per class; a student is listed under the first class they belong to in the session ('' = added individually). */
const groupOf=(student:Allocation['students'][number])=>student.classIds[0]??'';
const groupLabel=(id:string)=>id||'Sinh viên tự do';
const groupsOf=(plan:Allocation|null)=>{const ids=new Set((plan?.students||[]).map(groupOf));return [...ids].sort((a,b)=>(a===''?1:0)-(b===''?1:0)||a.localeCompare(b));};
const statusText:Record<ExamCredential['status'],string>={not_issued:'Chưa cấp',issued:'Đã cấp',claimed:'Đã đăng nhập',revoked:'Đã thu hồi'};
const actionText={reissue:'Cấp lại',transfer:'Cho phép đổi máy',revoke:'Thu hồi'} as const;
const actionDone={reissue:'Đã cấp lại tài khoản thi.',transfer:'Đã cho phép đổi máy.',revoke:'Đã thu hồi tài khoản thi.'} as const;
export function ExamAdmissionsPage({embedded=false,examinationId,sessionId,active=true}:{embedded?:boolean;examinationId?:string;sessionId?:string;active?:boolean}={}){
  const managementRoot=useManagementLocation().pathname.startsWith("/admin/")?"/admin":"/office";
  const {examId:routeExamId}=useParams();const examId=examinationId||routeExamId;const [params]=useSearchParams();
  const {data:exam,loading,error}=useLoad(()=>teacherService.getExamination(examId!),[examId]);
  const requestedId=sessionId||params.get('sessionId');const [slotIndex,setSlotIndex]=useState(1);
  useEffect(()=>{const requested=exam?.sessions.find(s=>s.id===requestedId);if(requested)setSlotIndex(requested.index);},[exam,requestedId]);
  const slot=exam?.sessions.find(s=>s.index===slotIndex);
  const [allocation,setAllocation]=useState<Allocation|null>(null);const [rows,setRows]=useState<ExamCredential[]>([]);const [selected,setSelected]=useState<string|null>(null);
  const [fetching,setFetching]=useState(false);const [busy,setBusy]=useState(false);const [failure,setFailure]=useState('');const [version,setVersion]=useState(0);
  const [confirmExport,setConfirmExport]=useState<{classId:string|null;count:number}|null>(null);
  const [pending,setPending]=useState<{row:ExamCredential;action:keyof typeof actionText}|null>(null);const [reason,setReason]=useState('');
  useEffect(()=>{if(!active||!examId||!slot?.id)return;let stale=false;setFetching(true);setFailure('');
    Promise.all([examSessions.plan(slot.id),examAdmission.list(examId,slot.id)]).then(([data,credentials])=>{if(stale)return;setAllocation(data);setRows(credentials);setSelected(current=>{const groups=groupsOf(data);return current!==null&&groups.includes(current)?current:groups[0]??null;});}).catch(e=>{if(!stale)setFailure(e instanceof Error?e.message:'Không thể tải danh sách tài khoản.');}).finally(()=>{if(!stale)setFetching(false);});return()=>{stale=true;};
  },[active,examId,slot?.id,version]);
  async function runExport(classId:string|null,reissue:boolean){if(!examId||!slot?.id||busy)return;setBusy(true);setFailure('');try{await examAdmission.exportClass(examId,slot.id,classId,reissue);notify.success('Đã xuất tài khoản thi.');setConfirmExport(null);setVersion(v=>v+1);}catch(e){notify.fail('Không thể xuất tài khoản.',e);setConfirmExport(null);}finally{setBusy(false);}}
  function requestExport(classId:string|null){const ids=new Set(allocation?.students.filter(s=>classId===null||groupOf(s)===classId).map(s=>s.id));const count=rows.filter(r=>ids.has(r.studentId)&&r.status!=='not_issued').length;if(count)setConfirmExport({classId,count});else void runExport(classId,false);}
  async function manage(){if(!examId||!slot?.id||!pending?.row.id||busy)return;setBusy(true);setFailure('');try{const result=await examAdmission.manage(examId,slot.id,pending.row.id,pending.action,reason.trim());if(typeof result==='string')downloadExamCredentials(result,slot.index);notify.success(actionDone[pending.action]);setPending(null);setReason('');setVersion(v=>v+1);}catch(e){notify.fail('Không thể cập nhật tài khoản.',e);}finally{setBusy(false);}}
  if(loading)return <Loading/>;if(error||!exam)return <ErrorState title="Không thể tải kỳ thi" message={error}/>;
  const groups=groupsOf(allocation);const current=selected!==null&&groups.includes(selected)?selected:null;const members=allocation?.students.filter(s=>groupOf(s)===selected)||[];
  const status=(id:string)=>rows.find(row=>row.studentId===id)?.status||'not_issued';
  const issued=rows.filter(row=>row.status!=='not_issued').length;
  const canExport=Boolean(allocation?.confirmedAt&&!busy&&!fetching);
  return <section className="teacher-page office-admissions-page">
    {!embedded&&<header className="teacher-page-intro office-admissions-intro"><div><Link to={slot?.id?`${managementRoot}/sessions/${slot.id}/edit`:`${managementRoot}/examinations`}>← Quay lại ca thi</Link><h1>{exam.title}</h1><p>Xuất tài khoản thi · {exam.examDate}</p></div></header>}
    {!embedded&&<div className="examination-session-tabs office-admission-session-tabs" role="tablist" aria-label="Ca thi">{exam.sessions.map(s=><button role="tab" aria-selected={slotIndex===s.index} className={slotIndex===s.index?'active':''} key={s.index} disabled={busy} onClick={()=>setSlotIndex(s.index)}><strong>Ca {s.index}</strong><span>{s.starts}–{s.ends}</span></button>)}</div>}
    <section className="office-admissions-card credential-export">
      <div className="credential-export-heading"><div><h2>Xuất toàn bộ ca thi</h2><p className="muted">Tổng sinh viên: {allocation?.students.length||0} · Số lớp: {allocation?.classes.length||0} · Đã cấp: {issued} · Chưa cấp: {rows.length-issued}</p></div><button type="button" className="button primary" disabled={!canExport||!rows.length} onClick={()=>requestExport(null)}>Xuất tất cả tài khoản (.zip)</button></div>
      {!allocation?.confirmedAt&&!fetching&&<p className="office-admissions-notice muted">Nhấn “Xác nhận ca thi” trong danh sách ca thi để chốt ca và bật xuất tài khoản.</p>}
      {allocation?.confirmedAt&&!rows.length&&!fetching&&<p className="office-admissions-notice muted">Ca đã sẵn sàng nhưng chưa có sinh viên đủ điều kiện để cấp tài khoản.</p>}
      <p className="tiny muted">Mật khẩu chỉ xuất hiện trong file tải xuống. Xuất lại sẽ yêu cầu xác nhận cấp mật khẩu mới.</p>
      {failure&&<p className="error" role="alert">{failure}</p>}
      {fetching?<Loading/>:<><h3>Danh sách lớp</h3><div className="credential-export-layout"><div className="credential-export-rooms">{groups.map(group=>{const students=allocation!.students.filter(student=>groupOf(student)===group);const count=students.filter(student=>status(student.id)!=='not_issued').length;return <div className={`credential-room ${group===selected?'is-active':''}`} key={group||'no-class'}><button type="button" className="credential-room-select" onClick={()=>setSelected(group)}><strong>{groupLabel(group)}</strong><small>{students.length} sinh viên · {count}/{students.length} đã cấp</small></button><button type="button" className="button" disabled={!canExport||!students.length} onClick={()=>requestExport(group)}>Xuất CSV</button></div>;})}{!groups.length&&<p className="muted">Chưa có lớp.</p>}</div>
      <div className="credential-room-details">{current!==null?<><div className="credential-export-heading"><h3>{groupLabel(current)} · {members.length} sinh viên</h3><button type="button" className="button" disabled={!canExport||!members.length} onClick={()=>requestExport(current)}>Xuất tài khoản (.csv)</button></div><div className="office-admission-table"><table><thead><tr><th>MSSV</th><th>Tên</th><th>Lớp</th><th>Trạng thái tài khoản</th><th>Thao tác</th></tr></thead><tbody>{members.map(student=>{const row=rows.find(item=>item.studentId===student.id);return <tr key={student.id}><td>{student.studentCode||'—'}</td><td>{student.name}</td><td>{student.classIds.join(', ')||'Sinh viên tự do'}</td><td>{statusText[status(student.id)]}</td><td>{row?.id&&<div className="office-admission-actions">{(Object.keys(actionText) as (keyof typeof actionText)[]).filter(a=>a==='reissue'||(a==='transfer'?row.status==='claimed':row.status!=='revoked')).map(action=><button type="button" className="button" key={action} disabled={busy} onClick={()=>{setPending({row,action});setReason('');}}>{actionText[action]}</button>)}</div>}</td></tr>;})}</tbody></table>{!members.length&&<p className="muted">Lớp chưa có sinh viên.</p>}</div></>:<p className="muted">Chọn lớp để xem danh sách sinh viên.</p>}</div></div></>}
    </section>
    {confirmExport&&<Dialog title="Xác nhận cấp lại mật khẩu" onClose={()=>!busy&&setConfirmExport(null)}><p>Phạm vi xuất có {confirmExport.count} tài khoản đã cấp. Tiếp tục sẽ tạo mật khẩu mới cho các tài khoản này; thông tin trong các file đã xuất trước đó sẽ không còn hiệu lực. Tên đăng nhập cũ sẽ được thay nếu chưa đạt chuẩn.</p><div className="credential-dialog-actions"><button type="button" className="button" disabled={busy} onClick={()=>setConfirmExport(null)}>Hủy</button><button type="button" className="button primary" disabled={busy} onClick={()=>void runExport(confirmExport.classId,true)}>{busy?'Đang xuất…':'Cấp lại và xuất'}</button></div></Dialog>}
    {pending&&<Dialog title={`${actionText[pending.action]} · ${pending.row.student}`} onClose={()=>!busy&&setPending(null)}><label>Lý do<input value={reason} maxLength={120} placeholder="Nhập lý do" onChange={e=>setReason(e.target.value)} disabled={busy}/></label><div className="credential-dialog-actions"><button type="button" className="button" disabled={busy} onClick={()=>setPending(null)}>Hủy</button><button type="button" className="button primary" disabled={busy||reason.trim().length<5} onClick={()=>void manage()}>Xác nhận</button></div></Dialog>}
  </section>;
}
