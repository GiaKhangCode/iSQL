import {Children,Fragment,isValidElement,useId,useLayoutEffect,useRef,useState,type ReactNode,type SelectHTMLAttributes} from "react";
import {AssignmentFilterSelect} from "@/shared/components/AssignmentFilterSelect";
import "@/shared/components/AssignmentFilters.css";
import "./Select.css";

type Option={value:string;label:string;disabled?:boolean};
function text(node:ReactNode):string {
  return Children.toArray(node).map(child=>isValidElement<{children?:ReactNode}>(child)?text(child.props.children):String(child)).join("");
}
function options(children:ReactNode):Option[] {
  return Children.toArray(children).flatMap(child=>{
    if(!isValidElement<{children?:ReactNode;value?:string|number;label?:string;disabled?:boolean}>(child))return [];
    if(child.type===Fragment)return options(child.props.children);
    return [{value:String(child.props.value ?? text(child.props.children)),label:child.props.label ?? text(child.props.children),disabled:child.props.disabled}];
  });
}

/** A native form value paired with the application's accessible dropdown menu. */
export function Select({children,className="",style,id,onChange,onInvalid,...props}:SelectHTMLAttributes<HTMLSelectElement>) {
  const generated=useId();
  const native=useRef<HTMLSelectElement>(null);
  const host=useRef<HTMLSpanElement>(null);
  const [fieldLabel,setFieldLabel]=useState("");
  const items=options(children);
  const selected=String(props.value ?? props.defaultValue ?? items[0]?.value ?? "");
  useLayoutEffect(()=>{
    const labels=Array.from(native.current?.labels ?? []);
    if(id)labels.push(...Array.from(document.querySelectorAll<HTMLLabelElement>("label")).filter(label=>label.htmlFor===id));
    setFieldLabel(labels.map(label=>{const copy=label.cloneNode(true) as HTMLElement;copy.querySelectorAll("select,.app-select").forEach(node=>node.remove());return copy.textContent?.trim();}).join(" "));
  },[children]);
  return <span ref={host} className={`app-select ${className}`} style={style}>
    <select {...props} ref={native} id={id?`${id}-value`:undefined} className="app-select-native" aria-hidden="true" tabIndex={-1} onChange={onChange}
      onInvalid={event=>{event.preventDefault();onInvalid?.(event);host.current?.querySelector<HTMLButtonElement>('button')?.focus();}}>{children}</select>
    <AssignmentFilterSelect triggerId={id || generated} label={props["aria-label"] || fieldLabel || items.find(item=>item.value===selected)?.label || ""} labelledBy={props["aria-labelledby"]} exactLabel required={props.required} value={selected} options={items} active={false} disabled={Boolean(props.disabled) || !items.length} icon={null}
      onChange={value=>{
        if(!native.current)return;
        native.current.value=value;
        native.current.dispatchEvent(new (native.current.ownerDocument.defaultView!.Event)("change",{bubbles:true}));
      }}/>
  </span>;
}
