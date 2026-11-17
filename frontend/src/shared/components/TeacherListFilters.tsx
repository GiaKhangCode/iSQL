import type { ListOrder } from "./ListSortSelect";
import { ListSearchInput, type SearchSuggestion } from "./ListSearchInput";
import { ListDateFilters, type ListDates } from "./ListDateFilters";
// Lucian UX/UI Prompt #027 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#027
import { useId, useRef, useState, type ReactNode, type RefObject } from "react";
import { createPortal } from "react-dom";
import { Link } from "react-router-dom";
import { ArrowUpDown, ChevronDown, Plus, Search, SlidersHorizontal, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { AssignmentFilterSelect } from "@/shared/components/AssignmentFilterSelect";
import "@/shared/components/AssignmentFilters.css";

type Filter = {
  key: string; label: string; value: string; defaultValue: string; icon: ReactNode;
  options: { value: string; label: string }[]; onChange: (value: string) => void;
};
type Chip = { key: string; label: string; onClear: () => void };
type Props = {
  order?:ListOrder;
  dates?: ListDates;
  suggestions?: SearchSuggestion[];
  search: string; onSearchChange: (value: string) => void; searchLabel: string;
  filters?: Filter[]; extraChips?: Chip[]; onReset?: () => void;
  createLabel?: string; createTo?: string; onCreate?: () => void;
  primaryAction?: ReactNode;
  secondaryAction?: ReactNode; actionsTarget?: HTMLElement | null;
  disabled?: boolean; collapsible?: boolean;
  defaultOpen?: boolean;
  triggerRef?: RefObject<HTMLButtonElement>;
  onOpenChange?: (open: boolean) => void;
  renderSearch?: (context: { open: boolean }) => ReactNode;
  renderExtraFields?: (context: { open: boolean }) => ReactNode;
  extraFieldCount?: number;
};

/** Shared filter drawer and side actions for teacher list pages. */
export function TeacherListFilters({ order, dates, suggestions, search, onSearchChange, searchLabel, filters:providedFilters = [], extraChips = [], onReset,
  createLabel, createTo, onCreate, primaryAction, secondaryAction, actionsTarget, triggerRef: suppliedTrigger,
  onOpenChange, renderSearch, renderExtraFields, extraFieldCount = 0, disabled = false, collapsible = true, defaultOpen = false }: Props) {
  const { t } = useLanguage("teacher");
  const filters = order ? [...providedFilters,{key:"sort",label:t("Sort order"),value:order.value,defaultValue:"descending",onChange:order.setValue,icon:<ArrowUpDown size={17}/>,options:[{value:"descending",label:t("Descending")},{value:"ascending",label:t("Ascending")}]}] : providedFilters;
  const id = useId();
  const ownTrigger = useRef<HTMLButtonElement>(null);
  const trigger = suppliedTrigger ?? ownTrigger;
  const [expanded, setOpen] = useState(defaultOpen);
  const open = !collapsible || expanded;
  const [ready, setReady] = useState(false);
  const chips = filters.filter(filter => filter.value !== filter.defaultValue).map(filter => ({
    key: filter.key, label: filter.options.find(option => option.value === filter.value)?.label ?? filter.value,
    onClear: () => filter.onChange(filter.defaultValue),
  })).concat(extraChips);
  const filterCount = Number(Boolean(search)) + chips.length + Number(Boolean(dates?.created.from || dates?.created.to));
  const actions = <div className="assignment-filters-u027 u027-actions-host">
    <div className={`u027-actions${createLabel || primaryAction ? "" : " u027-actions-single"}`}>
      <button ref={trigger} type="button" disabled={disabled} className={`u027-toggle${open ? " is-active" : ""}`} aria-expanded={open}
        aria-controls={`${id}-filters`} onClick={() => {
          setReady(false); setOpen(!open); onOpenChange?.(!open);
        }}><SlidersHorizontal size={18} aria-hidden="true" /><TranslatedText>{t("Filters")}</TranslatedText>
        {filterCount > 0 && <span className="u027-filter-count">{filterCount}</span>}
        <ChevronDown size={16} className="u027-toggle-chevron" aria-hidden="true" />
      </button>
      {primaryAction || (createLabel && (createTo ? <Link className="u027-create" to={createTo}><Plus size={18} aria-hidden="true" /><TranslatedText>{createLabel}</TranslatedText></Link>
        : <button className="u027-create" type="button" onClick={onCreate}><Plus size={18} aria-hidden="true" /><TranslatedText>{createLabel}</TranslatedText></button>))}
    </div>
    {secondaryAction && <div className="u027-secondary-action">{secondaryAction}</div>}
  </div>;

  return <div className="assignment-filters-u027">
    {collapsible && (actionsTarget === undefined ? actions : actionsTarget && createPortal(actions, actionsTarget))}
    <div id={`${id}-filters`} className={`u027-drawer${open ? " is-open" : ""}${ready || !collapsible ? " is-ready" : ""}`} aria-hidden={!open}
      {...(!open ? { inert: "" } : {})} onTransitionEnd={event => {
        if (event.target === event.currentTarget && event.propertyName === "grid-template-rows" && open) setReady(true);
      }}>
      <div className="u027-drawer-content">
        <div className={`u027-toolbar u027-toolbar-${filters.length + extraFieldCount}`}>
          {renderSearch ? renderSearch({ open }) : <ListSearchInput value={search} onChange={onSearchChange} label={searchLabel} items={suggestions} disabled={!open||disabled}/>}

          {renderExtraFields?.({ open })}
          {filters.map(filter => <AssignmentFilterSelect key={filter.key} label={filter.label} value={filter.value}
            active={filter.value !== filter.defaultValue} disabled={!open || disabled} icon={filter.icon} options={filter.options.map(option=>option.value===filter.defaultValue && filter.key!=="sort" ? {...option,label:filter.label.charAt(0)+filter.label.slice(1).toLocaleLowerCase()} : option)} onChange={filter.onChange} />)}
          {dates && <ListDateFilters dates={dates}/>}
        </div>
        {filterCount > 0 && <div className="u027-filter-state">
          {search && <button type="button" className="u027-chip" aria-label={t("Clear search")} onClick={() => onSearchChange("")}><Search size={12} aria-hidden="true" /><span>{search}</span><X size={12} aria-hidden="true" /></button>}
          {chips.map(chip => <button key={chip.key} type="button" className="u027-chip" aria-label={t("Remove {value0}", { value0: chip.label })}
            onClick={chip.onClear}><span>{chip.label}</span><X size={12} aria-hidden="true" /></button>)}
          <button className="u027-reset" type="button" onClick={() => {
            onSearchChange(""); filters.forEach(filter => filter.onChange(filter.defaultValue));
            extraChips.forEach(chip => chip.onClear()); dates?.setCreated({from:"",to:""}); onReset?.();
          }}><TranslatedText>{t("Reset filters")}</TranslatedText></button>
        </div>}
      </div>
    </div>
  </div>;
}
