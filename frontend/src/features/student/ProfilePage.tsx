import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiFetch } from "@/shared/api/apiClient";
import { formatPoints } from "@/shared/lib/sql/formatPoints";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { Table } from "@/shared/ui/Table";
import { Status } from "@/shared/ui/ui";
import { Award, BookOpen, Clock, ExternalLink, GraduationCap } from "lucide-react";
import "@/features/student/ProfilePage.css";

export interface CourseGrade {
  code: string;
  name: string;
  credits: number;
  process?: number | null;
  practice?: number | null;
  midterm?: number | null;
  final?: number | null;
  gpa: number;
}

export interface ActivityHistoryItem {
  id: string;
  title: string;
  kind: "exam" | "assignment" | "Examination" | "ExamSession" | "Assignment";
  date?: string | null;
  submittedAt?: string | null;
  status?: string;
  score: number | null;
  maxScore: number;
  scorePublished?: boolean;
  submittedProblems?: number;
  totalProblems?: number;
}

export interface Profile {
  id: string;
  name: string;
  email: string;
  studentCode: string | null;
  department?: string | null;
  practiceSubmissions?: number;
  transcript?: CourseGrade[];
  history: ActivityHistoryItem[];
}

const DEFAULT_TRANSCRIPT: CourseGrade[] = [
  { code: "IT004", name: "Cơ sở dữ liệu", credits: 4, process: null, practice: null, midterm: null, final: null, gpa: 0 },
];

const statusesVi: Record<string, string> = {
  in_progress: "Đang làm bài",
  pending: "Chờ chấm điểm",
  unpublished: "Chưa công bố điểm",
  graded: "Đã công bố điểm",
  not_submitted: "Chưa nộp bài",
};

const statusesEn: Record<string, string> = {
  in_progress: "In progress",
  pending: "Pending grading",
  unpublished: "Scores not published",
  graded: "Published",
  not_submitted: "Not submitted",
};

function formatGrade(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

export function ProfilePage() {
  const { language } = useLanguage();
  const isVi = language !== "en";
  const [profile, setProfile] = useState<Profile | null>(null);
  const [error, setError] = useState("");
  const [activeTab, setActiveTab] = useState<"transcript" | "history">("transcript");

  useEffect(() => {
    let active = true;
    apiFetch<Profile>("/api/student/profile")
      .then((data) => {
        if (active) setProfile(data);
      })
      .catch((e) => {
        if (active) setError(e.message);
      });
    return () => {
      active = false;
    };
  }, []);

  const rawTranscript = (profile?.transcript && profile.transcript.length > 0)
    ? profile.transcript
    : DEFAULT_TRANSCRIPT;

  // Chỉ hiển thị môn Cơ sở dữ liệu (IT004)
  const transcript = rawTranscript.filter((item) => item.code === "IT004");

  const totalCredits = transcript.reduce((sum, item) => sum + (item.credits || 0), 0);
  const totalScorePoints = transcript.reduce((sum, item) => sum + (item.gpa * item.credits), 0);
  const cumulativeGpa = totalCredits > 0 ? (totalScorePoints / totalCredits).toFixed(2) : "0.00";

  const initials = profile?.name
    ? profile.name.split(" ").filter(Boolean).map((w) => w[0]).slice(-2).join("").toUpperCase()
    : "SV";

  const statuses = isVi ? statusesVi : statusesEn;

  return (
    <section className="page student-profile-page">
      {error && <p className="admin-notice" role="alert">{error}</p>}

      {profile ? (
        <>
          <div className="student-profile-hero">
            <div className="student-profile-identity">
              <div className="student-profile-avatar">{initials}</div>
              <div className="student-profile-meta">
                <h2>
                  {profile.name}
                  <span className="student-role-pill">{isVi ? "Sinh viên" : "Student"}</span>
                </h2>
                <div className="student-profile-details">
                  <span>
                    <strong>MSSV:</strong> {profile.studentCode || (isVi ? "Chưa cập nhật" : "Not provided")}
                  </span>
                  <span>•</span>
                  <span>{profile.email}</span>
                  <span>•</span>
                  <span>{profile.department || "Khoa Hệ thống Thông tin"}</span>
                </div>
              </div>
            </div>

            <div className="student-profile-stats">
              <div className="student-stat-box">
                <span className="student-stat-value">{profile.practiceSubmissions ?? 0}</span>
                <span className="student-stat-label">{isVi ? "Lượt luyện tập" : "Practice submissions"}</span>
              </div>
              <div className="student-stat-box">
                <span className="student-stat-value">{profile.history.length}</span>
                <span className="student-stat-label">{isVi ? "Kỳ thi & bài tập" : "Completed tests"}</span>
              </div>
            </div>
          </div>

          <div className="student-profile-tabs" role="tablist">
            <button
              type="button"
              role="tab"
              aria-selected={activeTab === "transcript"}
              className={`student-profile-tab ${activeTab === "transcript" ? "is-active" : ""}`}
              onClick={() => setActiveTab("transcript")}
            >
              <GraduationCap size={16} />
              <span>{isVi ? "Bảng điểm học phần" : "Transcript"}</span>
              <span className="student-tab-badge">{transcript.length}</span>
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={activeTab === "history"}
              className={`student-profile-tab ${activeTab === "history" ? "is-active" : ""}`}
              onClick={() => setActiveTab("history")}
            >
              <Clock size={16} />
              <span>{isVi ? "Lịch sử học tập & dự thi" : "Learning and examination history"}</span>
              <span className="student-tab-badge">{profile.history.length}</span>
            </button>
          </div>

          {activeTab === "transcript" && (
            <div className="student-table-card">
              <div className="student-table-card-header">
                <div>
                  <h3>{isVi ? "Bảng điểm học phần" : "Academic Transcript"}</h3>
                  <p>{isVi ? "Chi tiết điểm các môn học theo chương trình đào tạo" : "Detailed module and course grades for current curriculum"}</p>
                </div>
                <div className="student-table-meta-tag">
                  <Award size={13} />
                  <span>Học kỳ 1 · Năm học 2024 - 2025</span>
                </div>
              </div>

              <div className="student-table-scroll">
                <Table className="profile-table transcript-table">
                  <thead>
                    <tr>
                      <th style={{ width: "14%" }}>{isVi ? "Mã môn" : "Course code"}</th>
                      <th style={{ width: "38%" }}>{isVi ? "Tên môn học" : "Course name"}</th>
                      <th style={{ width: "8%", textAlign: "center" }}>TC</th>
                      <th style={{ width: "8%", textAlign: "center" }}>QT</th>
                      <th style={{ width: "8%", textAlign: "center" }}>TH</th>
                      <th style={{ width: "8%", textAlign: "center" }}>GK</th>
                      <th style={{ width: "8%", textAlign: "center" }}>CK</th>
                      <th style={{ width: "8%", textAlign: "center" }}>{isVi ? "Điểm TB" : "Grade"}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {transcript.map((row) => (
                      <tr key={row.code} className={row.code === "IT004" ? "highlight-row" : undefined}>
                        <td className="cell-code">{row.code}</td>
                        <td className="cell-name">{row.name}</td>
                        <td className="cell-center">{row.credits}</td>
                        <td className="cell-center">{formatGrade(row.process)}</td>
                        <td className="cell-center">{formatGrade(row.practice)}</td>
                        <td className="cell-center">{formatGrade(row.midterm)}</td>
                        <td className="cell-center">{formatGrade(row.final)}</td>
                        <td className="cell-gpa">{row.gpa > 0 ? formatGrade(row.gpa) : "—"}</td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </div>

              <div className="transcript-summary-footer">
                <div>
                  <small className="muted">{isVi ? "Thang điểm: 10.0 · Môn: IT004 - Cơ sở dữ liệu" : "Scale: 10.0 · Course: IT004 - Database"}</small>
                </div>
                <div>
                  <small className="muted">{isVi ? "Khoa Hệ thống Thông tin - UIT" : "Faculty of Information Systems - UIT"}</small>
                </div>
              </div>
            </div>
          )}

          {activeTab === "history" && (
            <div className="student-table-card">
              <div className="student-table-card-header">
                <div>
                  <h3>{isVi ? "Lịch sử thi cử & bài tập" : "Examination and Assignment History"}</h3>
                  <p>{isVi ? "Lịch sử các bài nộp chính thức trong các lớp học và kỳ thi" : "History of official submissions in classes and exams"}</p>
                </div>
                <div className="student-table-meta-tag">
                  <BookOpen size={13} />
                  <span>{profile.history.length} {isVi ? "bản ghi" : "records"}</span>
                </div>
              </div>

              <div className="student-table-scroll">
                <Table className="profile-table history-table">
                  <thead>
                    <tr>
                      <th style={{ width: "42%" }}>{isVi ? "Hoạt động" : "Activity"}</th>
                      <th style={{ width: "22%" }}>{isVi ? "Thời gian nộp" : "Date"}</th>
                      <th style={{ width: "16%" }}>{isVi ? "Trạng thái" : "Status"}</th>
                      <th style={{ width: "12%" }}>{isVi ? "Điểm số" : "Score"}</th>
                      <th style={{ width: "8%", textAlign: "center" }}>{isVi ? "Chi tiết" : "Action"}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {profile.history.map((row) => {
                      const rawDate = row.submittedAt || row.date;
                      const isExamKind = row.kind === "exam" || row.kind === "Examination" || row.kind === "ExamSession";
                      const derivedStatus = row.status || (row.scorePublished ? "graded" : rawDate ? "unpublished" : "not_submitted");
                      const canViewDetails = derivedStatus === "graded" || Boolean(row.scorePublished);
                      return (
                      <tr key={row.id}>
                        <td>
                          <span className="activity-title">{row.title}</span>
                          <span className="activity-type">
                            {isExamKind
                              ? (isVi ? "Kỳ thi / Ca thi" : "Examination / exam session")
                              : (isVi ? "Bài tập" : "Assignment")}
                          </span>
                        </td>
                        <td className="muted">{rawDate ? parseServerDateTime(rawDate).toLocaleString(isVi ? "vi-VN" : "en-US") : "—"}</td>
                        <td>
                          <Status value={statuses[derivedStatus] || derivedStatus} />
                        </td>
                        <td className="cell-score">
                          {row.score === null
                            ? "—"
                            : `${formatPoints(row.score)} / ${formatPoints(row.maxScore)}`}
                        </td>
                        <td style={{ textAlign: "center" }}>
                          {canViewDetails && (
                            <Link
                              className="view-link"
                              to={`/submissions?context=${encodeURIComponent(row.id)}`}
                              title={isVi ? "Xem bài nộp" : "View submissions"}
                            >
                              <ExternalLink size={14} />
                            </Link>
                          )}
                        </td>
                      </tr>
                    );})}
                    {!profile.history.length && (
                      <tr>
                        <td colSpan={5} style={{ textAlign: "center", padding: "28px 14px", color: "var(--muted)" }}>
                          {isVi ? "Chưa có lịch sử làm bài tập hay kỳ thi." : "No assignment or examination history yet."}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </Table>
              </div>
            </div>
          )}
        </>
      ) : (
        !error && <p role="status">{isVi ? "Đang tải hồ sơ sinh viên…" : "Loading profile…"}</p>
      )}
    </section>
  );
}
