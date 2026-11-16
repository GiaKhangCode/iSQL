import { useEffect, useRef, useState, type ReactNode } from "react";

export function SlidingDisclosure({open,onClosed,children}:{open:boolean;onClosed?:()=>void;children:ReactNode}) {
  const [mounted,setMounted]=useState(open);
  const closed=useRef(onClosed);closed.current=onClosed;
  useEffect(()=>{
    if(open){setMounted(true);return;}
    if(!mounted)return;
    const finish=()=>{setMounted(false);closed.current?.();};
    if(window.matchMedia("(prefers-reduced-motion: reduce)").matches){finish();return;}
    const timer=window.setTimeout(finish,240);
    return ()=>window.clearTimeout(timer);
  },[open,mounted]);
  return mounted ? <div className="teacher-sliding-disclosure" data-open={open} aria-hidden={!open}
    ref={node=>{if(node)node.inert=!open;}}><div className="teacher-sliding-disclosure-inner">{children}</div></div> : null;
}
