import { useState } from "react";
import { ArrowUpDown } from "lucide-react";
import { AssignmentFilterSelect } from "./AssignmentFilterSelect";
import { useLanguage } from "@/shared/context/LanguageContext";
import "./AssignmentFilters.css";

export function sortAscending<T>(rows:readonly T[], key:(row:T)=>string|number):T[] {
  return [...rows].sort((a,b)=>String(key(a)).localeCompare(String(key(b)),"vi",{numeric:true,sensitivity:"base"}));
}
export function useListOrder() {
  const [value,setValue]=useState("descending");
  return {value,setValue,apply:<T,>(rows:readonly T[],key:(row:T)=>string|number)=>value==="ascending"?sortAscending(rows,key):sortAscending(rows,key).reverse()};
}
export type ListOrder=ReturnType<typeof useListOrder>;
export function ListSortSelect({order}:{order:ListOrder}) {
  const {t}=useLanguage("teacher");
  return <div className="assignment-filters-u027 list-sort-control"><AssignmentFilterSelect disabled={false} label={t("Sort order")} value={order.value} active={order.value!=="descending"} icon={<ArrowUpDown size={17}/>} onChange={order.setValue} options={[{value:"descending",label:t("Descending")},{value:"ascending",label:t("Ascending")}]} /></div>;
}
