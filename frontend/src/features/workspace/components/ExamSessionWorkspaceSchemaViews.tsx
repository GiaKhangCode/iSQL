import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Table as TableIcon, Key, Link2, ChevronDown, ChevronUp } from "lucide-react";
import type { Translator } from "@/i18n/index";
import type { ExamSessionSchema } from "@/features/workspace/types";

export function ExamSessionDiagramCanvas({ schemaData, isModal, tr }: { schemaData: ExamSessionSchema | null; isModal: boolean; tr: Translator }) {
if (!schemaData?.tables || schemaData.tables.length === 0) {
      return (
        <div style={{ textAlign: "center", padding: "40px 16px", color: "var(--muted)" }}>
          <TableIcon size={32} style={{ opacity: 0.4, marginBottom: 12 }} />
          <p style={{ margin: 0, fontSize: 13 }}><TranslatedText>{tr("No tables have been created in this schema.")}</TranslatedText></p>
          <small><TranslatedText>{tr("Write a `CREATE TABLE` statement and click \"Run DDL\" to see the database diagram here.")}</TranslatedText></small>
        </div>
      );
    }
return (
      <div className="exam-session-diagram-canvas">
        <div className={isModal ? "exam-session-fullscreen-erd-grid" : "exam-session-diagram-grid"}>
          {schemaData.tables.map((tbl) => {
            const sortedCols = [...tbl.columns].sort((a, b) => {
              if (a.isPk && !b.isPk) return -1;
              if (!a.isPk && b.isPk) return 1;
              if (a.isFk && !b.isFk) return -1;
              if (!a.isFk && b.isFk) return 1;
              return 0;
            });

            return (
              <div key={tbl.tableName} className="exam-session-diagram-table-node">
                <div className="exam-session-diagram-table-header">
                  <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
                    <TableIcon size={14} color="#0ea5e9" />
                    <span>{tbl.tableName.toUpperCase()}</span>
                  </div>
                  <span className="exam-session-row-count-badge">{tbl.rowCount} <TranslatedText>{tr("rows")}</TranslatedText></span>
                </div>

                <div className="exam-session-diagram-cols-list">
                  {sortedCols.map((c) => (
                    <div
                      key={c.name}
                      className={`exam-session-diagram-col-row ${c.isPk ? "is-pk" : ""} ${c.isFk ? "is-fk" : ""}`}
                    >
                      <div style={{ display: "flex", alignItems: "center", gap: "6px" }}>
                        {c.isPk && (
                          <span className="exam-session-col-badge-pk" title={tr("Primary key (PK)")}>
                            <Key size={10} /> PK
                          </span>
                        )}
                        {c.isFk && (
                          <span className="exam-session-col-badge-fk" title={tr("Foreign key (FK)")}>
                            <Link2 size={10} /> FK
                          </span>
                        )}
                        <span style={{ fontWeight: c.isPk ? 700 : c.isFk ? 600 : 400 }}>
                          {c.name}
                        </span>
                        {c.fkTarget && (
                          <span className="exam-session-fk-target-chip" title={tr("References {value0}", { value0: c.fkTarget.toUpperCase() })}>
                            ➔ {c.fkTarget.toUpperCase()}
                          </span>
                        )}
                      </div>

                      <div style={{ display: "flex", alignItems: "center", gap: "6px", color: "var(--muted)", fontSize: "11px" }}>
                        <span>
                          {c.type.toUpperCase()}
                          {c.maxLen && c.maxLen > 0 ? `(${c.maxLen})` : ""}
                        </span>
                        {!c.nullable && <span style={{ color: "#ef4444", fontWeight: "bold" }}>*</span>}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>

        {schemaData.relationships && schemaData.relationships.length > 0 && (
          <div className="exam-session-diagram-relations-box">
            <h4>
              <Link2 size={14} /> <TranslatedText>{tr("Foreign key relationships")}</TranslatedText>
            </h4>
            <div className="exam-session-relations-list">
              {schemaData.relationships.map((rel, i) => (
                <div key={i} className="exam-session-relation-item">
                  <b>{rel.fromTable.toUpperCase()}</b> ({rel.fromCol})
                  <span className="exam-session-relation-arrow">──[ N : 1 ]──►</span>
                  <b>{rel.toTable.toUpperCase()}</b> ({rel.toCol})
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    );
}

export function ExamSessionCardsView({ schemaData, collapsedCards, toggleCardCollapse, tr }: { schemaData: ExamSessionSchema | null; collapsedCards: Record<string, boolean>; toggleCardCollapse: (tableName: string) => void; tr: Translator }) {
if (!schemaData?.tables || schemaData.tables.length === 0) {
      return (
        <div style={{ textAlign: "center", padding: "40px 16px", color: "var(--muted)" }}>
          <TableIcon size={32} style={{ opacity: 0.4, marginBottom: 12 }} />
          <p style={{ margin: 0, fontSize: 13 }}><TranslatedText>{tr("No tables have been created in this schema.")}</TranslatedText></p>
          <small><TranslatedText>{tr("Write a `CREATE TABLE` statement and click \"Run DDL\" to see the table structure here.")}</TranslatedText></small>
        </div>
      );
    }
return schemaData.tables.map((tbl) => {
      const isCollapsed = Boolean(collapsedCards[tbl.tableName]);
      return (
        <div key={tbl.tableName} className="exam-session-table-card">
          <div
            className="exam-session-table-card-header"
            onClick={() => toggleCardCollapse(tbl.tableName)}
            title={tr("Click to collapse or expand the table")}
          >
            <div className="exam-session-table-name">
              <TableIcon size={14} color="var(--accent)" />
              <span>{tbl.tableName.toUpperCase()}</span>
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
              <span className="exam-session-row-count-badge">{tbl.rowCount} <TranslatedText>{tr("rows")}</TranslatedText></span>
              {isCollapsed ? <ChevronDown size={14} /> : <ChevronUp size={14} />}
            </div>
          </div>
          {!isCollapsed && (
            <div className="exam-session-table-columns">
              {tbl.columns.map((c) => (
                <div key={c.name} className="exam-session-col-item">
                  <div className="exam-session-col-left">
                    {c.isPk && (
                      <span className="exam-session-col-badge-pk" title={tr("Primary key (PK)")}>
                        <Key size={10} /> PK
                      </span>
                    )}
                    {c.isFk && (
                      <span className="exam-session-col-badge-fk" title={tr("Foreign key (FK)")}>
                        <Link2 size={10} /> FK
                      </span>
                    )}
                    <span>{c.name}</span>
                    {c.fkTarget && (
                      <span className="exam-session-fk-target-chip" title={tr("References {value0}", { value0: c.fkTarget.toUpperCase() })}>
                        ➔ {c.fkTarget.toUpperCase()}
                      </span>
                    )}
                  </div>
                  <div className="exam-session-col-right">
                    <span>
                      <TranslatedText>{tr(c.type)}</TranslatedText>
                      {c.maxLen && c.maxLen > 0 ? `(${c.maxLen})` : ""}
                    </span>
                    {!c.nullable && <span style={{ color: "#ef4444" }}>*</span>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      );
    });
}
