import {Select} from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import React, { useEffect, useState, useRef, type FormEvent, type ReactNode } from "react";
import { Dialog } from "@/shared/ui/ui";
import { adminService, type AdminUser, type RoleOption } from "@/features/admin/adminService";
import { teacherService } from "@/shared/api/teacherService";
import { notify } from "@/shared/ui/notify";

export function PageIntro({ title, sub, children, compact = false }: { title: string; sub?: string; children?: ReactNode; compact?: boolean }) {
  const { t: tr } = useLanguage("admin");
  return <div className={`admin-page-intro${compact ? " page-list-intro" : ""}`}><div><h1 className={compact ? "sr-only" : undefined}><TranslatedText>{title}</TranslatedText></h1>{sub && <p><TranslatedText>{tr(sub)}</TranslatedText></p>}</div>{children && <div className="admin-page-actions">{children}</div>}</div>;
}

export function Split({ main, side, className = "" }: { main: ReactNode; side: ReactNode; className?: string }) {
  const { t: tr } = useLanguage("admin");
  return <div className={`admin-split ${className}`}><section className="admin-primary"><TranslatedText>{tr(main)}</TranslatedText></section><aside className="admin-side"><TranslatedText>{tr(side)}</TranslatedText></aside></div>;
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
  const { t: tr } = useLanguage("admin");
  return <label className="admin-field"><span><TranslatedText>{tr(label)}</TranslatedText></span>{children}</label>;
}

/** Báo kết quả nhập CSV bằng toast; danh sách lỗi từng dòng vẫn hiện trong hộp thoại. */
function toastImportResult(response: any, tr: (text: string) => string) {
  const ok = Number(response?.success_count ?? 0);
  const failed = Number(response?.failed_count ?? 0);
  const detail = `${tr("Success")}: ${ok} · ${tr("Failed")}: ${failed}`;
  if (failed === 0) notify.success(tr("Import Complete"), detail);
  else if (ok === 0) notify.error(tr("Import failed"), detail);
  else notify.info(tr("Import finished with errors"), detail);
}

export function Notice({ children }: { children: ReactNode }) {
  return <p className="admin-notice" role="status">{children}</p>;
}

export function AddUserDialog({ onClose, onAdd }: { onClose: () => void; onAdd: (user: AdminUser) => void }) {
  const { t: tr } = useLanguage("admin");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [accountType, setAccountType] = useState<AdminUser["account_type"]>("student");
  const [roleId, setRoleId] = useState("");
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([]);
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let active = true;
    adminService.getRoleOptions().then((options) => { if (active) setRoleOptions(options); }).catch(() => { if (active) setRoleOptions([]); });
    return () => { active = false; };
  }, []);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim() || !email.trim() || !password.trim()) return;
    setLoading(true);
    try {
      const newUser = await adminService.createUser({ name: name.trim(), email: email.trim(), account_type: accountType, role_id: roleId || undefined, password });
      onAdd(newUser);
    } catch (err: any) {
      notify.fail(tr("Failed to create user"), err, tr);
    } finally {
      setLoading(false);
    }
  }

  return <Dialog title={tr("Add user")} onClose={onClose} className="admin-dialog"><form onSubmit={submit} className="admin-dialog-form">
    <Field label={tr("NAME")}><input autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder={tr("Full name")} required /></Field>
    <Field label={tr("EMAIL")}><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="name@example.edu" required /></Field>
    <Field label={tr("PASSWORD")}><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder={tr("Password")} required minLength={8} /></Field>
    <Field label={tr("ACCOUNT TYPE")}><Select value={accountType} onChange={(e) => setAccountType(e.target.value as AdminUser["account_type"])}><option value="student">{tr("Student")}</option><option value="staff">{tr("Staff")}</option></Select></Field>
    <Field label={tr("ROLE")}><Select value={roleId} onChange={(e) => setRoleId(e.target.value)}><option value="">{tr(accountType === "student" ? "Default role (Student)" : "No role yet")}</option>{roleOptions.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</Select></Field>
    <div className="admin-dialog-actions"><button type="button" className="button" onClick={onClose} disabled={loading}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button className="button primary" disabled={loading}><TranslatedText>{loading ? tr("Adding...") : tr("Add user")}</TranslatedText></button></div>
  </form></Dialog>;
}

export function BulkImportDialog({ onClose, onSuccess }: { onClose: () => void; onSuccess: (message: string) => void }) {
  const { t: tr } = useLanguage("admin");
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<any>(null);
  const [isDragging, setIsDragging] = useState(false);

  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files.length > 0) {
      setFile(e.target.files[0]);
    }
  };

  const onDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(true);
  };

  const onDragLeave = () => {
    setIsDragging(false);
  };

  const onDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      const droppedFile = e.dataTransfer.files[0];
      if (droppedFile.name.endsWith('.csv')) {
        setFile(droppedFile);
      } else {
        notify.error(tr("Please upload a .csv file"));
      }
    }
  };

  const handleDownloadTemplate = () => {
    const csvContent = "name,email,account_type,role,password\nNguyễn Văn A,nva@example.com,student,,12354678";
    const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", "template_import_users.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!file) {
      notify.error(tr("Please select a CSV file."));
      return;
    }
    setLoading(true);
    setResult(null);

    try {
      const response = await adminService.importUsers(file);
      setResult(response);
      toastImportResult(response, tr);
    } catch (err: any) {
      notify.fail(tr("Failed to import users"), err, tr);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog title={tr("Import Users from CSV")} onClose={onClose} className="admin-dialog">
      <form onSubmit={submit} className="admin-dialog-form">
        {!result ? (
          <>
            <div style={{ marginBottom: "1.5rem", display: "flex", justifyContent: "flex-end" }}>
              <button type="button" className="text-button" onClick={handleDownloadTemplate} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <TranslatedText>{tr("Download CSV Template")}</TranslatedText>
              </button>
            </div>

            <div
              onDragOver={onDragOver}
              onDragLeave={onDragLeave}
              onDrop={onDrop}
              onClick={() => fileInputRef.current?.click()}
              style={{
                border: `2px dashed ${isDragging ? "var(--color-primary, #6366f1)" : "#cbd5e1"}`,
                borderRadius: "8px",
                padding: "2.5rem 2rem",
                textAlign: "center",
                backgroundColor: isDragging ? "rgba(99, 102, 241, 0.05)" : "transparent",
                cursor: "pointer",
                transition: "all 0.2s ease",
                marginBottom: "1rem"
              }}
            >
              <input
                type="file"
                accept=".csv"
                onChange={handleFileChange}
                ref={fileInputRef}
                style={{ display: "none" }}
              />
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke={isDragging ? "var(--color-primary, #6366f1)" : "#94a3b8"} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ margin: "0 auto 1rem", transition: "stroke 0.2s ease" }}>
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
              {file ? (
                <div>
                  <p style={{ fontWeight: 600, color: "var(--color-primary, #6366f1)", margin: "0 0 0.25rem" }}>{file.name}</p>
                  <p className="admin-muted" style={{ fontSize: "0.875rem", margin: 0 }}>{(file.size / 1024).toFixed(1)} KB</p>
                </div>
              ) : (
                <>
                  <p style={{ fontWeight: 500, margin: "0 0 0.5rem" }}><TranslatedText>{tr("Drag & drop your CSV file here")}</TranslatedText></p>
                  <p className="admin-muted" style={{ fontSize: "0.875rem", margin: 0 }}><TranslatedText>{tr("or click to browse from your computer")}</TranslatedText></p>
                </>
              )}
            </div>

            <p className="admin-muted" style={{ fontSize: "0.875rem", textAlign: "center", margin: "1rem 0" }}>
              <TranslatedText>{tr("The CSV file must contain at least 2 columns:")}</TranslatedText> <code style={{ backgroundColor: "#f1f5f9", padding: "0.2rem 0.4rem", borderRadius: "4px" }}>name</code>, <code style={{ backgroundColor: "#f1f5f9", padding: "0.2rem 0.4rem", borderRadius: "4px" }}>email</code>.
            </p>

            <div style={{ backgroundColor: "#fffbeb", border: "1px solid #fef08a", borderRadius: "6px", padding: "0.75rem", marginTop: "0.75rem" }}>
              <p style={{ fontSize: "0.8rem", color: "#854d0e", margin: 0, display: "flex", alignItems: "flex-start", gap: "0.5rem" }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, marginTop: "0.1rem" }}><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <span><strong><TranslatedText>{tr("Important:")}</TranslatedText></strong> <TranslatedText>{tr("When saving from Excel, please select")}</TranslatedText> <strong><TranslatedText>{tr("CSV UTF-8 (Comma delimited)")}</TranslatedText></strong> <TranslatedText>{tr("to prevent corruption of Vietnamese characters.")}</TranslatedText></span>
              </p>
            </div>

            <div className="admin-dialog-actions" style={{ marginTop: "1.5rem" }}>
              <button type="button" className="button" onClick={onClose} disabled={loading}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
              <button className="button primary" disabled={loading || !file}>
                <TranslatedText>{loading ? tr("Importing...") : tr("Import Users")}</TranslatedText>
              </button>
            </div>
          </>
        ) : (
          <>
            <div style={{ textAlign: "center", marginBottom: "1.5rem", marginTop: "1rem" }}>
              <div style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", width: "56px", height: "56px", borderRadius: "50%", backgroundColor: result.failed_count === 0 ? "#dcfce7" : "#fef08a", color: result.failed_count === 0 ? "#166534" : "#854d0e", marginBottom: "1rem" }}>
                {result.failed_count === 0 ? (
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                ) : (
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                )}
              </div>
              <h3 style={{ margin: 0, fontSize: "1.25rem", color: "#0f172a" }}><TranslatedText>{tr("Import Complete")}</TranslatedText></h3>
            </div>

            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "1rem", textAlign: "center", marginBottom: "1.5rem" }}>
              <div style={{ padding: "1rem", backgroundColor: "#f8fafc", borderRadius: "8px", border: "1px solid #e2e8f0" }}>
                <p className="admin-muted" style={{ fontSize: "0.75rem", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 0.5rem" }}><TranslatedText>{tr("Processed")}</TranslatedText></p>
                <p style={{ fontSize: "1.5rem", fontWeight: 600, margin: 0, color: "#334155" }}>{result.total_processed}</p>
              </div>
              <div style={{ padding: "1rem", backgroundColor: "#f0fdf4", borderRadius: "8px", border: "1px solid #bbf7d0", color: "#166534" }}>
                <p style={{ fontSize: "0.75rem", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 0.5rem", opacity: 0.8 }}><TranslatedText>{tr("Success")}</TranslatedText></p>
                <p style={{ fontSize: "1.5rem", fontWeight: 600, margin: 0 }}>{result.success_count}</p>
              </div>
              <div style={{ padding: "1rem", backgroundColor: result.failed_count > 0 ? "#fef2f2" : "#f8fafc", borderRadius: "8px", border: `1px solid ${result.failed_count > 0 ? "#fecaca" : "#e2e8f0"}`, color: result.failed_count > 0 ? "#991b1b" : "inherit" }}>
                <p style={{ fontSize: "0.75rem", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 0.5rem", color: result.failed_count > 0 ? "#991b1b" : "var(--color-text-muted)" }}><TranslatedText>{tr("Failed")}</TranslatedText></p>
                <p style={{ fontSize: "1.5rem", fontWeight: 600, margin: 0 }}>{result.failed_count}</p>
              </div>
            </div>

            {result.errors && result.errors.length > 0 && (
              <div style={{ maxHeight: "180px", overflowY: "auto", border: "1px solid #e2e8f0", borderRadius: "8px", padding: "1rem", marginTop: "1rem", backgroundColor: "#f8fafc" }}>
                <p style={{ fontWeight: 600, margin: "0 0 0.75rem", fontSize: "0.875rem", color: "#334155" }}><TranslatedText>{tr("Error Details:")}</TranslatedText></p>
                <ul style={{ fontSize: "0.875rem", paddingLeft: "1.25rem", margin: 0, color: "#64748b", display: "flex", flexDirection: "column", gap: "0.375rem" }}>
                  {result.errors.map((e: any, idx: number) => (
                    <li key={idx} style={{ lineHeight: 1.4 }}>
                      <span style={{ fontWeight: 600, color: "#475569" }}><TranslatedText>{tr("Row")}</TranslatedText> {e.row}:</span> {e.email} — <span style={{ color: "#ef4444" }}><TranslatedText>{tr(e.error)}</TranslatedText></span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="admin-dialog-actions" style={{ marginTop: "2rem" }}>
              <button type="button" className="button primary" style={{ width: "100%", justifyContent: "center" }} onClick={() => {
                onClose();
                if (result.success_count > 0) onSuccess(`Successfully imported ${result.success_count} users.`);
              }}>
                <TranslatedText>{tr("Done")}</TranslatedText>
              </button>
            </div>
          </>
        )}
      </form>
    </Dialog>
  );
}

export function BulkImportMembersDialog({ classId, onClose, onSuccess }: { classId: string; onClose: () => void; onSuccess: (message: string) => void }) {
  const { t: tr } = useLanguage("admin");
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<any>(null);
  const [isDragging, setIsDragging] = useState(false);

  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files.length > 0) {
      setFile(e.target.files[0]);
    }
  };

  const onDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(true);
  };

  const onDragLeave = () => {
    setIsDragging(false);
  };

  const onDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      const droppedFile = e.dataTransfer.files[0];
      if (droppedFile.name.endsWith('.csv') || droppedFile.name.endsWith('.txt')) {
        setFile(droppedFile);
      } else {
        notify.error(tr("Please upload a .csv or .txt file"));
      }
    }
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!file) {
      notify.error(tr("Please select a file."));
      return;
    }
    setLoading(true);
    setResult(null);

    try {
      const response = await teacherService.importClassMembers(classId, file);
      setResult(response);
      toastImportResult(response, tr);
    } catch (err: any) {
      notify.fail(tr("Failed to import members"), err, tr);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog title={tr("Import Class Members")} onClose={onClose} className="admin-dialog">
      <form onSubmit={submit} className="admin-dialog-form">
        {!result ? (
          <>
            <div
              onDragOver={onDragOver}
              onDragLeave={onDragLeave}
              onDrop={onDrop}
              onClick={() => fileInputRef.current?.click()}
              style={{
                border: `2px dashed ${isDragging ? "var(--color-primary, #6366f1)" : "#cbd5e1"}`,
                borderRadius: "8px",
                padding: "2.5rem 2rem",
                textAlign: "center",
                backgroundColor: isDragging ? "rgba(99, 102, 241, 0.05)" : "transparent",
                cursor: "pointer",
                transition: "all 0.2s ease",
                marginBottom: "1rem"
              }}
            >
              <input
                type="file"
                accept=".csv,.txt"
                onChange={handleFileChange}
                ref={fileInputRef}
                style={{ display: "none" }}
              />
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke={isDragging ? "var(--color-primary, #6366f1)" : "#94a3b8"} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ margin: "0 auto 1rem", transition: "stroke 0.2s ease" }}>
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
              {file ? (
                <div>
                  <p style={{ fontWeight: 600, color: "var(--color-primary, #6366f1)", margin: "0 0 0.25rem" }}>{file.name}</p>
                  <p className="admin-muted" style={{ fontSize: "0.875rem", margin: 0 }}>{(file.size / 1024).toFixed(1)} KB</p>
                </div>
              ) : (
                <>
                  <p style={{ fontWeight: 500, margin: "0 0 0.5rem" }}><TranslatedText>{tr("Drag & drop your CSV/TXT file here")}</TranslatedText></p>
                  <p className="admin-muted" style={{ fontSize: "0.875rem", margin: 0 }}><TranslatedText>{tr("or click to browse from your computer")}</TranslatedText></p>
                </>
              )}
            </div>

            <p className="admin-muted" style={{ fontSize: "0.875rem", textAlign: "center", margin: "1rem 0" }}>
              <TranslatedText>{tr("The file can be a comma-separated list of emails or one email per line.")}</TranslatedText>
            </p>

            <div className="admin-dialog-actions" style={{ marginTop: "1.5rem" }}>
              <button type="button" className="button" onClick={onClose} disabled={loading}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
              <button className="button primary" disabled={loading || !file}>
                <TranslatedText>{loading ? tr("Importing...") : tr("Import Members")}</TranslatedText>
              </button>
            </div>
          </>
        ) : (
          <>
            <div style={{ textAlign: "center", marginBottom: "1.5rem", marginTop: "1rem" }}>
              <div style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", width: "56px", height: "56px", borderRadius: "50%", backgroundColor: result.failed_count === 0 ? "#dcfce7" : "#fef08a", color: result.failed_count === 0 ? "#166534" : "#854d0e", marginBottom: "1rem" }}>
                {result.failed_count === 0 ? (
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                ) : (
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                )}
              </div>
              <h3 style={{ margin: 0, fontSize: "1.25rem", color: "#0f172a" }}><TranslatedText>{tr("Import Complete")}</TranslatedText></h3>
            </div>

            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "1rem", textAlign: "center", marginBottom: "1.5rem" }}>
              <div style={{ padding: "1rem", backgroundColor: "#f8fafc", borderRadius: "8px", border: "1px solid #e2e8f0" }}>
                <p className="admin-muted" style={{ fontSize: "0.75rem", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 0.5rem" }}><TranslatedText>{tr("Processed")}</TranslatedText></p>
                <p style={{ fontSize: "1.5rem", fontWeight: 600, margin: 0, color: "#334155" }}>{result.total_processed}</p>
              </div>
              <div style={{ padding: "1rem", backgroundColor: "#f0fdf4", borderRadius: "8px", border: "1px solid #bbf7d0", color: "#166534" }}>
                <p style={{ fontSize: "0.75rem", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 0.5rem", opacity: 0.8 }}><TranslatedText>{tr("Added")}</TranslatedText></p>
                <p style={{ fontSize: "1.5rem", fontWeight: 600, margin: 0 }}>{result.success_count}</p>
              </div>
              <div style={{ padding: "1rem", backgroundColor: result.skipped_count > 0 || result.failed_count > 0 ? "#fef2f2" : "#f8fafc", borderRadius: "8px", border: `1px solid ${result.skipped_count > 0 || result.failed_count > 0 ? "#fecaca" : "#e2e8f0"}`, color: result.skipped_count > 0 || result.failed_count > 0 ? "#991b1b" : "inherit" }}>
                <p style={{ fontSize: "0.75rem", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 0.5rem", color: result.skipped_count > 0 || result.failed_count > 0 ? "#991b1b" : "var(--color-text-muted)" }}><TranslatedText>{tr("Skipped/Failed")}</TranslatedText></p>
                <p style={{ fontSize: "1.5rem", fontWeight: 600, margin: 0 }}>{result.skipped_count + result.failed_count}</p>
              </div>
            </div>

            {result.errors && result.errors.length > 0 && (
              <div style={{ maxHeight: "180px", overflowY: "auto", border: "1px solid #e2e8f0", borderRadius: "8px", padding: "1rem", marginTop: "1rem", backgroundColor: "#f8fafc" }}>
                <p style={{ fontWeight: 600, margin: "0 0 0.75rem", fontSize: "0.875rem", color: "#334155" }}><TranslatedText>{tr("Failed/Skipped Details:")}</TranslatedText></p>
                <ul style={{ fontSize: "0.875rem", paddingLeft: "1.25rem", margin: 0, color: "#64748b", display: "flex", flexDirection: "column", gap: "0.375rem" }}>
                  {result.errors.map((e: any, idx: number) => (
                    <li key={idx} style={{ lineHeight: 1.4 }}>
                      <span style={{ fontWeight: 600, color: "#475569" }}>{e.email}</span> — <span style={{ color: "#ef4444" }}><TranslatedText>{tr(e.error)}</TranslatedText></span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="admin-dialog-actions" style={{ marginTop: "2rem" }}>
              <button type="button" className="button primary" style={{ width: "100%", justifyContent: "center" }} onClick={() => {
                onClose();
                if (result.success_count > 0) onSuccess(`Successfully imported ${result.success_count} members.`);
              }}>
                <TranslatedText>{tr("Done")}</TranslatedText>
              </button>
            </div>
          </>
        )}
      </form>
    </Dialog>
  );
}

export function AdminListPagination({ total, page, onPageChange, label, noun, alwaysVisible = false }: { total: number; page: number; onPageChange: (page: number) => void; label: string; noun: string; alwaysVisible?: boolean }) {
  const { t: tr, locale: uiLocale } = useLanguage("admin");
  const pageSize = 15;
  const pageCount = Math.max(1, Math.ceil(total / pageSize));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * pageSize;
  return <div className="admin-list-table-footer">
    <p className="admin-table-footer" role="status"><TranslatedText>{tr("Showing")}</TranslatedText> {total === 0 ? 0 : firstIndex + 1}–{Math.min(firstIndex + pageSize, total)} <TranslatedText>{tr("of")}</TranslatedText> {total.toLocaleString(uiLocale)} <TranslatedText>{tr(noun)}</TranslatedText></p>
    {(pageCount > 1 || alwaysVisible) && <nav className="admin-table-pagination" aria-label={tr(label)}>
      <button type="button" className="button admin-button" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)}><TranslatedText>{tr("← Previous")}</TranslatedText></button>
      <span><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</span>
      <button type="button" className="button admin-button" disabled={currentPage === pageCount} onClick={() => onPageChange(currentPage + 1)}><TranslatedText>{tr("Next →")}</TranslatedText></button>
    </nav>}
  </div>;
}
