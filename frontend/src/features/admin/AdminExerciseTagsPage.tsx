import { useState, useEffect, useMemo, useRef } from "react";
import { Plus, Pencil, Trash2, Tag, Filter } from "lucide-react";
import { Dialog, Loading, Status } from "@/shared/ui/ui";
import { Table, TableRow } from "@/shared/ui/Table";
import { Select } from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { exerciseTagsApi, type ExerciseTag } from "@/shared/api/exerciseTagsApi";
import { invalidateExerciseTagsCache } from "@/features/teacher/problem-editor/QuestionTagSelectors";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";

export function AdminExerciseTagsPage() {
  const { t: tr } = useLanguage("admin");
  const [tags, setTags] = useState<ExerciseTag[]>([]);
  const [loading, setLoading] = useState(true);
  const [notice, setNotice] = useState("");
  const [query, setQuery] = useState("");
  const [filterKind, setFilterKind] = useState<string>("All");
  const [filterPart, setFilterPart] = useState<string>("ALL");
  const [selectedId, setSelectedId] = useState("");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const tableScrollRef = useRef<HTMLDivElement>(null);

  // Modal states
  const [dialogMode, setDialogMode] = useState<"create" | "edit" | null>(null);
  const [editingTag, setEditingTag] = useState<ExerciseTag | null>(null);
  const [formKind, setFormKind] = useState<"difficulty" | "category">("difficulty");
  const [formName, setFormName] = useState("");
  const [formPart, setFormPart] = useState<"ALL" | "DDL" | "DML" | "DQL" | "TRIGGER">("ALL");
  const [formOrderIndex, setFormOrderIndex] = useState<number>(0);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState("");

  // Delete modal state
  const [deletingTag, setDeletingTag] = useState<ExerciseTag | null>(null);

  const fetchTags = async () => {
    try {
      setLoading(true);
      const res = await exerciseTagsApi.getTags();
      setTags(res);
      if (!selectedId && res.length > 0) {
        setSelectedId(res[0].id);
      }
    } catch (err: any) {
      setNotice(err?.message || tr("Failed to load exercise tags."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void fetchTags();
  }, []);

  const openCreateDialog = (defaultKind?: "difficulty" | "category") => {
    const kind = defaultKind || (filterKind === "category" ? "category" : "difficulty");
    const countForKind = tags.filter((t) => t.kind === kind).length;
    setDialogMode("create");
    setEditingTag(null);
    setFormKind(kind);
    setFormName("");
    setFormPart("ALL");
    setFormOrderIndex(countForKind + 1);
    setFormError("");
  };

  const openEditDialog = (tag: ExerciseTag) => {
    setDialogMode("edit");
    setEditingTag(tag);
    setFormKind(tag.kind);
    setFormName(tag.name);
    setFormPart(tag.part);
    setFormOrderIndex(tag.orderIndex);
    setFormError("");
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formName.trim()) {
      setFormError(tr("Please enter tag name."));
      return;
    }
    setSubmitting(true);
    setFormError("");
    try {
      if (dialogMode === "create") {
        const created = await exerciseTagsApi.createTag({
          kind: formKind,
          name: formName.trim(),
          part: formKind === "category" ? formPart : "ALL",
          orderIndex: formOrderIndex,
        });
        setSelectedId(created.id);
        setNotice(`Đã tạo thành công thẻ "${created.name}".`);
      } else if (dialogMode === "edit" && editingTag) {
        const updated = await exerciseTagsApi.updateTag(editingTag.id, {
          name: formName.trim(),
          part: formKind === "category" ? formPart : "ALL",
          orderIndex: formOrderIndex,
        });
        setSelectedId(updated.id);
        setNotice(`Đã cập nhật thành công thẻ "${updated.name}".`);
      }
      invalidateExerciseTagsCache();
      setDialogMode(null);
      await fetchTags();
    } catch (err: any) {
      setFormError(err?.message || tr("Failed to save exercise tag."));
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingTag) return;
    setSubmitting(true);
    try {
      await exerciseTagsApi.deleteTag(deletingTag.id);
      invalidateExerciseTagsCache();
      setNotice(`Đã xóa thẻ "${deletingTag.name}".`);
      setDeletingTag(null);
      setSelectedId("");
      await fetchTags();
    } catch (err: any) {
      alert(err?.message || tr("Failed to delete exercise tag."));
    } finally {
      setSubmitting(false);
    }
  };

  const filteredTags = useMemo(() => {
    return tags.filter((tag) => {
      if (filterKind !== "All" && tag.kind !== filterKind) return false;
      if (filterKind !== "difficulty" && filterPart !== "ALL" && tag.part !== "ALL" && tag.part !== filterPart) {
        return false;
      }
      if (query.trim()) {
        const q = query.toLowerCase();
        return (
          tag.name.toLowerCase().includes(q) ||
          tag.kind.toLowerCase().includes(q) ||
          tag.part.toLowerCase().includes(q)
        );
      }
      return true;
    }).sort((a, b) => {
      if (a.kind !== b.kind) {
        return a.kind === "difficulty" ? -1 : 1;
      }
      if (a.orderIndex !== b.orderIndex) {
        return a.orderIndex - b.orderIndex;
      }
      return a.name.localeCompare(b.name);
    });
  }, [tags, filterKind, filterPart, query]);

  const difficultyCount = tags.filter((t) => t.kind === "difficulty").length;
  const categoryCount = tags.filter((t) => t.kind === "category").length;

  const pageCount = Math.max(1, Math.ceil(filteredTags.length / pageSize));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * pageSize;
  const pageTags = filteredTags.slice(firstIndex, firstIndex + pageSize);

  const selectedTag = filteredTags.find((t) => t.id === selectedId) || pageTags[0] || filteredTags[0];

  return (
    <section className="teacher-page teacher-list-page compact-teacher-exam-sessions admin-courses-page admin-tags-page">
      <h1 className="sr-only"><TranslatedText>{tr("Exercise tags management")}</TranslatedText></h1>
      {notice && <p className="teacher-form-message" role="status"><TranslatedText>{notice}</TranslatedText></p>}
      <div className="teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main">
          <TeacherListFilters
            search={query}
            onSearchChange={(val) => {
              setQuery(val);
              setPage(1);
            }}
            searchLabel={tr("Tìm kiếm thẻ...")}
            actionsTarget={actionsTarget}
            createLabel={tr("Thêm thẻ mới")}
            onCreate={() => openCreateDialog()}
            filters={[
              {
                key: "kind",
                label: tr("LOẠI THẺ"),
                value: filterKind,
                defaultValue: "All",
                onChange: (v) => {
                  setFilterKind(v);
                  setPage(1);
                },
                icon: <Tag size={17} aria-hidden="true" />,
                options: [
                  { value: "All", label: `Tất cả (${tags.length})` },
                  { value: "difficulty", label: `Thẻ độ khó (${difficultyCount})` },
                  { value: "category", label: `Thẻ loại đề (${categoryCount})` },
                ],
              },
              ...(filterKind !== "difficulty"
                ? [
                    {
                      key: "part",
                      label: tr("PHẦN ÁP DỤNG"),
                      value: filterPart,
                      defaultValue: "ALL",
                      onChange: (v: string) => {
                        setFilterPart(v);
                        setPage(1);
                      },
                      icon: <Filter size={17} aria-hidden="true" />,
                      options: [
                        { value: "ALL", label: "Tất cả các phần" },
                        { value: "DDL", label: "DDL (Tạo / sửa bảng)" },
                        { value: "DML", label: "DML (Thêm dữ liệu)" },
                        { value: "DQL", label: "DQL (Truy vấn dữ liệu)" },
                        { value: "TRIGGER", label: "TRIGGER (Ràng buộc toàn vẹn)" },
                      ],
                    },
                  ]
                : []),
            ]}
            onReset={() => {
              setQuery("");
              setFilterKind("All");
              setFilterPart("ALL");
              setPage(1);
            }}
          />
          <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
            <Table className="teacher-table teacher-course-list-table sticky-list-table">
              <thead>
                <tr>
                  <th><TranslatedText>{tr("ID")}</TranslatedText></th>
                  <th><TranslatedText>{tr("TÊN THẺ / DẠNG")}</TranslatedText></th>
                  <th><TranslatedText>{tr("LOẠI THẺ")}</TranslatedText></th>
                  <th><TranslatedText>{tr("ÁP DỤNG PHẦN")}</TranslatedText></th>
                  <th><TranslatedText>{tr("TRẠNG THÁI")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan={5} style={{ padding: "40px 0", textAlign: "center" }}>
                      <Loading label={tr("Đang tải danh sách thẻ...")} />
                    </td>
                  </tr>
                ) : (
                  pageTags.map((tag, index) => {
                    const isSelected = selectedTag?.id === tag.id;
                    return (
                      <TableRow
                        selected={isSelected}
                        key={tag.id}
                        className={`teacher-selectable-list-row${isSelected ? " is-selected" : ""}`}
                        onClick={() => setSelectedId(tag.id)}
                        onDoubleClick={() => openEditDialog(tag)}
                      >
                        <td data-label="ID">{firstIndex + index + 1}</td>
                        <td data-label={tr("TÊN THẺ / DẠNG")}>
                          <button
                            type="button"
                            className="teacher-selectable-row-title"
                            onClick={(e) => {
                              e.stopPropagation();
                              setSelectedId(tag.id);
                            }}
                          >
                            {tag.name}
                          </button>
                          <small className="teacher-row-subtext">
                            Thứ tự: {tag.orderIndex} · {tag.kind === "difficulty" ? "Độ khó câu hỏi" : `Dạng: ${tag.part}`}
                          </small>
                        </td>
                        <td data-label={tr("LOẠI THẺ")}>
                          <span
                            style={{
                              display: "inline-block",
                              padding: "2px 8px",
                              borderRadius: 4,
                              fontSize: 12,
                              fontWeight: 500,
                              background:
                                tag.kind === "difficulty" ? "rgba(59, 130, 246, 0.12)" : "rgba(16, 185, 129, 0.12)",
                              color: tag.kind === "difficulty" ? "#2563eb" : "#059669",
                            }}
                          >
                            {tag.kind === "difficulty" ? "Thẻ độ khó" : "Thẻ loại đề"}
                          </span>
                        </td>
                        <td data-label={tr("ÁP DỤNG PHẦN")}>
                          {tag.kind === "difficulty" ? (
                            <span style={{ color: "var(--muted)", fontSize: 12 }}>Tất cả</span>
                          ) : (
                            <span
                              style={{
                                display: "inline-block",
                                padding: "2px 8px",
                                borderRadius: 4,
                                fontSize: 11,
                                fontWeight: 600,
                                background: "var(--accent-subtle, rgba(99, 102, 241, 0.1))",
                                color: "var(--accent, #4f46e5)",
                              }}
                            >
                              {tag.part}
                            </span>
                          )}
                        </td>
                        <td data-label={tr("TRẠNG THÁI")}>
                          <Status value="Active" />
                        </td>
                      </TableRow>
                    );
                  })
                )}
                {!loading && !filteredTags.length && (
                  <tr>
                    <td className="teacher-empty-row" colSpan={5}>
                      <TranslatedText>
                        {tags.length === 0
                          ? "Chưa có thẻ nào. Nhấn Thêm thẻ mới để bắt đầu."
                          : "Không tìm thấy thẻ phù hợp bộ lọc."}
                      </TranslatedText>
                    </td>
                  </tr>
                )}
              </tbody>
            </Table>
          </div>
          <TeacherListPagination
            summary={
              <>
                <TranslatedText>{tr("Hiển thị")}</TranslatedText>{" "}
                {filteredTags.length ? firstIndex + 1 : 0}–{firstIndex + pageTags.length}{" "}
                <TranslatedText>{tr("trên")}</TranslatedText> {filteredTags.length}{" "}
                <TranslatedText>{tr("thẻ")}</TranslatedText>
              </>
            }
            paginationLabel={tr("Trang thẻ")}
            pageIndicator={
              <>
                <TranslatedText>{tr("Trang")}</TranslatedText> {currentPage}{" "}
                <TranslatedText>{tr("trên")}</TranslatedText> {pageCount}
              </>
            }
            previousLabel={<TranslatedText>{tr("← Trước")}</TranslatedText>}
            nextLabel={<TranslatedText>{tr("Tiếp →")}</TranslatedText>}
            page={currentPage}
            pageCount={pageCount}
            onPageChange={(nextPage) => {
              setPage(nextPage);
            }}
          />
        </div>
        <div className="teacher-filter-side">
          <div ref={setActionsTarget} className="teacher-filter-actions" />
          <aside className="teacher-list-detail-panel">
            {selectedTag ? (
              <>
                <span className="teacher-detail-eyebrow">
                  <TranslatedText>CHI TIẾT THẺ BÀI TẬP</TranslatedText>
                </span>
                <h2>{selectedTag.name}</h2>
                <p className="muted">
                  {selectedTag.kind === "difficulty" ? "Thẻ độ khó câu hỏi" : `Thẻ loại đề (${selectedTag.part})`}
                </p>
                <dl className="teacher-list-detail-facts">
                  <div>
                    <dt><TranslatedText>LOẠI THẺ</TranslatedText></dt>
                    <dd>
                      <span
                        style={{
                          display: "inline-block",
                          padding: "2px 8px",
                          borderRadius: 4,
                          fontSize: 12,
                          fontWeight: 500,
                          background:
                            selectedTag.kind === "difficulty" ? "rgba(59, 130, 246, 0.12)" : "rgba(16, 185, 129, 0.12)",
                          color: selectedTag.kind === "difficulty" ? "#2563eb" : "#059669",
                        }}
                      >
                        {selectedTag.kind === "difficulty" ? "Thẻ độ khó" : "Thẻ loại đề"}
                      </span>
                    </dd>
                  </div>
                  <div>
                    <dt><TranslatedText>PHẦN ÁP DỤNG</TranslatedText></dt>
                    <dd>{selectedTag.kind === "difficulty" ? "Tất cả các phần (DDL, DML, DQL, Trigger)" : selectedTag.part}</dd>
                  </div>
                  <div>
                    <dt><TranslatedText>THỨ TỰ HIỂN THỊ</TranslatedText></dt>
                    <dd>{selectedTag.orderIndex}</dd>
                  </div>
                  <div>
                    <dt><TranslatedText>TRẠNG THÁI</TranslatedText></dt>
                    <dd><Status value="Active" /></dd>
                  </div>
                  <div>
                    <dt><TranslatedText>MÃ THẺ (ID)</TranslatedText></dt>
                    <dd style={{ fontSize: "11px", wordBreak: "break-all", color: "var(--muted)" }}>{selectedTag.id}</dd>
                  </div>
                </dl>
                <div className="teacher-list-detail-actions">
                  <button
                    type="button"
                    className="button primary"
                    onClick={() => openEditDialog(selectedTag)}
                  >
                    <Pencil size={13} style={{ marginRight: 6 }} />
                    <TranslatedText>Chỉnh sửa thẻ</TranslatedText>
                  </button>
                  <button
                    type="button"
                    className="button"
                    style={{ color: "var(--danger, #ef4444)", borderColor: "var(--border)" }}
                    onClick={() => setDeletingTag(selectedTag)}
                  >
                    <Trash2 size={13} style={{ marginRight: 6 }} />
                    <TranslatedText>Xóa thẻ</TranslatedText>
                  </button>
                </div>
              </>
            ) : (
              <p className="muted"><TranslatedText>Chọn một thẻ để xem thông tin chi tiết.</TranslatedText></p>
            )}
          </aside>
        </div>
      </div>

      {/* Modal: Create or Edit Dialog */}
      {dialogMode && (
        <Dialog
          title={dialogMode === "create" ? tr("Thêm thẻ mới") : tr("Chỉnh sửa thẻ")}
          onClose={() => setDialogMode(null)}
          className="admin-dialog"
        >
          <form onSubmit={handleSave} style={{ display: "flex", flexDirection: "column", gap: 16 }}>
            {dialogMode === "create" ? (
              <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                <span style={{ fontSize: 12, fontWeight: 600, color: "var(--muted)" }}>
                  <TranslatedText>LOẠI THẺ</TranslatedText>
                </span>
                <Select
                  value={formKind}
                  onChange={(e) => setFormKind(e.target.value as "difficulty" | "category")}
                >
                  <option value="difficulty">Thẻ độ khó (Dễ, Trung bình, Khó...)</option>
                  <option value="category">Thẻ loại đề (DDL, DML, DQL, Trigger...)</option>
                </Select>
              </label>
            ) : (
              <div style={{ fontSize: 13, color: "var(--muted)" }}>
                <span>Loại thẻ: </span>
                <strong>
                  {editingTag?.kind === "difficulty" ? "Thẻ độ khó" : "Thẻ loại đề"}
                </strong>
              </div>
            )}

            <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: "var(--muted)" }}>
                <TranslatedText>TÊN THẺ *</TranslatedText>
              </span>
              <input
                autoFocus
                type="text"
                required
                value={formName}
                onChange={(e) => setFormName(e.target.value)}
                placeholder={
                  formKind === "difficulty"
                    ? "Ví dụ: Dễ, Trung bình, Khó..."
                    : "Ví dụ: DDL - Tạo bảng (CREATE TABLE)..."
                }
                style={{
                  height: 36,
                  padding: "0 10px",
                  borderRadius: 6,
                  border: "1px solid var(--border)",
                  background: "var(--bg)",
                  color: "var(--text)",
                  fontSize: 13,
                }}
              />
            </label>

            {formKind === "category" && (
              <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                <span style={{ fontSize: 12, fontWeight: 600, color: "var(--muted)" }}>
                  <TranslatedText>PHẦN ÁP DỤNG</TranslatedText>
                </span>
                <Select
                  value={formPart}
                  onChange={(e) => setFormPart(e.target.value as any)}
                >
                  <option value="ALL">Tất cả các phần (ALL)</option>
                  <option value="DDL">DDL (Cấu trúc bảng)</option>
                  <option value="DML">DML (Thao tác dữ liệu)</option>
                  <option value="DQL">DQL (Truy vấn dữ liệu)</option>
                  <option value="TRIGGER">TRIGGER (Ràng buộc toàn vẹn)</option>
                </Select>
              </label>
            )}

            <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: "var(--muted)" }}>
                <TranslatedText>THỨ TỰ HIỂN THỊ</TranslatedText>
              </span>
              <input
                type="number"
                min={0}
                value={formOrderIndex}
                onChange={(e) => setFormOrderIndex(parseInt(e.target.value, 10) || 0)}
                style={{
                  height: 36,
                  padding: "0 10px",
                  borderRadius: 6,
                  border: "1px solid var(--border)",
                  background: "var(--bg)",
                  color: "var(--text)",
                  fontSize: 13,
                }}
              />
            </label>

            {formError && (
              <p style={{ margin: 0, color: "var(--danger, #ef4444)", fontSize: 13 }}>
                {formError}
              </p>
            )}

            <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 8 }}>
              <button
                type="button"
                className="button"
                onClick={() => setDialogMode(null)}
                disabled={submitting}
              >
                <span><TranslatedText>Hủy</TranslatedText></span>
              </button>
              <button type="submit" className="button primary" disabled={submitting}>
                <span>
                  <TranslatedText>
                    {submitting
                      ? "Đang lưu..."
                      : dialogMode === "create"
                      ? "Tạo thẻ"
                      : "Lưu thay đổi"}
                  </TranslatedText>
                </span>
              </button>
            </div>
          </form>
        </Dialog>
      )}

      {/* Modal: Delete Confirmation */}
      {deletingTag && (
        <Dialog
          title="Xác nhận xóa thẻ"
          onClose={() => setDeletingTag(null)}
          className="admin-dialog"
        >
          <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
            <p style={{ margin: 0, fontSize: 14, color: "var(--text)" }}>
              Bạn có chắc chắn muốn xóa thẻ <strong>"{deletingTag.name}"</strong> không?
            </p>
            <p style={{ margin: 0, fontSize: 13, color: "var(--muted)" }}>
              Thẻ này sẽ không còn xuất hiện trong các dropdown chọn thẻ. Các câu hỏi đã gắn thẻ này trước đó vẫn giữ nguyên tên nhãn.
            </p>
            <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 8 }}>
              <button
                type="button"
                className="button"
                onClick={() => setDeletingTag(null)}
                disabled={submitting}
              >
                <span><TranslatedText>Hủy</TranslatedText></span>
              </button>
              <button
                type="button"
                className="button primary"
                style={{ background: "var(--danger, #ef4444)", borderColor: "var(--danger, #ef4444)" }}
                onClick={handleDelete}
                disabled={submitting}
              >
                <span>
                  <TranslatedText>{submitting ? "Đang xóa..." : "Xóa thẻ"}</TranslatedText>
                </span>
              </button>
            </div>
          </div>
        </Dialog>
      )}
    </section>
  );
}
