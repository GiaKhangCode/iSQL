import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";

function sectionOffset() {
  return (Number.parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue("--header-height")) || 0) + 24;
}

/** Track headings at the same offset used when navigating to them. */
export function useSectionNavigation(ids: readonly string[], ready = true, contentKey = "") {
  const key = ids.join("|");
  const [activeSection, setActiveSection] = useState(ids[0] || "");
  const requested = useRef<string | null>(null);
  const updateRef = useRef(() => {});
  const cancelSettleRef = useRef(() => {});
  const scrollFrames = useRef<number[]>([]);
  const navigateToSection = useCallback((id: string) => {
    requested.current = id;
    cancelSettleRef.current();
    setActiveSection(id);
    scrollFrames.current.forEach(id=>window.cancelAnimationFrame(id));
    scrollFrames.current = [window.requestAnimationFrame(() => {
      scrollFrames.current.push(window.requestAnimationFrame(() => {
        const target = document.getElementById(id);
        if (!target) { requested.current = null; updateRef.current(); return; }
        window.scrollTo({top:Math.max(0,window.scrollY + target.getBoundingClientRect().top - sectionOffset()),
          behavior:window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth"});
        updateRef.current();
      }));
    })];
  }, []);

  useEffect(() => {
    if (!ready) return;
    let frame = 0;
    let settle = 0;
    const sectionIds = key.split("|");
    const update = () => {
      frame = 0;
      const sections = sectionIds.flatMap(id => {
        const node = document.getElementById(id);
        if (!node || node.closest("[hidden]")) return [];
        const bounds = node.getBoundingClientRect();
        return bounds.height || bounds.width ? [{id,top:bounds.top}] : [];
      }).sort((a,b)=>a.top-b.top);
      if (!sections.length) return;
      const offset = sectionOffset();
      const bottom = document.documentElement.scrollHeight > window.innerHeight + 8 && window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 4;
      const target = sections.find(section=>section.id===requested.current);
      if (target && Math.abs(target.top-offset)>2 && !bottom) return;
      requested.current = null;
      let next = sections[0].id;
      for (const section of sections) if (section.top <= offset + 2) next=section.id;
      if (bottom) next=sections[sections.length-1].id;
      setActiveSection(current=>current===next?current:next);
    };
    const schedule = () => { if (!frame) frame=window.requestAnimationFrame(update); };
    const finish = () => { requested.current=null; schedule(); };
    const onScroll = () => {schedule();window.clearTimeout(settle);settle=window.setTimeout(finish,140);};
    cancelSettleRef.current=()=>window.clearTimeout(settle);
    updateRef.current = () => {schedule();window.clearTimeout(settle);settle=window.setTimeout(finish,140);};
    const observer = new ResizeObserver(schedule);
    observer.observe(document.body);
    sectionIds.forEach(id=>{const node=document.getElementById(id);if(node)observer.observe(node);});
    window.addEventListener("scroll",onScroll,{passive:true});
    window.addEventListener("resize",schedule);
    window.addEventListener("wheel",finish,{passive:true});
    window.addEventListener("touchstart",finish,{passive:true});
    schedule();
    return ()=>{
      observer.disconnect();window.cancelAnimationFrame(frame);window.clearTimeout(settle);
      window.removeEventListener("scroll",onScroll);window.removeEventListener("resize",schedule);
      window.removeEventListener("wheel",finish);window.removeEventListener("touchstart",finish);
      updateRef.current=()=>{};
      cancelSettleRef.current=()=>{};
    };
  }, [key,ready,contentKey]);
  useEffect(()=>()=>{scrollFrames.current.forEach(id=>window.cancelAnimationFrame(id));},[]);
  return {activeSection,setActiveSection,navigateToSection};
}

/** Measure in the nav's scroll coordinates, including its border and padding. */
export function useNavigationIndicator(active: string, contentKey = "") {
  const navigationRef = useRef<HTMLElement>(null);
  const activeItemRef = useRef<HTMLAnchorElement & HTMLButtonElement>(null);
  const [indicator, setIndicator] = useState({x:0,y:0,width:0,height:0});
  useLayoutEffect(()=>{
    const nav=navigationRef.current, item=activeItemRef.current;
    if(!nav || !item)return;
    let frame=0;
    const measure=()=>{
      frame=0;
      const root=nav.getBoundingClientRect(),bounds=item.getBoundingClientRect();
      const next={x:bounds.left-root.left+nav.scrollLeft-nav.clientLeft,y:bounds.top-root.top+nav.scrollTop-nav.clientTop,width:bounds.width,height:bounds.height};
      setIndicator(previous=>Object.keys(next).every(key=>Math.abs(previous[key as keyof typeof next]-next[key as keyof typeof next])<.5)?previous:next);
    };
    const schedule=()=>{if(!frame)frame=window.requestAnimationFrame(measure);};
    measure();
    const observer=new ResizeObserver(schedule);observer.observe(nav);observer.observe(item);
    // Preceding controls can change height (e.g. the unsaved badge).
    for(const child of nav.children)observer.observe(child);
    window.addEventListener("resize",schedule);
    return ()=>{observer.disconnect();window.cancelAnimationFrame(frame);window.removeEventListener("resize",schedule);};
  },[active,contentKey]);
  return {navigationRef,activeItemRef,indicator};
}
