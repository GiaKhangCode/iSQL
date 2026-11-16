import {TranslatedText} from "@/shared/ui/TranslatedText";
import { useRef, useState } from "react";
import { Download, FileSpreadsheet, Upload, X } from "lucide-react";
import { Dialog } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";
import { toast } from "@/shared/ui/StackingToasts";
import { audienceTemplateText,createAudienceTemplate,readAudienceFile,type AudienceFileData,type AudienceFileMode } from "@/shared/lib/contest/audienceFiles";
import "@/features/teacher/components/AudienceImportDialog.css";

export function AudienceImportDialog({mode,classIds,onImport,onClose}:{mode:AudienceFileMode;classIds:string[];onImport:(data:AudienceFileData)=>Promise<void>;onClose:()=>void}){
  const {t}=useLanguage("teacher");
  const input=useRef<HTMLInputElement>(null);
  const [file,setFile]=useState<File|null>(null),[dragging,setDragging]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState("");
  const [downloading,setDownloading]=useState(false);
  const choose=(next?:File)=>{if(busy || !next)return;setError("");if(!/\.csv$/i.test(next.name)){setError(t("Choose a CSV file."));return;}if(next.size>1024*1024){setError(t("Choose a file smaller than 1 MB."));return;}setFile(next);};
  const download=async()=>{
    setDownloading(true);setError("");
    try{const blob=await createAudienceTemplate(mode,classIds);const url=URL.createObjectURL(blob);const link=document.createElement("a");link.href=url;link.download=`mau-${mode==="classes"?"lop":"sinh-vien"}.csv`;document.body.append(link);link.click();link.remove();window.setTimeout(()=>URL.revokeObjectURL(url),1000);}
    catch{setError(t("Could not download the template. Try again."));}finally{setDownloading(false);}
  };
  const submit=async()=>{
    if(!file || busy)return;setBusy(true);setError("");
    try{await onImport(await readAudienceFile(file,classIds,mode));onClose();}
    catch(cause){const message=cause instanceof Error?t(cause.message):t("Could not import CSV");setError(message);toast.show({variant:"error",title:t("Import failed"),description:message});}
    finally{setBusy(false);}
  };
  return <Dialog className="teacher-audience-import-dialog" title={t(mode==="classes"?"Import class list":"Import student list")} onClose={()=>{if(!busy)onClose();}} closeOnBackdrop={!busy} closeOnCancel={!busy}>
    <div className="teacher-audience-import-body">
      <input ref={input} hidden type="file" accept=".csv" aria-label={t("Choose import file")} onChange={event=>{choose(event.target.files?.[0]);event.target.value="";}}/>
      <button type="button" className={`teacher-audience-dropzone${dragging?" is-dragging":""}`} disabled={busy} onClick={()=>input.current?.click()} onDragOver={event=>{event.preventDefault();if(!busy)setDragging(true);}} onDragLeave={()=>setDragging(false)} onDrop={event=>{event.preventDefault();setDragging(false);choose(event.dataTransfer.files[0]);}}>
        {file?<FileSpreadsheet size={36} aria-hidden="true"/>:<Upload size={36} aria-hidden="true"/>}
        <strong><TranslatedText>{file?file.name:t("Drag a CSV file here")}</TranslatedText></strong>
        <small><TranslatedText>{file?`${(file.size/1024).toFixed(1)} KB`:t("or click to choose a file")}</TranslatedText></small>
      </button>
      {file && <button type="button" className="text-button teacher-import-remove" disabled={busy} onClick={()=>{setFile(null);setError("");}}><X size={12}/><TranslatedText>{t("Remove file")}</TranslatedText></button>}
      <p className="teacher-import-help"><TranslatedText>{t(mode==="classes"?"One class ID per row. No header required; the template header is also accepted.":"One student ID per row. Replace the examples with the students you want to add.")}</TranslatedText><small><TranslatedText>{t("Up to 1 MB · 5,000 rows.")}</TranslatedText></small></p>
      <div className="teacher-import-templates"><span><TranslatedText>{t("Download a template")}</TranslatedText></span><button type="button" className="button" disabled={downloading||busy} onClick={()=>void download()}><Download size={13}/><TranslatedText>{t("CSV")}</TranslatedText></button><pre className="teacher-import-example" aria-label={t("CSV structure")}><code>{audienceTemplateText(mode,classIds)}</code></pre></div>
      {error && <p className="teacher-import-error" role="alert">{error}</p>}
      <div className="teacher-dialog-actions"><button type="button" className="button" disabled={busy} onClick={onClose}><TranslatedText>{t("Cancel")}</TranslatedText></button><button type="button" className="button primary" disabled={!file||busy} onClick={()=>void submit()}><TranslatedText>{t(busy?"Importingâ€¦":"Import list")}</TranslatedText></button></div>
    </div>
  </Dialog>;
}
