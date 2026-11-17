import {TranslatedText} from "@/shared/ui/TranslatedText";
import { useState } from "react";
import { CalendarDays } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";

type Range = { from: string; to: string };
export function matchesDateRange(value: unknown, range: Range) {
  if (!range.from && !range.to) return true;
  if (typeof value !== "string" || !value) return false;
  const date = parseServerDateTime(value);
  if (!Number.isFinite(date.getTime())) return false;
  const day = new Intl.DateTimeFormat("en-CA", {timeZone:"Asia/Ho_Chi_Minh",year:"numeric",month:"2-digit",day:"2-digit"}).format(date);
  return (!range.from || day >= range.from) && (!range.to || day <= range.to);
}
export function useListDateFilters() {
  const [created, setCreated] = useState<Range>({from:"",to:""});
  return {created,setCreated,key:JSON.stringify(created),
    matches:(value:object) => {
      const row = value as Record<string,unknown>;
      return matchesDateRange(row.createdAt ?? row.created_at ?? row.submittedAt,created);
    }};
}
export type ListDates = ReturnType<typeof useListDateFilters>;
export function ListDateFilters({dates}:{dates:ListDates}) {
  const {t}=useLanguage("teacher");
  return <div className="list-date-filters">{([
    ["Created date",dates.created,dates.setCreated],
  ] as const).map(([label,range,setRange])=><details className="list-date-filter" key={label}>
    <summary><CalendarDays size={15} aria-hidden="true"/><span><TranslatedText>{t(label)}</TranslatedText></span>{(range.from || range.to) && <b>•</b>}</summary>
    <div className="list-date-popover">
      <label><TranslatedText>{t("From date")}</TranslatedText><input type="date" aria-label={`${t(label)} · ${t("From date")}`} value={range.from} max={range.to || undefined} onChange={e=>setRange({...range,from:e.target.value})}/></label>
      <label><TranslatedText>{t("To date")}</TranslatedText><input type="date" aria-label={`${t(label)} · ${t("To date")}`} value={range.to} min={range.from || undefined} onChange={e=>setRange({...range,to:e.target.value})}/></label>
      <button type="button" onClick={()=>setRange({from:"",to:""})}><TranslatedText>{t("Clear")}</TranslatedText></button>
    </div>
  </details>)}</div>;
}
