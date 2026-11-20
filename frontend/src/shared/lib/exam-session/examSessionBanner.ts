import type { CSSProperties } from "react";

const bannerVariants = 3;

function bannerHash(value: string) {
  let hash = 0;
  for (let index = 0; index < value.length; index += 1) {
    hash = (hash * 31 + value.charCodeAt(index)) | 0;
  }
  return Math.abs(hash);
}

export function examSessionBannerClass(id: string) {
  return `exam-session-banner-visual-${bannerHash(id) % bannerVariants}`;
}

/** Resolve API-relative banner paths against the configured backend origin. */
export function resolveExamSessionBannerUrl(bannerUrl?: string | null): string | undefined {
  if (!bannerUrl) return undefined;
  if (/^(?:[a-z]+:|data:|blob:|\/\/)/i.test(bannerUrl)) return bannerUrl;
  const apiBase = import.meta.env.VITE_API_URL || window.location.origin;
  return new URL(bannerUrl, apiBase.endsWith("/") ? apiBase : apiBase + "/").toString();
}

/** The cropped, display-sized banner is the only banner students should see. */
export function examSessionBannerStyle(bannerUrl?: string | null): CSSProperties | undefined {
  const resolvedUrl = resolveExamSessionBannerUrl(bannerUrl);
  if (!resolvedUrl) return undefined;
  return {
    backgroundImage: `linear-gradient(90deg, rgba(14, 14, 51, .82), rgba(14, 14, 51, .48)), url(${JSON.stringify(resolvedUrl)})`,
  };
}
