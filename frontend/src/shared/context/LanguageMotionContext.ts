import { createStableContext } from "@/shared/context/stableContext";
import type { LanguageMotionController } from "@/i18n/motion";

export const LanguageMotionContext = createStableContext<LanguageMotionController | null>("sqluit.language-motion", null);
