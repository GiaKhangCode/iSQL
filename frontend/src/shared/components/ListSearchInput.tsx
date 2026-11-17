import {TranslatedText} from "@/shared/ui/TranslatedText";
import { useId, useRef, useState } from "react";
import { FileSearch, Search, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { matchesListSearch } from "@/shared/lib/listSearch";
import "./AssignmentFilters.css";

export type SearchSuggestion = { id:string; title:string; description?:string; value?:string };
export function ListSearchInput({value,onChange,label,items=[],disabled=false}: {
  value:string; onChange:(value:string)=>void; label:string; items?:SearchSuggestion[]; disabled?:boolean;
}) {
  const {t}=useLanguage("teacher");
  const id=useId(), input=useRef<HTMLInputElement>(null);
  const [open,setOpen]=useState(false), [active,setActive]=useState(-1);
  const results=items.filter(item=>matchesListSearch(value,item.title,item.description,item.value)).slice(0,5);
  const choose=(item:SearchSuggestion)=>{onChange(item.value ?? item.title);setOpen(false);setActive(-1);};
  return <div className="assignment-filters-u027 list-search-host"><div className="u027-search" onBlur={e=>{if(!e.currentTarget.contains(e.relatedTarget))setOpen(false);}}>
    <div className="u027-field"><Search size={17} className="u027-search-icon" aria-hidden="true"/>
      <input ref={input} className="u027-input" type="text" value={value} disabled={disabled} autoComplete="off" role="combobox" aria-autocomplete="list" aria-label={label} placeholder={label} aria-expanded={open&&!disabled} aria-controls={`${id}-results`} aria-activedescendant={open&&active>=0&&results[active]?`${id}-${active}`:undefined}
        onFocus={()=>setOpen(true)} onChange={e=>{onChange(e.target.value);setOpen(true);setActive(-1);}}
        onKeyDown={e=>{
          if(e.nativeEvent.isComposing)return;
          if(e.key==="Escape"){e.preventDefault();setOpen(false);setActive(-1);}
          if(e.key==="ArrowDown"||e.key==="ArrowUp"){e.preventDefault();setOpen(true);setActive(current=>results.length?(current+(e.key==="ArrowDown"?1:-1)+results.length)%results.length:-1);}
          if(e.key==="Enter"){e.preventDefault();if(open&&results[active])choose(results[active]);else setOpen(false);}
        }}/>
      {value&&<button type="button" className="u027-close" aria-label={t("Clear search")} onClick={()=>{onChange("");setActive(-1);input.current?.focus();}}><X size={14}/></button>}
    </div>
    <div className={`u027-pop${open&&!disabled?" is-visible":""}`} aria-hidden={!open||disabled}>
      <p className="u027-section"><TranslatedText>{t(value.trim()?"Matching results":"Suggested results")}</TranslatedText></p>
      <ul role="listbox" id={`${id}-results`} className="u027-results" aria-label={label}>
        {results.map((item,index)=><li role="option" key={item.id} id={`${id}-${index}`} aria-selected={active===index} className="u027-option" onPointerMove={()=>setActive(index)} onMouseDown={e=>e.preventDefault()} onClick={()=>choose(item)}>
          <span className="u027-option-icon"><FileSearch size={16} aria-hidden="true"/></span><span className="u027-option-copy"><b>{item.title}</b>{item.description&&<small>{item.description}</small>}</span>
        </li>)}
      </ul>
      {!results.length&&<p className="u027-empty"><TranslatedText>{t("No matching results.")}</TranslatedText></p>}
      <p className="u027-hint"><TranslatedText>{t("↑ ↓ to choose · Enter to apply · Esc to close")}</TranslatedText></p>
    </div>
  </div></div>;
}
