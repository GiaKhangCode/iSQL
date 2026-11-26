import { toast } from "@/shared/ui/StackingToasts";

/**
 * Thông báo kết quả của một thao tác (thêm, lưu, xóa, nhập...). Dùng thay cho banner inline:
 * title nói chuyện gì đã xảy ra, description là chi tiết (tên đối tượng, lý do lỗi từ máy chủ).
 * Chuỗi truyền vào phải được dịch sẵn bởi nơi gọi.
 */
const ERROR_DURATION = 8000;

function detail(error: unknown, translate?: (text: string) => string): string | undefined {
  const message = error instanceof Error ? error.message : typeof error === "string" ? error : "";
  const text = message.trim();
  if (!text) return undefined;
  return translate ? translate(text) : text;
}

export const notify = {
  success: (title: string, description?: string) => toast.show({ variant: "success", title, description }),
  info: (title: string, description?: string) => toast.show({ variant: "info", title, description }),
  error: (title: string, description?: string) =>
    toast.show({ variant: "error", title, description, duration: ERROR_DURATION }),
  /** Thao tác thất bại: title là việc không làm được, description là lý do máy chủ trả về (nếu khác title). */
  fail: (title: string, error: unknown, translate?: (text: string) => string) => {
    const reason = detail(error, translate);
    return toast.show({
      variant: "error",
      title,
      description: reason && reason !== title ? reason : undefined,
      duration: ERROR_DURATION,
    });
  },
};
