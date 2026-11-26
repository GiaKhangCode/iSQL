import { X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import type { ProctorOption } from "@/shared/api/examSessions";

type ClassInfo = { id: string; course?: string; practice_class?: string; practiceClass?: string; lecturerId?: string | null; lecturer?: string | null };

/** One row per class of the session: the lecturers who proctor that class. Every class needs at least one. */
export function ClassProctorPicker({ classIds, classes, options, value, loading, error, onChange }: {
  classIds: string[];
  classes: ClassInfo[];
  options: ProctorOption[];
  value: Record<string, string[]>;
  loading?: boolean;
  error?: string;
  onChange: (next: Record<string, string[]>) => void;
}) {
  const { t } = useLanguage("teacher");
  const people = new Map(options.map(option => [option.id, option]));
  // Giảng viên của lớp là giám thị mặc định, kể cả khi không nằm trong danh sách chọn thêm.
  classes.forEach(info => {
    if (info.lecturerId && !people.has(String(info.lecturerId))) {
      people.set(String(info.lecturerId), { id: String(info.lecturerId), name: info.lecturer || String(info.lecturerId), email: "" });
    }
  });
  const label = (classId: string) => {
    const info = classes.find(item => item.id === classId);
    return info?.practice_class || info?.practiceClass || info?.course || classId;
  };
  const setClass = (classId: string, ids: string[]) => onChange({ ...value, [classId]: ids });

  if (!classIds.length) {
    return <p className="muted"><TranslatedText>{t("Select classes above first.")}</TranslatedText></p>;
  }
  return (
    <div className="examination-proctor-list">
      {loading && <p className="muted"><TranslatedText>{t("Loading lecturers…")}</TranslatedText></p>}
      {error && <p className="teacher-state-failed" role="alert"><TranslatedText>{t(error)}</TranslatedText></p>}
      {!loading && !error && !options.length && <p className="muted"><TranslatedText>{t("No lecturer accounts available.")}</TranslatedText></p>}
      {classIds.map(classId => {
        const chosen = value[classId] || [];
        const remaining = options.filter(option => !chosen.includes(option.id));
        const selectId = `exam-proctor-${classId}`;
        return (
          <div key={classId} className={`examination-proctor-row${chosen.length ? "" : " is-missing"}`}>
            <div className="examination-proctor-class">
              <b>{label(classId)}</b>
              <small>{classId}</small>
            </div>
            <div className="examination-proctor-chosen">
              {chosen.map(id => {
                const person = people.get(id);
                const name = person?.name || id;
                return (
                  <span key={id} className="examination-proctor-chip" title={person?.email}>
                    {name}
                    <button
                      type="button"
                      className="icon-button"
                      aria-label={t("Remove {value0}", { value0: name })}
                      disabled={chosen.length <= 1}
                      title={chosen.length <= 1 ? t("Each class needs at least one proctor.") : undefined}
                      onClick={() => setClass(classId, chosen.filter(value => value !== id))}
                    >
                      <X size={12} aria-hidden="true" />
                    </button>
                  </span>
                );
              })}
              {!chosen.length && <span className="examination-proctor-empty"><TranslatedText>{t("No proctor yet")}</TranslatedText></span>}
            </div>
            <select
              id={selectId}
              className="examination-proctor-select"
              aria-label={t("Proctors for {value0}", { value0: label(classId) })}
              aria-invalid={chosen.length ? undefined : true}
              value=""
              disabled={loading || !remaining.length}
              onChange={event => { if (event.target.value) setClass(classId, [...chosen, event.target.value]); }}
            >
              <option value="">{t("Add proctor…")}</option>
              {remaining.map(option => <option key={option.id} value={option.id}>{option.name} · {option.email}</option>)}
            </select>
          </div>
        );
      })}
    </div>
  );
}
