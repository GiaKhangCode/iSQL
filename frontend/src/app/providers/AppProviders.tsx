import type { ReactNode } from "react";
import { AuthProvider } from "@/app/providers/AuthContext";
import { ThemeProvider } from "@/app/providers/ThemeContext";
import { LanguageProvider } from "@/app/providers/LanguageContext";
import { ToastProvider } from "@/shared/ui/StackingToasts";

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider>
      <AuthProvider>
        <LanguageProvider>
          <ToastProvider>{children}</ToastProvider>
        </LanguageProvider>
      </AuthProvider>
    </ThemeProvider>
  );
}
