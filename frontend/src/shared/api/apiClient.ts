import { storage } from "@/shared/api/storage";

export class ApiError extends Error {
  constructor(message: string, public status: number, public data?: any) {
    super(message);
    this.name = 'ApiError';
  }
}

function translateRawMessage(msg: string): string {
  const trimmed = msg.trim();
  const errorTranslations: Record<string, string> = {
    "One or more selected classes are unavailable.": "Một hoặc nhiều lớp học đã chọn không còn khả dụng hoặc không tồn tại.",
    "End time must be after start time.": "Thời gian kết thúc phải sau thời gian bắt đầu.",
    "Each class can be selected only once.": "Mỗi lớp học chỉ có thể được chọn một lần.",
    "Only teachers can view audience counts.": "Chỉ giảng viên hoặc giáo vụ mới có quyền xem thông tin đối tượng.",
    "Only teachers can search students.": "Chỉ giảng viên hoặc giáo vụ mới có quyền tìm kiếm sinh viên.",
    "Invalid audience type.": "Hình thức đối tượng tham gia không hợp lệ.",
    "Published contests need a short description and description.": "Cuộc thi đã công bố cần có mô tả ngắn và mô tả chi tiết.",
    "Published activities need valid start and end times.": "Hoạt động đã công bố cần có thời gian bắt đầu và kết thúc hợp lệ.",
    "All-students contests cannot select classes.": "Cuộc thi mở cho tất cả sinh viên không thể chọn lớp học.",
    "Select at least one section for each problem.": "Hãy chọn ít nhất một phần bài làm cho từng bài toán.",
  };
  if (errorTranslations[trimmed]) return errorTranslations[trimmed];
  if (trimmed.includes("One or more selected classes are unavailable")) {
    return trimmed.replace("One or more selected classes are unavailable", "Một hoặc nhiều lớp học đã chọn không còn khả dụng hoặc không tồn tại");
  }
  return msg;
}

export function formatErrorMessage(data: any): string {
  if (!data) return "Máy chủ không thể hoàn thành yêu cầu.";
  if (typeof data === "string") {
    const trimmed = data.trim();
    if ((trimmed.startsWith("[") && trimmed.endsWith("]")) || (trimmed.startsWith("{") && trimmed.endsWith("}"))) {
      try {
        const parsed = JSON.parse(trimmed);
        return formatErrorMessage(parsed);
      } catch {
        return translateRawMessage(data);
      }
    }
    return translateRawMessage(data);
  }
  if (data?.errors && typeof data.errors === "object" && !Array.isArray(data.errors)) {
    const fieldMessages = Object.entries(data.errors)
      .flatMap(([field, msgs]) => {
        const list = Array.isArray(msgs) ? msgs : [msgs];
        return list.filter(Boolean).map((m) => `${field}: ${translateRawMessage(String(m))}`);
      });
    if (fieldMessages.length > 0) return fieldMessages.join("; ");
  }
  const detail = data?.detail ?? data;
  if (typeof detail === "string") return translateRawMessage(detail);
  if (Array.isArray(detail)) {
    return detail.map((err: any) => {
      if (typeof err === "string") return err;
      const locParts = Array.isArray(err.loc)
        ? err.loc
            .filter((p: any) => p !== "body" && p !== "[key]")
            .map((p: any) => {
              if (typeof p === "number") return `#${p + 1}`;
              const fieldMap: Record<string, string> = {
                sessions: "Ca thi",
                problems: "Bài toán",
                questionSelection: "Cấu hình câu hỏi",
                _autoRules: "Quy tắc tự động",
                _sharedQuestions: "Câu hỏi chung",
                title: "Tiêu đề",
                starts: "Giờ bắt đầu",
                ends: "Giờ kết thúc",
                exam_date: "Ngày thi",
                class_ids: "Lớp học",
                student_emails: "Sinh viên",
                room: "Phòng thi",
                capacity: "Sức chứa",
              };
              return fieldMap[p] || p;
            })
        : [];
      const loc = locParts.join(" > ");
      let msg = err.msg || "Giá trị không hợp lệ";
      if (typeof msg === "string") {
        msg = msg.replace(/^Value error,\s*/i, "");
        if (msg.includes("Input should be a valid list")) {
          msg = "Dữ liệu phải là danh sách";
        } else if (msg.includes("Input should be")) {
          msg = msg.replace("Input should be", "Giá trị phải là");
        }
        if (msg.includes("Field required")) {
          msg = "Trường này là bắt buộc";
        }
      }
      return loc ? `${loc}: ${msg}` : msg;
    }).join("; ");
  }
  if (detail && typeof detail === "object") {
    if (detail.message) return translateRawMessage(String(detail.message));
    return JSON.stringify(detail);
  }
  return "Máy chủ không thể hoàn thành yêu cầu.";
}

export async function apiFetch<T = any>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const token = storage.get("sql-practice:access-token");
  
  const headers: Record<string, string> = {
    ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }),
    ...((options.headers as any) || {}),
  };

  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  const baseUrl = import.meta.env.VITE_API_URL || "";
  const fullUrl = endpoint.startsWith("http") ? endpoint : `${baseUrl}${endpoint}`;

  const response = await fetch(fullUrl, {
    credentials: "include",
    ...options,
    headers,
  });

  const isJson = response.headers.get("content-type")?.includes("application/json");
  const data = isJson ? await response.json() : await response.text();

  if (!response.ok) {
    const errorMsg = formatErrorMessage(data);
    throw new ApiError(errorMsg, response.status, data);
  }

  return data as T;
}
