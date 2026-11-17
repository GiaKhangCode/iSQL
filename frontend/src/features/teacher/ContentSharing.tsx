import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import {ProblemMarkdown} from "@/shared/components/ProblemMarkdown";
import {useEffect,useState,type FormEvent} from "react";
import {Link,useNavigate,useParams} from "react-router-dom";
import {apiFetch} from "@/shared/api/apiClient";
import {Dialog} from "@/shared/ui/ui";
import {useAuth} from "@/app/providers/AuthContext";
type Kind='problem'|'library';
type Recipient={id:string;name:string;email:string;canEdit:boolean};
export function ShareContentButton({kind,id}:{kind:Kind;id:string}){
  const {t}=useLanguage();

  let permissions: string[] | null = null;
  try {
    // eslint-disable-next-line react-hooks/rules-of-hooks
    permissions = useAuth().permissions;
  } catch {
    permissions = null;
  }
  const [open,setOpen]=useState(false);
  const [rows,setRows]=useState<Recipient[]>([]);const [email,setEmail]=useState('');const [edit,setEdit]=useState(false);
  const [error,setError]=useState('');const [busy,setBusy]=useState(false);const [version,setVersion]=useState(0);
  const [suggestions,setSuggestions]=useState<{id:string;name:string;email:string}[]>([]);const [chosen,setChosen]=useState('');const [searching,setSearching]=useState(false);
  useEffect(()=>{if(!open)return;let active=true;setRows([]);apiFetch<Recipient[]>(`/api/sharing/${kind}/${id}`).then(data=>{if(active){setRows(data);setError('');}}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[open,kind,id,version]);
  useEffect(()=>{if(!open||chosen===email||email.trim().length<2){setSuggestions([]);setSearching(false);return;}let active=true;setSearching(true);
    const timer=setTimeout(()=>{apiFetch<{id:string;name:string;email:string}[]>(`/api/sharing/recipients/search?kind=${kind}&q=${encodeURIComponent(email.trim())}`).then(data=>{if(active)setSuggestions(data);}).catch(e=>{if(active){setSuggestions([]);setError(e.message);}}).finally(()=>{if(active)setSearching(false);});},250);
    return()=>{active=false;clearTimeout(timer);};
  },[open,email,chosen,kind]);
  if(!permissions?.includes(kind==='problem'?'problem.share':'library.share'))return null;
  const save=async(e:FormEvent)=>{e.preventDefault();setBusy(true);setError('');try{await apiFetch(`/api/sharing/${kind}/${id}`,{method:'PUT',body:JSON.stringify({email,can_edit:edit})});setEmail('');setChosen('');setVersion(v=>v+1);}catch(e){setError(e instanceof Error?e.message:'Không chia sẻ được.');}finally{setBusy(false);}};
  const revoke=async(recipient:Recipient)=>{setBusy(true);try{await apiFetch(`/api/sharing/${kind}/${id}/${recipient.id}`,{method:'DELETE'});setVersion(v=>v+1);}catch(e){setError(e instanceof Error?e.message:'Không thu hồi được.');}finally{setBusy(false);}};
  return <><button type="button" className="button" onClick={()=>setOpen(true)}><TranslatedText>{t("Share")}</TranslatedText></button>{open&&<Dialog title={t("Share with an instructor")} onClose={()=>{if(!busy)setOpen(false);}}><p><TranslatedText>{t("Recipients can preview and make their own copies. Enable editing only to allow changes to the original.")}</TranslatedText></p>{error&&<p role="alert">{error}</p>}<form onSubmit={save} className="admin-dialog-form"><label><TranslatedText>{t("Instructor email")}</TranslatedText><input type="text" autoComplete="off" required value={email} disabled={busy} onChange={e=>{setEmail(e.target.value);setChosen('');setError('');}} aria-autocomplete="list" aria-expanded={suggestions.length>0}/>{email.trim().length>=2&&chosen!==email&&<div className="share-suggestions" role="listbox" aria-label={t("Instructor suggestions")}>{searching?<p><TranslatedText>{t("Searching instructors…")}</TranslatedText></p>:suggestions.length?suggestions.map(person=><button type="button" role="option" aria-selected="false" key={person.id} onClick={()=>{setEmail(person.email);setChosen(person.email);setSuggestions([]);}}><strong>{person.name}</strong><small>{person.email}</small></button>):<p><TranslatedText>{t("No eligible instructors found.")}</TranslatedText></p>}</div>}</label><label className="access-permission"><input type="checkbox" checked={edit} disabled={busy} onChange={e=>setEdit(e.target.checked)}/><TranslatedText>{t("Allow editing the original")}</TranslatedText></label><button className="button primary" disabled={busy||!chosen||chosen!==email}><TranslatedText>{t("Save sharing")}</TranslatedText></button></form><ul>{rows.map(row=><li key={row.id}>{row.name} · {row.email} · <TranslatedText>{row.canEdit?t("Can edit the original"):t("Preview and copy")}</TranslatedText> <button type="button" className="button" disabled={busy} onClick={()=>void revoke(row)}><TranslatedText>{t("Revoke")}</TranslatedText></button></li>)}</ul></Dialog>}</>;
}
type Shared={id:string;kind:Kind;title:string;owner:string;canEdit:boolean};
export function SharedContentPage(){
  const {t}=useLanguage();

  const navigate=useNavigate();
  const [preview,setPreview]=useState<{title:string;statement:string;schemaSql:string;seedSql:string}|null>(null);
  const show=async(row:Shared)=>{setBusy(true);try{setPreview(await apiFetch(`/api/sharing/${row.kind}/${row.id}/preview`));}catch(e){setError(e instanceof Error?e.message:'Không xem được nội dung.');}finally{setBusy(false);}};
  const [rows,setRows]=useState<Shared[]>([]);const [error,setError]=useState('');const [busy,setBusy]=useState(false);
  const [notice,setNotice]=useState('');
  useEffect(()=>{let active=true;apiFetch<Shared[]>('/api/sharing').then(data=>{if(active)setRows(data);}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[]);
  const copy=async(row:Shared)=>{setBusy(true);setError('');try{const value=await apiFetch<{id:string}>(`/api/sharing/${row.kind}/${row.id}/copy`,{method:'POST'});setNotice(`Đã tạo bản sao riêng: ${row.title}.`);if(row.kind==='problem')navigate(`/teacher/problems/${value.id}/edit`);}catch(e){setError(e instanceof Error?e.message:'Không sao chép được.');}finally{setBusy(false);}};
  return <section className="teacher-page"><h1><TranslatedText>{t("Shared with me")}</TranslatedText></h1>{error&&<p role="alert">{error}</p>}{notice&&<p role="status">{notice}</p>}<table className="teacher-table"><thead><tr><th><TranslatedText>{t("Content")}</TranslatedText></th><th><TranslatedText>{t("Shared by")}</TranslatedText></th><th><TranslatedText>{t("Permission")}</TranslatedText></th><th><TranslatedText>{t("Actions")}</TranslatedText></th></tr></thead><tbody>{rows.map(row=><tr key={`${row.kind}:${row.id}`}><td>{row.title}<small><TranslatedText>{row.kind==='problem'?t("Problem"):t("Database")}</TranslatedText></small></td><td>{row.owner}</td><td><TranslatedText>{row.canEdit?t("Can edit the original"):t("Preview and copy")}</TranslatedText></td><td><button className="button" type="button" disabled={busy} onClick={()=>void copy(row)}><TranslatedText>{t("Make your own copy")}</TranslatedText></button><button type="button" className="button" disabled={busy} onClick={()=>void show(row)}><TranslatedText>{t("Preview content")}</TranslatedText></button>{row.kind==='problem'&&row.canEdit&&<Link className="button" to={`/teacher/problems/${row.id}/edit`}><TranslatedText>{t("Edit original")}</TranslatedText></Link>}{row.kind==='library'&&row.canEdit&&<Link className="button" to="/teacher/database-library"><TranslatedText>{t("Edit in the library")}</TranslatedText></Link>}</td></tr>)}{!rows.length&&<tr><td colSpan={4}><TranslatedText>{t("No content has been shared with you yet.")}</TranslatedText></td></tr>}</tbody></table>{preview&&<Dialog title={preview.title} onClose={()=>setPreview(null)}><ProblemMarkdown>{preview.statement}</ProblemMarkdown><h3><TranslatedText>{t("Database structure")}</TranslatedText></h3><pre>{preview.schemaSql}</pre><h3><TranslatedText>{t("Initial data")}</TranslatedText></h3><pre>{preview.seedSql}</pre></Dialog>}</section>;
}

export function SharedPreviewPage({kind}:{kind:Kind}){
  const {t}=useLanguage();const {id}=useParams();const navigate=useNavigate();
  const [content,setContent]=useState<{title:string;statement:string;schemaSql:string;seedSql:string;canEdit:boolean}|null>(null);
  const [error,setError]=useState('');const [busy,setBusy]=useState(false);
  useEffect(()=>{let active=true;setContent(null);setError('');apiFetch<{title:string;statement:string;schemaSql:string;seedSql:string;canEdit:boolean}>(`/api/sharing/${kind}/${encodeURIComponent(id||'')}/preview`).then(data=>{if(active)setContent(data);}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[id,kind]);
  const save=async()=>{setBusy(true);setError('');try{const result=await apiFetch<{id:string}>(`/api/sharing/${kind}/${encodeURIComponent(id||'')}/copy`,{method:'POST'});navigate(kind==='problem'?`/teacher/problems/${result.id}/edit`:'/teacher/database-library');}catch(e){setError(e instanceof Error?e.message:'Không lưu được nội dung.');}finally{setBusy(false);}};
  return <section className="teacher-page"><h1><TranslatedText>{t("Preview shared content")}</TranslatedText></h1>{error&&<p role="alert">{error}</p>}{content?<div className="teacher-list-detail-layout shared-preview-layout"><aside className="teacher-list-detail-panel"><h2>{content.title}</h2><button type="button" className="button primary" disabled={busy} onClick={()=>void save()}><TranslatedText>{t("Save this content")}</TranslatedText></button>{content.canEdit&&<p><Link className="button" to={kind==='problem'?`/teacher/problems/${id}/edit`:'/teacher/database-library'}><TranslatedText>{t("Edit original")}</TranslatedText></Link></p>}</aside><article className="teacher-list-detail-panel"><ProblemMarkdown>{content.statement}</ProblemMarkdown>{content.schemaSql&&<><h3><TranslatedText>{t("Database structure")}</TranslatedText></h3><pre>{content.schemaSql}</pre></>}{content.seedSql&&<><h3><TranslatedText>{t("Initial data")}</TranslatedText></h3><pre>{content.seedSql}</pre></>}</article></div>:!error&&<p role="status"><TranslatedText>{t("Loading…")}</TranslatedText></p>}</section>;
}
