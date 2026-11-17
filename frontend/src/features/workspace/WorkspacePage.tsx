import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useParams, useSearchParams, Link } from "react-router-dom";
import { AppHeader } from "@/app/shells/AppHeader";
import { Empty, Loading } from "@/shared/ui/ui";
import { useLoad } from "@/shared/hooks/useLoad";
import { useAuth } from "@/app/providers/AuthContext";
import { studentApi } from "@/shared/api/studentApi";
import { ProblemWorkspace } from "@/features/workspace/ProblemWorkspace";

import { WorkspaceExamProvider } from "@/features/workspace/components/WorkspaceExamContext";

export function WorkspacePage() {
  const [params] = useSearchParams();
  const { session } = useAuth();
  return <WorkspaceExamProvider key={`${session?.id}:${params.get("source")}:${params.get("context")}`}><WorkspaceContent /></WorkspaceExamProvider>;
}

function WorkspaceContent() {
  const { t: tr } = useLanguage("workspace");
  const { problemId = "" } = useParams();
  const { session } = useAuth();
  const [params] = useSearchParams();
  const context = params.get("context") || (params.get("source") || "Practice");
  const { data, loading, error } = useLoad(
    () => studentApi.getProblem(problemId, context),
    [problemId, context],
  );
  if (loading)
    return (
      <>
        <AppHeader
          workspace={{ title: "Loading problem…", number: "", topic: "" }}
        />
        <main id="main-content">
          <Loading variant="workspace" />
        </main>
      </>
    );
  if (error || !data)
    return (
      <>
        <AppHeader />
        <main id="main-content">
          <Empty title={tr("Problem unavailable")}>
            <TranslatedText>{tr(error) || tr("This problem ID does not exist.")}</TranslatedText>{" "}
            <Link to={session?.examSessionId ? "/examinations" : "/dashboard"}><TranslatedText>{session?.examSessionId ? tr("Back to examinations") : tr("Back to dashboard")}</TranslatedText></Link>
          </Empty>
        </main>
      </>
    );
  return <ProblemWorkspace key={`${session?.id}:${context}:${data.id}`} problem={data} />;
}
