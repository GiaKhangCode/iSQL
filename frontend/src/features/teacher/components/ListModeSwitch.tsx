import {useEffect,useRef} from "react";
import {flushSync} from "react-dom";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {useLanguage} from "@/shared/context/LanguageContext";
import "./ListModeSwitch.css";

export function ListModeSwitch({rooms,onChange,labels=["Classes","Exam rooms"],nextKind,collapseSecondary=false}:{rooms:boolean;onChange:(rooms:boolean)=>void;labels?:[string,string];nextKind?:string;collapseSecondary?:boolean}){
  const {t}=useLanguage();
  const control=useRef<HTMLButtonElement>(null);
  const running=useRef(false);
  const mounted=useRef(true);
  const animations=useRef<Animation[]>([]);
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;animations.current.forEach(animation=>animation.cancel());};},[]);
  async function select(next:boolean){
    if(next===rooms||running.current)return;
    const page=control.current?.closest('.teacher-page');
    const container=page?.closest('.teacher-results-dashboard, .office-exam-dashboard')||page?.parentElement;
    const panels=Array.from(page?.querySelectorAll<HTMLElement>('.teacher-list-table-scroll, .teacher-list-detail-panel')||[]);
    const indicator=control.current?.querySelector<HTMLElement>('.class-room-switch-indicator');
    if(!panels[0]?.animate||window.matchMedia?.('(prefers-reduced-motion: reduce)').matches){onChange(next);return;}
    running.current=true;
    const direction=next?1:-1;
    const duration=collapseSecondary?300:180;
    const leaving=panels.map(panel=>panel.animate([{transform:'translateX(0)',opacity:1},{transform:`translateX(${-direction*32}px)`,opacity:0}],{duration,easing:'ease-in-out',fill:'forwards'}));
    const secondary=collapseSecondary?page?.querySelector<HTMLElement>('.u027-secondary-action'):null;
    if(secondary){const height=secondary.getBoundingClientRect().height;leaving.push(secondary.animate([{height:`${height}px`,marginTop:'8px',opacity:1,overflow:'hidden'},{height:'0px',marginTop:'0px',opacity:0,overflow:'hidden'}],{duration,easing:'ease-in-out',fill:'forwards'}));}
    animations.current=[...leaving];
    if(indicator)animations.current.push(indicator.animate([{transform:rooms?'translateX(calc(100% + 3px))':'translateX(0)'},{transform:next?'translateX(calc(100% + 3px))':'translateX(0)'}],{duration,easing:'cubic-bezier(.2,.8,.2,1)',fill:'forwards'}));
    try{await Promise.all(leaving.map(animation=>animation.finished));}catch{return;}
    if(!mounted.current)return;
    flushSync(()=>onChange(next));
    animations.current.forEach(animation=>animation.cancel());
    running.current=false;
    const visible=(selector:string)=>Array.from(container?.querySelectorAll<HTMLElement>(selector)||[]).filter(element=>!element.closest('[hidden]'));
    const newSecondary=collapseSecondary?visible('.u027-secondary-action')[0]:null;
    if(newSecondary){const height=newSecondary.getBoundingClientRect().height;newSecondary.animate([{height:'0px',marginTop:'0px',opacity:0,overflow:'hidden'},{height:`${height}px`,marginTop:'8px',opacity:1,overflow:'hidden'}],{duration:300,easing:'ease-in-out'});}
    visible('.teacher-list-table-scroll, .teacher-list-detail-panel').forEach(panel=>panel.animate([{transform:`translateX(${direction*32}px)`,opacity:0},{transform:'translateX(0)',opacity:1}],{duration:collapseSecondary?300:220,easing:'cubic-bezier(.2,.8,.2,1)'}));
    visible('.class-room-switch')[0]?.focus({preventScroll:true});
  }
  return <button ref={control} type="button" className={`class-room-switch${rooms?' is-rooms':''}`} role="switch" aria-checked={rooms} aria-label={`${t(labels[0])} / ${t(labels[1])}`} data-results-kind={nextKind} onClick={()=>void select(!rooms)}>
    <span className="class-room-switch-indicator" aria-hidden="true"/>
    <span className={`class-room-option${!rooms?' is-active':''}`}><TranslatedText>{t(labels[0])}</TranslatedText></span>
    <span className={`class-room-option${rooms?' is-active':''}`}><TranslatedText>{t(labels[1])}</TranslatedText></span>
  </button>;
}
