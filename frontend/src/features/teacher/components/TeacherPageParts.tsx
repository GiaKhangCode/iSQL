import { LibraryEditorTabs } from "@/features/teacher/library/LibraryEditorTabs";
import { teacherService } from "@/shared/api/teacherService";
import type { TeacherClassMember } from "@/data/teacherTypes";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { ChevronDown, Search, X } from "lucide-react";
import { Loading } from "@/shared/ui/ui";
import { SlidingDisclosure } from "@/shared/ui/SlidingDisclosure";

export function TeacherPageIntro({
  title,
  context,
  children,
  compact = false,
}: {
  title: string;
  context: string;
  children?: ReactNode;
  compact?: boolean;
}) {
  const { t: tr } = useLanguage("teacher");
  return (
    <div className={`teacher-page-intro${compact ? " page-list-intro" : ""}`}>
      <div>
        <h1 className={compact ? "sr-only" : undefined}><TranslatedText>{title}</TranslatedText></h1>
        {context && <p><TranslatedText>{tr(context)}</TranslatedText></p>}
      </div>
      {children && <div className="teacher-page-actions">{children}</div>}
    </div>
  );
}

export function TeacherSectionTitle({
  title,
  children,
}: {
  title: string;
  children?: ReactNode;
}) {
  return (
    <div className="teacher-section-title">
      <h2><TranslatedText>{title}</TranslatedText></h2>
      {children}
    </div>
  );
}

export function TeacherField({
  label,
  children,
  className = "",
}: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  const { t: tr } = useLanguage("teacher");
  return (
    <label className={`teacher-field ${className}`}>
      <span><TranslatedText>{tr(label)}</TranslatedText></span>
      {children}
    </label>
  );
}

export function ClassPicker({
  selected,
  onChange,
  classes,
  loading,
  error,
  actions,
  searchActions,
  expanded,
  onExpandedChange,
  onPanelClosed,
  hideTrigger=false,
  individualStudentsPanel,
  individualMode=false,
  onIndividualModeChange,
  individualCount=0,
  selectedIndividualsPanel,
  panelId,
  disabledClassIds=[],
  classNotices={},
  conflictStudentEmails=[],
  conflictStudentIds=[],
  conflictNotice="",
}: {
  selected: string[];
  onChange: (next: string[]) => void;
  classes: { id: string; course?: string; students?: number; practice_class?: string; practiceClass?: string; lecturer?: string; practice_lecturer?: string }[];
  disabledClassIds?:string[];
  classNotices?:Record<string,string>;
  conflictStudentEmails?: string[];
  conflictStudentIds?: string[];
  conflictNotice?: string;
  loading: boolean;
  error: string;
  actions?: ReactNode;
  searchActions?: ReactNode;
  expanded?: boolean;
  onExpandedChange?: (open:boolean)=>void;
  onPanelClosed?: ()=>void;
  hideTrigger?:boolean;
  individualStudentsPanel?:ReactNode;
  individualMode?:boolean;
  onIndividualModeChange?:(value:boolean)=>void;
  individualCount?:number;
  selectedIndividualsPanel?:ReactNode;
  panelId?:string;
}) {
  const { t: tr } = useLanguage("teacher");
  const [localOpen, setLocalOpen] = useState(false);
  const open=expanded ?? localOpen;
  const setOpen=(value:boolean | ((current:boolean)=>boolean))=>{const next=typeof value==="function"?value(open):value;setLocalOpen(next);onExpandedChange?.(next);};
  const [search,setSearch]=useState("");
  const generatedId = useId();
  const optionsId=panelId || generatedId;
  const [focusedClass,setFocusedClass]=useState("");
  const viewedClass=selected.includes(focusedClass)?focusedClass:selected[0] || "";
  const [members,setMembers]=useState<TeacherClassMember[]>([]);
  const [membersLoading,setMembersLoading]=useState(false);
  const [membersError,setMembersError]=useState("");
  const [membersRetry,setMembersRetry]=useState(0);
  useEffect(()=>{
    setMembers([]);setMembersError("");
    if(!open || !viewedClass){setMembersLoading(false);return;}
    let active=true;setMembersLoading(true);
    Promise.resolve().then(()=>teacherService.getClassMembers(viewedClass)).then(data=>{if(active)setMembers(data);})
      .catch(error=>{if(active)setMembersError(error instanceof Error?error.message:tr("Could not load students."));})
      .finally(()=>{if(active)setMembersLoading(false);});
    return ()=>{active=false;};
  },[open,viewedClass,membersRetry]);
  const visible=classes.filter(item=>`${item.id} ${item.course || ""}`.toLowerCase().includes(search.trim().toLowerCase()));

  const browserRef=useRef<HTMLDivElement>(null);
  const panelPositions=useRef(new Map<string,number>());
  const switchMode=(value:boolean)=>{
    panelPositions.current=new Map(Array.from(browserRef.current?.querySelectorAll<HTMLElement>('[data-audience-panel]') || []).map(node=>[node.dataset.audiencePanel!,node.getBoundingClientRect().left]));
    onIndividualModeChange?.(value);
  };
  useLayoutEffect(()=>{
    if(!window.matchMedia("(prefers-reduced-motion: reduce)").matches){
      browserRef.current?.querySelectorAll<HTMLElement>('[data-audience-panel]').forEach(node=>{
        const old=panelPositions.current.get(node.dataset.audiencePanel!);
        if(old!==undefined){const dx=old-node.getBoundingClientRect().left;if(Math.abs(dx)>1)node.animate?.([{transform:`translateX(${dx}px)`,opacity:.65},{transform:'translateX(0)',opacity:1}],{duration:300,easing:'cubic-bezier(.2,.8,.2,1)'});}
      });
    }
    panelPositions.current.clear();
  },[individualMode]);
  const [selectedSummary,setSelectedSummary]=useState<"classes"|"students">("classes");
  useEffect(()=>{if(individualMode || !individualCount)setSelectedSummary("classes");},[individualMode,individualCount]);
  const selectedPanel=(
          <section key="chosen-classes" data-audience-panel="classes" className="teacher-class-browser-column" aria-label={tr("Chosen classes")}>
            <header className={individualCount>0 && !individualMode?"teacher-audience-tabs":""}>{individualCount>0 && !individualMode?<LibraryEditorTabs tabs={[{id:"classes",label:tr("Chosen classes")},{id:"students",label:tr("Individual students")}]} value={selectedSummary} onChange={setSelectedSummary} label={tr("Selected participants")} prefix={`${optionsId}-selected`} panelId={`${optionsId}-selected-list`} />:<><h3><TranslatedText>{tr("Chosen classes")}</TranslatedText></h3><span>{selected.length}</span></>}</header>
            <div id={`${optionsId}-selected-list`} className="teacher-selected-class-list" aria-disabled={individualMode || undefined}>
              {selectedSummary==="students" && !individualMode?selectedIndividualsPanel:<>

              {selected.map(id=><div key={id} className={`teacher-selected-class-row${viewedClass===id?" is-current":""}`}>
                <button type="button" className="teacher-selected-class-view" disabled={individualMode} aria-pressed={viewedClass===id} onClick={()=>setFocusedClass(id)}><b>{classes.find(item=>item.id===id)?.course || id}</b><small>{id}</small></button>
                <button type="button" className="icon-button" disabled={individualMode} aria-label={tr("Remove {value0}",{value0:id})} onClick={()=>onChange(selected.filter(value=>value!==id))}><X size={14} aria-hidden="true" /></button>
              </div>)}
              {!selected.length && <p className="teacher-class-browser-empty"><TranslatedText>{tr("Select classes from the list on the left.")}</TranslatedText></p>}
              </>}
            </div>
          </section>
  );
  const studentPanel=(
          <section key="student-panel" data-audience-panel="students" className="teacher-class-browser-column" aria-label={tr(individualMode?"Individual students":"Class students")} aria-busy={!individualMode && membersLoading}>
            <header><h3><TranslatedText>{tr(individualMode?"Individual students":"Class students")}</TranslatedText></h3>{!individualMode && viewedClass && !membersLoading && !membersError && <span>{members.length}</span>}</header>
            {!individualMode && viewedClass && <p className="teacher-class-roster-title">{classes.find(item=>item.id===viewedClass)?.course || viewedClass}</p>}
            <div className={`teacher-class-roster${individualMode?" is-individual":""}`}>
              {individualMode ? selectedIndividualsPanel : membersLoading ? <Loading variant="inline" rows={3} label={tr("Loading students…")} /> : membersError ? <p className="teacher-class-browser-empty" role="alert">{membersError} <button type="button" className="text-button" onClick={()=>setMembersRetry(value=>value+1)}><TranslatedText>{tr("Try again")}</TranslatedText></button></p> : !viewedClass ? <p className="teacher-class-browser-empty"><TranslatedText>{tr("Choose a selected class to view its students.")}</TranslatedText></p> : !members.length ? <p className="teacher-class-browser-empty"><TranslatedText>{tr("This class has no students yet.")}</TranslatedText></p> : members.map(member => {
                const memberEmail = (member.email || "").toLowerCase();
                const memberId = member.id || "";
                const memberMssv = memberEmail.split("@")[0];
                const isConflict = Boolean(
                  (conflictStudentEmails && conflictStudentEmails.length > 0 && (conflictStudentEmails.includes(memberEmail) || conflictStudentEmails.includes(memberId.toLowerCase()))) ||
                  (conflictStudentIds && conflictStudentIds.length > 0 && (conflictStudentIds.includes(memberId) || conflictStudentIds.includes(memberMssv)))
                );
                return (
                  <article
                    key={member.id}
                    className={`teacher-class-member-item${isConflict ? " is-conflict-student has-error" : ""}`}
                    data-tooltip={isConflict ? (conflictNotice || "Sinh viên này đã có kỳ thi khác trùng giờ") : undefined}
                    title={isConflict ? (conflictNotice || "Sinh viên này đã có kỳ thi khác trùng giờ") : undefined}
                  >
                    <span className={`teacher-class-member-avatar${isConflict ? " is-conflict" : ""}`} aria-hidden="true">
                      {isConflict ? "!" : member.name.slice(0, 1).toUpperCase()}
                    </span>
                    <div>
                      <b>{member.name}</b>
                      <small>{member.email || member.id}</small>
                    </div>
                    {isConflict && (
                      <span className="teacher-conflict-tag" title="Trùng lịch thi">
                        Trùng lịch
                      </span>
                    )}
                  </article>
                );
              })}
            </div>
          </section>
  );
  return (
    <div className="teacher-class-picker">
      {(!hideTrigger || actions) && <div className="teacher-class-chips">
        {!hideTrigger && <button
          type="button"
          className="teacher-add-class"
          aria-expanded={open}
          aria-controls={optionsId}
          onClick={() => setOpen((value) => !value)}
        >
          <TranslatedText>{tr("Selected classes")}</TranslatedText>
          <ChevronDown size={13} aria-hidden="true" />
        </button>}
        {actions}
      </div>}
      <SlidingDisclosure open={open} onClosed={onPanelClosed}>
        <div id={optionsId} className="teacher-class-options" onKeyDown={event => { if (event.key === "Escape") { setOpen(false); event.currentTarget.parentElement?.querySelector<HTMLButtonElement>(".teacher-add-class")?.focus(); } }}>
          <div ref={browserRef} className="teacher-class-browser">
          <section className="teacher-class-browser-column" aria-label={tr("Available classes")}>
          <header className="teacher-audience-tabs">{individualStudentsPanel ? <LibraryEditorTabs value={individualMode?"students":"classes"} onChange={value=>switchMode(value==="students")} tabs={[{id:"classes",label:tr("Available classes")},{id:"students",label:tr("Individual students")}]} label={tr("Participant selection")} prefix={optionsId} panelId={`${optionsId}-list`} /> : <h3><TranslatedText>{tr("Available classes")}</TranslatedText></h3>}</header>
          <div id={`${optionsId}-list`} className="teacher-audience-tab-content" role={individualStudentsPanel?"tabpanel":undefined} aria-labelledby={individualStudentsPanel?`${optionsId}-${individualMode?"students":"classes"}`:undefined}>
          {individualMode ? <div className="teacher-audience-student-candidates">{individualStudentsPanel}</div> : <>
          <div className="teacher-class-search-toolbar">
            <div className="teacher-class-search-input"><Search size={16} aria-hidden="true" />
              <input type="search" placeholder={tr("Search classes…")} aria-label={tr("Search classes…")} value={search} onChange={event=>setSearch(event.target.value)}/>
              {search && <button type="button" className="icon-button" aria-label={tr("Clear class search")} onClick={() => setSearch("")}><X size={14} aria-hidden="true" /></button>}
              {searchActions}
            </div>
            <small><TranslatedText>{tr("{value0} classes", {value0:visible.length})}</TranslatedText></small>
          </div>
          <div className="teacher-class-results">
          {visible.map((classInfo) => {
            const checked = selected.includes(classInfo.id);
            return (
              <label key={classInfo.id} className={checked ? "is-selected" : ""}>
                <input
                  type="checkbox"
                  disabled={disabledClassIds.includes(classInfo.id)}
                  checked={checked}
                  onChange={() => {
                    if(!checked)setFocusedClass(classInfo.id);
                    onChange(
                      checked
                        ? selected.filter((id) => id !== classInfo.id)
                        : [...selected, classInfo.id],
                    );
                  }}
                />
                <span><b>{classInfo.course || classInfo.id}</b>{classInfo.course && classInfo.course !== classInfo.id && <small style={{ display: "flex", gap: "6px", flexWrap: "wrap" }}><span>Lớp TH: <strong>{classInfo.practice_class || classInfo.practiceClass || classInfo.id}</strong></span>{classInfo.students !== undefined && <span>• {classInfo.students} SV</span>}</small>}{classNotices[classInfo.id] && <small className="teacher-class-assignment-notice"><TranslatedText>{classNotices[classInfo.id]}</TranslatedText></small>}</span>
              </label>
            );
          })}
          </div>
          {loading ? <Loading variant="inline" rows={2} label={tr("Loading classes…")} /> : error ? <span role="alert"><TranslatedText>{tr(error)}</TranslatedText></span> : classes.length === 0 && <span className="muted"><TranslatedText>{tr("No classes available.")}</TranslatedText></span>}
          {!loading && !error && classes.length > 0 && !visible.length && <p className="muted teacher-class-empty"><TranslatedText>{tr("No matching classes.")}</TranslatedText></p>}
          </>}
          </div>
          </section>
          {individualMode ? [studentPanel,selectedPanel] : [selectedPanel,studentPanel]}
          </div>
        </div>
      </SlidingDisclosure>
    </div>
  );
}
