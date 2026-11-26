import { DatabaseLibraryList } from "./DatabaseLibraryList";
import { newestFirst } from "@/shared/lib/sql/listOrder";
import { useEffect, useRef, useState, type ChangeEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useSectionNavigation, useNavigationIndicator } from "@/shared/hooks/useSectionNavigation";
import { Database, Save, Trash2, Upload } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Loading } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TeacherField } from "@/features/teacher/components/TeacherPageParts";
import { teacherService, type TeacherDatabaseLibraryDataset, type TeacherDatabaseLibraryEntry, type TeacherDatabaseLibraryEntryInput } from "@/shared/api/teacherService";
import { DatabaseLibrarySqlImportError, parseDatabaseLibrarySql, sqlFileBaseName } from "@/shared/lib/sql/databaseLibraryImport";
import { SchemaPanel, buildSchemaDiagram, type SchemaDiagramState } from "@/features/teacher/content-editor/SchemaPanel";
import { DatasetsPanel } from "@/features/teacher/content-editor/DatasetsPanel";
import "@/styles/problem-workspace.css";
import "@/styles/database-library.css";
import { invalidateLibraryOutputs } from "@/features/teacher/library/LibraryBundleEditor";
import { LibraryEditorTabs, LibraryEditorPanel } from "@/features/teacher/library/LibraryEditorTabs";
import { blankEntry, scriptFileNames, datasetFileNameForTitle, fileNameFromEntry, datasetsFromEntry } from "@/features/teacher/DatabaseLibraryParts";
import { notify } from "@/shared/ui/notify";

export function DatabaseLibraryPage() {
  const { t: tr } = useLanguage("teacher");
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const urlId = searchParams.get("id");
  // Set when the teacher came from the problem editor to author a database; only that editor's routes are accepted.
  const rawReturnTo = searchParams.get("returnTo") || "";
  const returnTo = /^\/teacher\/problems\/(new|[A-Za-z0-9_-]+\/edit)$/.test(rawReturnTo) ? rawReturnTo : "";
  const openedForReturn = useRef(false);
  const [editing, setEditing] = useState(false);
  const [entries, setEntries] = useState<TeacherDatabaseLibraryEntry[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [form, setForm] = useState<TeacherDatabaseLibraryEntryInput>(() => ({ ...blankEntry }));
  const [structureTab, setStructureTab] = useState<"schema" | "data">("schema");
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [schemaDiagram, setSchemaDiagram] = useState<SchemaDiagramState>(null);
  const [saving, setSaving] = useState(false);
  const [editorKey, setEditorKey] = useState(0);
  const importInputRef = useRef<HTMLInputElement>(null);
  const librarySections = [
    {id:"library-details",label:"Database details"},
    {id:"library-schema",label:"Table structure"},
    {id:"library-datasets",label:"Initial datasets"},
  ];
  const {activeSection,navigateToSection}=useSectionNavigation(librarySections.map(section=>section.id),!loading && editing,`${selectedId}|${structureTab}`);
  const {navigationRef,activeItemRef,indicator}=useNavigationIndicator(activeSection,`${loading}|${editing}|${selectedId}|${structureTab}`);
  function openLibrarySection(id:string) {
    if (id === "library-schema" || id === "library-datasets") setStructureTab(id==="library-schema"?"schema":"data");
    navigateToSection(id);
  }

  useEffect(() => {
    if (saveStatus !== tr("Unsaved changes")) return;
    const beforeUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ""; };
    window.addEventListener("beforeunload", beforeUnload);
    return () => window.removeEventListener("beforeunload", beforeUnload);
  }, [saveStatus, tr]);

  useEffect(() => {
    let active = true;
    teacherService.getDatabaseLibrary().then((data) => {
      if (active) setEntries(newestFirst(data,entry=>entry.createdAt));
    }).catch((error: unknown) => {
      if (active) setLoadError(error instanceof Error ? error.message : tr("Could not load database library."));
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, [tr]);

  useEffect(() => {
    if (loading || loadError || openedForReturn.current) return;
    if (searchParams.get("new") === "1") {
      openedForReturn.current = true;
      startNewEntry(true);
      return;
    }
    if (urlId) {
      const target = entries.find((entry) => entry.id === urlId);
      if (target) {
        openedForReturn.current = true;
        selectEntry(target);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading, loadError, urlId]);

  function leaveEditor() {
    if (saveStatus === tr("Unsaved changes") && !window.confirm(tr("Discard unsaved changes?"))) return;
    setSaveStatus("");
    if (returnTo) navigate(`${returnTo}?restore=1`);
    else setEditing(false);
  }

  function startNewEntry(force = false) {
    if (!force && saveStatus === tr("Unsaved changes") && !window.confirm(tr("Discard unsaved changes?"))) return;
    setEditing(true);
    setSelectedId(null);
    setStructureTab("schema");
    setForm({ ...blankEntry, datasets: blankEntry.datasets.map((dataset) => ({ ...dataset })) });
    setSaveStatus("");
    setEditorKey(key => key + 1);
    setSchemaDiagram(null);
  }

  function selectEntry(entry: TeacherDatabaseLibraryEntry) {
    if (saveStatus === tr("Unsaved changes") && !window.confirm(tr("Discard unsaved changes?"))) return;
    setEditing(true);
    setSelectedId(entry.id);
    setForm({
      title: entry.title,
      ...fileNameFromEntry(entry),
      createTableSql: entry.createTableSql || "",
      datasets: datasetsFromEntry(entry),
      // Question content saved by older versions is carried through untouched; this page no longer edits it.
      bundle: entry.bundle ? structuredClone(entry.bundle) : undefined,
      expectedRevision: entry.revision || 1,
    });
    setStructureTab("schema");
    setSaveStatus("");
    setEditorKey(key => key + 1);
    setSchemaDiagram(null);
  }

  function updateForm<K extends keyof TeacherDatabaseLibraryEntryInput>(key: K, value: TeacherDatabaseLibraryEntryInput[K]) {
    setForm((current) => {
      if (key !== "title") return { ...current, [key]: value, ...(key === "createTableSql" || key === "datasets" ? {bundle:invalidateLibraryOutputs(current.bundle)} : {}) };
      const nextTitle = String(value);
      const previousDefaults = scriptFileNames(current.title);
      const nextDefaults = scriptFileNames(nextTitle);
      const datasets = current.datasets.map((dataset, index) => ({
        ...dataset,
        fileName: dataset.fileName === datasetFileNameForTitle(current.title, index)
          ? datasetFileNameForTitle(nextTitle, index)
          : dataset.fileName,
      }));
      return {
        ...current,
        title: nextTitle,
        createTableFileName: current.createTableFileName === previousDefaults.createTableFileName ? nextDefaults.createTableFileName : current.createTableFileName,
        datasets,
      };
    });
    setSaveStatus(tr("Unsaved changes"));
  }

  function updateDataset(index: number, patch: Partial<TeacherDatabaseLibraryDataset>) {
    setForm((current) => ({
      ...current,
      datasets: current.datasets.map((dataset, datasetIndex) => datasetIndex === index ? { ...dataset, ...patch } : dataset),
      ...(patch.sql !== undefined ? {bundle:invalidateLibraryOutputs(current.bundle)} : {}),
    }));
    setSaveStatus(tr("Unsaved changes"));
  }

  function runCreateTableDdl() {
    setSchemaDiagram(buildSchemaDiagram(form.createTableSql));
  }

  function addDataset() {
    let index = form.datasets.length;
    let fileName = datasetFileNameForTitle(form.title, index);
    while (form.datasets.some((dataset) => dataset.fileName.toLowerCase() === fileName.toLowerCase())) {
      index += 1;
      fileName = datasetFileNameForTitle(form.title, index);
    }
    setForm((current) => ({ ...current, datasets: [...current.datasets, { fileName, sql: "" }], bundle:invalidateLibraryOutputs(current.bundle) }));
    setSaveStatus(tr("Unsaved changes"));
  }

  function removeDataset(index: number) {
    if (index === 0) return;
    setForm((current) => ({ ...current, datasets: current.datasets.filter((_, datasetIndex) => datasetIndex !== index), bundle:invalidateLibraryOutputs(current.bundle) }));
    setSaveStatus(tr("Unsaved changes"));
  }

  async function importSqlFile(event: ChangeEvent<HTMLInputElement>) {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = "";
    if (!file) return;

    if (!/\.sql$/i.test(file.name)) {
      notify.error(tr("Choose a .sql file to import."));
      return;
    }

    try {
      const imported = parseDatabaseLibrarySql(await file.text());
      if ((form.createTableSql.trim() || form.datasets.some((dataset) => dataset.sql.trim())) && !window.confirm(tr("Importing a file replaces both scripts. Continue?"))) return;
      const baseName = sqlFileBaseName(file.name);
      const displayName = file.name.replace(/\.sql$/i, "").replace(/[_-]+/g, " ").trim();
      setForm((current) => ({
        ...current,
        title: current.title.trim() ? current.title : displayName || tr("Imported database"),
        createTableFileName: `${baseName}_createtable.sql`,
        createTableSql: imported.createTableSql,
        datasets: [{ fileName: `${baseName}_dataset.sql`, sql: imported.insertIntoSql }],
        bundle: invalidateLibraryOutputs(current.bundle),
      }));
      setSaveStatus(tr("Unsaved changes"));
      notify.success(tr("SQL file imported"), tr("Imported {value0} CREATE TABLE and {value1} INSERT INTO statements from {value2}.", {
        value0: imported.createTableCount,
        value1: imported.insertIntoCount,
        value2: file.name,
      }));
    } catch (error) {
      if (error instanceof DatabaseLibrarySqlImportError) {
        if (error.kind === "empty") notify.error(tr("The SQL file is empty."));
        else if (error.kind === "no-supported-statements") notify.error(tr("No CREATE TABLE or INSERT INTO statements were found."));
        else notify.error(tr("Only CREATE TABLE and INSERT INTO statements are allowed. Found: {value0}.", { value0: error.command }));
      } else {
        notify.fail(tr("Could not read this SQL file."), error);
      }
    }
  }

  async function saveEntry() {
    if (!form.title.trim()) {
      notify.error(tr("Give this database a name before saving."));
      return;
    }
    setSaving(true);
    try {
      const saved = selectedId
        ? await teacherService.updateDatabaseLibraryEntry(selectedId, form)
        : await teacherService.createDatabaseLibraryEntry(form);
      setEntries((current) => newestFirst([saved, ...current.filter((entry) => entry.id !== saved.id)],entry=>entry.createdAt));
      setSelectedId(saved.id);
      setForm({ title: saved.title, ...fileNameFromEntry(saved), createTableSql: saved.createTableSql, datasets: datasetsFromEntry(saved), bundle: saved.bundle || undefined, expectedRevision: saved.revision || 1 });
      setSaveStatus(tr("All changes saved"));
      notify.success(tr("Database library entry saved"), saved.title);
      try {
        localStorage.setItem(`teacher-db-library-entry:${saved.id}`, JSON.stringify({
          id: saved.id,
          title: saved.title,
          updatedAt: Date.now(),
        }));
      } catch {}
      if (returnTo) navigate(`${returnTo}?database=${encodeURIComponent(saved.id)}`);
    } catch (error) {
      notify.fail(tr("Could not save database library entry."), error, tr);
    } finally {
      setSaving(false);
    }
  }

  async function deleteEntry() {
    if (!selectedId || !window.confirm(tr("Delete this database library entry?"))) return;
    setSaving(true);
    try {
      await teacherService.deleteDatabaseLibraryEntry(selectedId);
      notify.success(tr("Database library entry deleted"), form.title);
      setEntries((current) => current.filter((entry) => entry.id !== selectedId));
      startNewEntry(true);
      setEditing(false);
    } catch (error) {
      notify.fail(tr("Could not delete database library entry."), error, tr);
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <section className="teacher-page teacher-database-library-page"><Loading variant="form" label={tr("Loading database library…")} /></section>;
  if (loadError) return <section className="teacher-page teacher-database-library-page"><div className="teacher-database-library-error" role="alert"><TranslatedText>{tr("Could not load database library.")}</TranslatedText><button className="button" type="button" onClick={() => window.location.reload()}><TranslatedText>{tr("Try again")}</TranslatedText></button></div></section>;

  if (!editing) return <DatabaseLibraryList entries={entries} onNew={() => startNewEntry()} onEdit={selectEntry} initialSearch={searchParams.get("search") || ""} />;

  return (
    <section className="teacher-page teacher-problem-page teacher-database-library-page">
      <div className="teacher-database-library-layout">
        <aside className="teacher-database-library-sidebar">
          <div className="teacher-database-library-sidebar-heading">
            <div className="teacher-database-library-sidebar-title"><Database size={17} aria-hidden="true" /><h1><TranslatedText>{tr("Database library")}</TranslatedText></h1></div>
            <p><TranslatedText>{tr("{value0} saved databases", { value0: entries.length })}</TranslatedText></p>
          </div>
          <button className="button teacher-database-library-list-back" type="button" disabled={saving} onClick={leaveEditor}><TranslatedText>{tr(returnTo ? "Back to problem" : "Back to database library")}</TranslatedText></button>
          <nav ref={navigationRef} className="teacher-exam-session-page-nav teacher-library-section-nav" aria-label={tr("Database editor sections")}>
            <span className="teacher-exam-session-page-nav-indicator" aria-hidden="true" style={{width:indicator.width,height:indicator.height,transform:`translate(${indicator.x}px,${indicator.y}px)`}} />
            <h3><TranslatedText>{tr("On this page")}</TranslatedText></h3>
            {librarySections.map(section=><button key={section.id} ref={activeSection===section.id?activeItemRef:undefined} type="button" className={activeSection===section.id?"is-active":""} aria-current={activeSection===section.id?"location":undefined} onClick={()=>openLibrarySection(section.id)}><TranslatedText>{tr(section.label)}</TranslatedText></button>)}
          </nav>
        </aside>

        <main className="teacher-database-library-main">
          <section className="teacher-database-library-card">
            <div className="teacher-database-library-section-heading">
              <h3><TranslatedText>{tr("Database details")}</TranslatedText></h3>
              {saveStatus && <span className={`teacher-database-library-save-status${saveStatus === tr("Unsaved changes") ? " is-unsaved" : ""}`}><TranslatedText>{saveStatus}</TranslatedText></span>}
            </div>
            <div id="library-details" className="teacher-database-library-identity-fields">
              <TeacherField label={tr("Database name")}>
                <input value={form.title} maxLength={255} placeholder={tr("e.g. Sales database")} onChange={(event) => updateForm("title", event.target.value)} />
              </TeacherField>

            </div>


            <div className="library-database-heading">
              <h3><TranslatedText>{tr("Database")}</TranslatedText></h3>
              <span className="problem-database-engine">SQL Server</span>
            </div>
            <div className="library-setup-toolbar">
              <LibraryEditorTabs<"schema" | "data"> secondary value={structureTab} onChange={setStructureTab}
                label={tr("Database setup")} prefix="library-setup" panelId="library-content-panel"
                tabs={[{id:"schema",label:tr("Table structure")},{id:"data",label:tr("Initial datasets")}]} />
              <div className="teacher-database-library-import-control">
                <input ref={importInputRef} className="teacher-database-library-import-input" type="file" accept=".sql,application/sql,text/plain" aria-label={tr("Import SQL file")} onChange={(event) => void importSqlFile(event)} />
                <button className="button teacher-database-library-import-button" type="button" disabled={saving} onClick={() => importInputRef.current?.click()}>
                  <Upload size={14} aria-hidden="true" />
                  <TranslatedText>{tr("Import SQL file")}</TranslatedText>
                </button>
                <small><TranslatedText>{tr("CREATE TABLE + INSERT INTO only")}</TranslatedText></small>
              </div>
            </div>
            <div id={structureTab === "schema" ? "library-schema" : "library-datasets"}>
            <LibraryEditorPanel motionKey={structureTab} order={structureTab === "schema" ? 0 : 1}
              labelledBy={`library-setup-${structureTab}`}>
            <div className="teacher-database-library-script-section" hidden={structureTab !== "schema"}>
              <SchemaPanel variant="library" sql={form.createTableSql} onSqlChange={(value) => updateForm("createTableSql", value)}
                fileName={form.createTableFileName} onFileNameChange={(value) => updateForm("createTableFileName", value)}
                diagram={schemaDiagram} onRun={runCreateTableDdl} />
            </div>

            <div className="teacher-database-library-script-section" hidden={structureTab !== "data"}>
              <DatasetsPanel key={editorKey} variant="library" showHiddenToggle datasets={form.datasets}
                onEdit={updateDataset} onAdd={addDataset} onRemove={removeDataset} />
            </div>
            </LibraryEditorPanel>
            </div>

            <div className="teacher-database-library-actions">
              {selectedId && <button className="button teacher-danger-button" type="button" disabled={saving} onClick={() => void deleteEntry()}><Trash2 size={14} aria-hidden="true" /><TranslatedText>{tr("Delete")}</TranslatedText></button>}
              <button className="button primary" type="button" disabled={saving || !form.title.trim()} onClick={() => void saveEntry()}><Save size={14} aria-hidden="true" /><TranslatedText>{saving ? tr("Saving…") : returnTo ? tr("Save and return to problem") : selectedId ? tr("Save changes") : tr("Save to library")}</TranslatedText></button>
            </div>
          </section>
        </main>
      </div>
    </section>
  );
}
