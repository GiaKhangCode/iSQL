# Frontend `src` Refactor Report

Date: 2026-11-03  
Branch: `codex/frontend-src-refactor`

## Result and recovery status

The feature-first reorganization, source splits, type moves, import updates, and audits are complete. Route paths and the 54-entry route table remain unchanged relative to the pre-task working-tree snapshot, which already included office routes. That snapshot was not the clean Git HEAD. The two naming fixes are `ContestListPage.tsx → ContestsListPage.tsx` (matching its exported name) and the contest hook remains `useContestWorkspace.tsx` because it still contains JSX expressions for its returned render callbacks.

There is **no separate backup archive** and no commit or staged snapshot. The work remains in the working tree. Git’s current HEAD still contains the tracked originals, but files created during this refactor are only in the working tree until committed or copied. The workspace also contains unrelated backend changes and other existing edits; no phase commits were made because staging or committing the broad mixed diff could capture unrelated work. Avoid resetting or cleaning the working tree if you need to retain the current changes.

## Old → new path map

Paths below are relative to `frontend/`. The deleted legacy `AiChatPanel.tsx` has no replacement; see “Intentionally removed or retained” below.

### Shared components, UI, and contexts

| Old path | New path |
|---|---|
| `src/components/Activity.tsx` | `src/shared/components/Activity.tsx` |
| `src/components/AdminHeader.tsx` | `src/app/shells/AdminHeader.tsx` |
| `src/components/AppHeader.tsx` | `src/app/shells/AppHeader.tsx` |
| `src/components/AppearanceSettings.tsx`, `AppearanceSettings.css` | `src/shared/ui/AppearanceSettings.tsx`, `AppearanceSettings.css` |
| `src/components/Calendar.tsx` | `src/shared/components/Calendar.tsx` |
| `src/components/ContestBannerCrop.tsx` | `src/shared/components/ContestBannerCrop.tsx` |
| `src/components/DatabaseDiagram.tsx` | `src/shared/components/DatabaseDiagram.tsx` |
| `src/components/LanguageSwitch.tsx`, `LanguageSwitch.css` | `src/shared/ui/LanguageSwitch.tsx`, `LanguageSwitch.css` |
| `src/components/ProblemDatabaseDescription.tsx` | `src/shared/components/ProblemDatabaseDescription.tsx` |
| `src/components/ProblemExpectedOutput.tsx` | `src/shared/components/ProblemExpectedOutput.tsx` |
| `src/components/ProblemMarkdown.tsx` | `src/shared/components/ProblemMarkdown.tsx` |
| `src/components/ProtectedRoute.tsx` | `src/app/routes/ProtectedRoute.tsx` |
| `src/components/RoleHeader.tsx` | `src/app/shells/RoleHeader.tsx` |
| `src/components/SubmissionDetails.tsx` | `src/shared/components/SubmissionDetails.tsx` |
| `src/components/TeacherHeader.tsx` | `src/app/shells/TeacherHeader.tsx` |
| `src/components/TranslatedText.tsx`, `TranslatedText.css` | `src/shared/ui/TranslatedText.tsx`, `TranslatedText.css` |
| `src/components/WorkspaceTools.tsx` | `src/shared/components/WorkspaceTools.tsx` |
| `src/components/sqlEditorExtensions.ts` | `src/shared/components/sqlEditorExtensions.ts` |
| `src/components/ui.tsx` | `src/shared/ui/ui.tsx` |
| `src/components/useLoad.ts` | `src/shared/hooks/useLoad.ts` |
| `src/context/AuthContext.tsx` | `src/app/providers/AuthContext.tsx` |
| `src/context/LanguageContext.tsx` | `src/shared/context/LanguageContext.tsx` |
| `src/context/LanguageMotionContext.ts` | `src/shared/context/LanguageMotionContext.ts` |
| `src/context/ThemeContext.tsx` | `src/shared/context/ThemeContext.tsx` |
| `src/context/stableContext.ts` | `src/shared/context/stableContext.ts` |

### Pages and feature services

| Old path | New path |
|---|---|
| `src/pages/admin/AdminPages.tsx` | `src/features/admin/AdminPages.tsx` (split modules listed below) |
| `src/pages/auth/AuthPages.tsx` | `src/features/auth/AuthPages.tsx` (split modules listed below) |
| `src/pages/student/AssignmentDetailPage.tsx` | `src/features/student/AssignmentDetailPage.tsx` |
| `src/pages/student/AssignmentWorkPage.tsx` | `src/features/student/AssignmentWorkPage.tsx` |
| `src/pages/student/AssignmentsPage.tsx` | `src/features/student/AssignmentsPage.tsx` |
| `src/pages/student/ContestDetailPage.tsx` | `src/features/student/ContestDetailPage.tsx` |
| `src/pages/student/ContestDetailView.tsx` | `src/shared/components/ContestDetailView.tsx` |
| `src/pages/student/ContestsPage.tsx` | `src/features/student/ContestsPage.tsx` |
| `src/pages/student/DashboardPage.tsx` | `src/features/student/DashboardPage.tsx` |
| `src/pages/student/SubmissionsPage.tsx` | `src/features/student/SubmissionsPage.tsx` |
| `src/pages/student/workspace/ContestWorkspacePage.tsx` | `src/features/workspace/ContestWorkspacePage.tsx` |
| `src/pages/student/workspace/DdlWorkspace.tsx` | `src/features/workspace/DdlWorkspace.tsx` |
| `src/pages/student/workspace/DmlWorkspace.tsx` | `src/features/workspace/DmlWorkspace.tsx` |
| `src/pages/student/workspace/ProblemWorkspace.tsx` | `src/features/workspace/ProblemWorkspace.tsx` |
| `src/pages/student/workspace/SqlWorkspace.tsx` | `src/features/workspace/SqlWorkspace.tsx` |
| `src/pages/student/workspace/WorkspacePage.tsx` | `src/features/workspace/WorkspacePage.tsx` |
| `src/pages/student/workspace/useContestWorkspace.tsx` | `src/features/workspace/useContestWorkspace.tsx` |
| `src/pages/student/workspace/components/ProblemWorkspaceLayoutContext.tsx` | `src/features/workspace/components/ProblemWorkspaceLayoutContext.tsx` |
| `src/pages/student/workspace/components/SqlErrorMessage.tsx` | `src/shared/components/SqlErrorMessage.tsx` |
| `src/pages/student/workspace/components/StageEditor.tsx` | `src/features/workspace/components/StageEditor.tsx` |
| `src/pages/student/workspace/components/WorkspaceDatabasePanel.tsx` | `src/shared/components/WorkspaceDatabasePanel.tsx` |
| `src/pages/student/workspace/components/WorkspaceEditorStatus.tsx` | `src/features/workspace/components/WorkspaceEditorStatus.tsx` |
| `src/pages/student/workspace/components/WorkspaceExamContext.tsx` | `src/features/workspace/components/WorkspaceExamContext.tsx` |
| `src/pages/student/workspace/components/WorkspaceNavigation.tsx` | `src/features/workspace/components/WorkspaceNavigation.tsx` |
| `src/pages/student/workspace/components/WorkspaceProblemSwitcher.tsx` | `src/features/workspace/components/WorkspaceProblemSwitcher.tsx` |
| `src/pages/student/workspace/components/WorkspaceResizeHandle.tsx` | `src/features/workspace/components/WorkspaceResizeHandle.tsx` |
| `src/pages/student/workspace/components/WorkspaceSolutionSwitcher.tsx` | `src/features/workspace/components/WorkspaceSolutionSwitcher.tsx` |
| `src/pages/student/workspace/components/WorkspaceSubmissionPanel.tsx` | `src/features/workspace/components/WorkspaceSubmissionPanel.tsx` |
| `src/pages/teacher/BuilderPages.tsx` | `src/features/teacher/builder/BuilderPages.tsx` (split modules listed below) |
| `src/pages/teacher/BuilderProblemSettings.tsx` | `src/features/teacher/builder/BuilderProblemSettings.tsx` |
| `src/pages/teacher/ClassesPage.tsx` | `src/features/teacher/ClassesPage.tsx` |
| `src/pages/teacher/ContestListPage.tsx` | `src/features/teacher/ContestsListPage.tsx` |
| `src/pages/teacher/DatabaseLibraryPage.tsx` | `src/features/teacher/DatabaseLibraryPage.tsx` |
| `src/pages/teacher/ProblemEditorPage.tsx` | `src/features/teacher/ProblemEditorPage.tsx` |
| `src/pages/teacher/ResultsPages.tsx` | `src/features/teacher/ResultsPages.tsx` (split modules listed below) |
| `src/pages/teacher/TeacherLandingPages.tsx` | `src/features/teacher/TeacherLandingPages.tsx` (split modules listed below) |
| `src/pages/teacher/TeacherPageParts.tsx` | `src/features/teacher/components/TeacherPageParts.tsx` |
| `src/pages/teacher/problem-editor/DatabaseDescriptionEditor.tsx` | `src/features/teacher/problem-editor/DatabaseDescriptionEditor.tsx` |
| `src/pages/teacher/problem-editor/ProblemRichTextEditor.tsx` | `src/features/teacher/problem-editor/ProblemRichTextEditor.tsx` |
| `src/pages/teacher/problem-editor/ProblemSqlEditor.tsx` | `src/features/teacher/problem-editor/ProblemSqlEditor.tsx` |
| `src/pages/teacher/problem-editor/TableColumnFilter.tsx` | `src/features/teacher/problem-editor/TableColumnFilter.tsx` |
| `src/pages/teacher/problem-editor/TeacherSchemaDiagram.tsx` | `src/features/teacher/problem-editor/TeacherSchemaDiagram.tsx` |
| `src/pages/teacher/problem-editor/TeacherSqlSolutions.tsx` | `src/features/teacher/problem-editor/TeacherSqlSolutions.tsx` |
| `src/services/adminService.ts` | `src/features/admin/adminService.ts` |
| `src/services/apiClient.ts` | `src/shared/api/apiClient.ts` |
| `src/services/authService.ts` | `src/features/auth/authService.ts` |
| `src/services/storage.ts` | `src/shared/api/storage.ts` |
| `src/services/studentApi.ts` | `src/shared/api/studentApi.ts` implementation and `src/features/student/studentApi.ts` feature facade |
| `src/services/studentPreferences.ts` | `src/features/student/studentPreferences.ts` |
| `src/services/teacherService.ts` | `src/shared/api/teacherService.ts` |

### Utilities

| Old path | New path |
|---|---|
| `src/utils/academicTerms.ts` | `src/shared/lib/datetime/academicTerms.ts` |
| `src/utils/contestBanner.ts` | `src/shared/lib/contest/contestBanner.ts` |
| `src/utils/contestMarkdown.ts` | `src/shared/lib/contest/contestMarkdown.ts` |
| `src/utils/databaseLibraryImport.ts` | `src/shared/lib/sql/databaseLibraryImport.ts` |
| `src/utils/featuredContests.ts` | `src/shared/lib/contest/featuredContests.ts` |
| `src/utils/parseDatabaseSchema.ts` | `src/shared/lib/sql/parseDatabaseSchema.ts` |
| `src/utils/parseSeedData.ts` | `src/shared/lib/sql/parseSeedData.ts` |
| `src/utils/serverDateTime.ts` | `src/shared/lib/datetime/serverDateTime.ts` |
| `src/utils/sqlErrors.ts` | `src/shared/lib/sql/sqlErrors.ts` |
| `src/utils/sqlQuestionDrafts.ts` | `src/shared/lib/sql/sqlQuestionDrafts.ts` |
| `src/utils/themeReveal.ts` | `src/shared/lib/theme/themeReveal.ts` |
| `src/utils/workspaceParts.ts` | `src/shared/lib/workspace/workspaceParts.ts` |
| `src/utils/workspacePreferences.ts` | `src/shared/lib/workspace/workspacePreferences.ts` |

### Splits and extracted shared code

- `AdminPages.tsx` keeps its feature entry and delegates page groups to `AdminShared.tsx`, `AdminUsersPages.tsx`, `CoursePages.tsx`, `LecturerPages.tsx`, `OverviewPage.tsx`, and `PracticeCatalogPage.tsx`.
- `AuthPages.tsx` is split with `AuthParts.tsx` and `AuthRecoveryPages.tsx`.
- `BuilderPages.tsx` keeps the builder page and uses `BuilderShared.tsx` for helpers, defaults, preview, date input, and types.
- `ProblemEditorPage.tsx` uses `problem-editor/ProblemEditorHelpers.tsx` for top-level helpers.
- `DatabaseLibraryPage.tsx` uses `DatabaseLibraryParts.tsx` for helpers.
- `TeacherLandingPages.tsx` re-exports `ProblemLibraryPage.tsx` and `AssignmentsListPage.tsx`; common empty state is `components/TeacherEmptyState.tsx`.
- `ResultsPages.tsx` re-exports `ResultsDashboardPage.tsx` and `ResultsList.tsx`.
- `SqlWorkspace.tsx` retains the student workspace; `ContestSqlWorkspace.tsx` holds the contest-specific workspace.
- `useContestWorkspace.tsx` delegates diagram and card markup to `components/ContestWorkspaceSchemaViews.tsx` while keeping the returned hook fields.
- `ContestDetailView.tsx` now receives student-specific callbacks and components from its student caller so the shared component does not import a feature.
- `ProblemEditorPage.tsx` and `DatabaseLibraryPage.tsx` share `shared/components/DatasetDataPreview.tsx`. List pages use `shared/components/TeacherListPagination.tsx`. Countdown formatting is in `shared/lib/datetime/formatCountdown.ts`; reduced-motion behavior is in `shared/hooks/useReducedMotion.ts`.

## New directory tree

```text
src/
├── app/                 App route table, providers, guards, and role shells
├── data/                Existing domain data and type definitions
├── features/
│   ├── admin/           Admin pages and service
│   ├── auth/            Auth pages, layout, and service
│   ├── office/          Examination and admission pages
│   ├── student/         Student pages and feature API facade
│   ├── teacher/         Teacher pages, builder, library, editor, results
│   └── workspace/       Student and contest workspaces, hooks and components
├── i18n/                Translation lookup, locales, and language motion
├── shared/
│   ├── api/             Shared API clients and storage
│   ├── components/      Cross-feature domain components
│   ├── context/         Low-level language, theme, and stable contexts
│   ├── hooks/           Shared hooks
│   ├── lib/              Topic-grouped pure helpers
│   ├── types/            Cross-feature type declarations
│   └── ui/               Reusable interface primitives
└── styles/               Global and route-level stylesheets
```

`src/README.md` documents placement, naming, the `@/` alias, and import direction.

## Dependency direction and structure checks

Rules: `shared/*` imports neither `features/*` nor `app/*`; features do not import other features; `app/*` may compose all layers. The dependency scan included type-only and dynamic imports and found **197 source modules, 0 dependency violations, and 0 circular paths**. The old root path scan found no legacy `src/pages`, `src/components`, `src/services`, or `src/utils` references in the audited source scripts, tests, READMEs, Vite config, and TypeScript configs. The i18n audit script now scans the new feature and shared locations.

The `@/` alias is configured in Vite and TypeScript. Route audit: all 54 route paths and their order match the recorded baseline. Lazy route imports still produce split route chunks.

## Deduplication, i18n, and types

- Shared countdown, dataset preview, pagination, and reduced-motion utilities were consolidated without changing call-site state ownership or output classes. Dataset variants preserve their original DOM elements and class names.
- The CSV import dialogs were left separate because their props and rendered content differ. The save/exit guard logic was left local because equivalence was not established.
- Repeated i18n keys were retained. Examples with identical values across scopes include “Show scores after submission”, “Submission limit”, “Submission limit reached”, “Unlimited”, “Score hidden by instructor”, and “Question {value0} {value1}”. No key text was changed.
- Type-only declarations were consolidated under `shared/types`; inline `import("…").Type` usages were replaced with type imports where applicable. A TypeScript AST scan found 106 `any` type nodes across these files; they remain because the boundary shapes are not certain enough to tighten safely:

| File | Count | File | Count |
|---|---:|---|---:|
| `app/shells/RoleHeader.tsx` | 6 | `data/examinations.ts` | 1 |
| `features/admin/adminService.ts` | 13 | `features/admin/AdminShared.tsx` | 7 |
| `features/admin/AdminUsersPages.tsx` | 2 | `features/admin/CoursePages.tsx` | 2 |
| `features/admin/LecturerPages.tsx` | 7 | `features/admin/OverviewPage.tsx` | 3 |
| `features/admin/PracticeCatalogPage.tsx` | 1 | `features/student/DashboardPage.tsx` | 1 |
| `features/student/SubmissionsPage.tsx` | 3 | `features/teacher/AssignmentsListPage.tsx` | 1 |
| `features/teacher/builder/BuilderPages.tsx` | 5 | `features/teacher/ClassesPage.tsx` | 2 |
| `features/teacher/problem-editor/ProblemEditorHelpers.tsx` | 3 | `features/teacher/ProblemEditorPage.tsx` | 2 |
| `features/teacher/ProblemLibraryPage.tsx` | 2 | `features/teacher/ResultsList.tsx` | 4 |
| `features/workspace/ContestWorkspacePage.tsx` | 1 | `features/workspace/useContestWorkspace.tsx` | 7 |
| `shared/api/apiClient.ts` | 2 | `shared/api/studentApi.ts` | 9 |
| `shared/api/teacherService.ts` | 19 | `shared/components/SubmissionDetails.tsx` | 1 |
| `shared/lib/workspace/workspacePreferences.ts` | 1 | `shared/types/queryResult.ts` | 1 |

## CSS audit (report only; no cleanup performed)

The `styles.css` import order was preserved:

`tokens.css → sql-editor.css → global.css → components.css → student-updates.css → teacher.css → admin.css → unified.css → compact-tables.css → route-transitions.css → scrollbars.css → tables.css`.

The audit parsed 49 CSS files and found 31 exact duplicate selector/declaration groups (62 occurrences), potential same-selector/property overlaps from `unified.css` and `compact-tables.css` with earlier global imports, and 106 auth-form chain rules in `components.css:229–334`. Overlap findings below identify matching selectors and declarations; media-context interactions were not normalized.

### Exact duplicate rule groups

| Selector | Identical declarations | Locations |
|---|---|---|
| `.admin-table` | `display:block; width:100%` | `admin.css:286`, `unified.css:270` |
| `.admin-table thead` | `display:none` | `admin.css:287`, `unified.css:271` |
| `.dashboard-reordered > .dashboard-learning-panel` | `margin-top:8px` | `compact-tables.css:218,225` |
| `.auth-form-wrap` | `width:min(360px, calc(100% - 48px))` | `components.css:132`, `student-updates.css:1413` |
| `.auth-form-wrap .auth-card` | `display:contents` | `components.css:149,223` |
| `.auth-form-wrap input` | `border-radius:6px; font-size:13px; min-height:42px` | `components.css:157`, `student-updates.css:1419` |
| `.auth-form-wrap .auth-heading ~ form > .auth-switch` | `font-size:13px` | `components.css:282,329` |
| `.auth-form-wrap .auth-heading ~ form > .auth-switch a` | `color:var(--accent)` | `components.css:283`, `student-updates.css:311` |
| `.auth-form-wrap .auth-heading ~ form > .auth-switch a:hover` | `text-decoration:underline` | `components.css:284`, `student-updates.css:312` |
| `.auth-form-wrap .auth-heading ~ form > .auth-switch` | `color:var(--muted)` | `components.css:306,328` |
| `.auth-form-wrap .auth-heading ~ form > .auth-switch` | `line-height:20px` | `components.css:308,330` |
| `.account-identity` | `display:none` | `components.css:1523,1586` |
| `.featured-contest :is(h2, h3)` | `font-size:18px` | `components.css:1532,1669` |
| `.workspace .mobile-pane.mobile-visible` | `display:flex; height:100%` | `components.css:1781`, `workspace.css:76` |
| `to` keyframes rule | `opacity:1; transform:translateY(0)` | `database-library.css:95`, `problem-workspace.css:186` |
| `.problem-workspace .workspace-header .workspace-identity` | `display:none` | `problem-workspace.css:468,525` |
| `.problem-workspace .workspace-header .workspace-tools` | `margin-left:auto` | `problem-workspace.css:475`, `workspace.css:150` |
| `.problem-workspace .workspace-header .problem-workspace-tabs` | `margin-left:0` | `problem-workspace.css:476`, `workspace.css:151` |
| `.practice-main` | `min-width:0` | `student-updates.css:350,1526` |
| `.teacher-schema-editor` | `min-height:203px` | `teacher.css:126,538` |
| `.teacher-solution-editor` | `min-height:146px` | `teacher.css:127,540` |
| `.teacher-library-table th:nth-child(1)` | `width:10%` | `teacher.css:168`, `unified.css:36` |
| `.teacher-library-table th:nth-child(2)` | `width:34%` | `teacher.css:169`, `unified.css:37` |
| `.teacher-library-table th:nth-child(3)` | `width:15%` | `teacher.css:170`, `unified.css:38` |
| `.teacher-library-table th:nth-child(4)` | `width:25%` | `teacher.css:171`, `unified.css:39` |
| `.teacher-library-table th:nth-child(5)` | `width:16%` | `teacher.css:172`, `unified.css:40` |
| `.teacher-review-code-section` | `margin-bottom:16px` | `teacher.css:475,611` |
| `.teacher-builder-layout.teacher-contest-builder-layout` | `gap:10px; grid-template-columns:200px minmax(0,1fr)` | `teacher.css:664,724` |
| `.teacher-list-filters,.teacher-problem-filters,.teacher-activity-filters,.teacher-results-filters` | `grid-template-columns:repeat(2,minmax(0,1fr))` | `unified.css:227,264` |
| `.teacher-list-table-scroll,.admin-table-wrap` | `overflow:visible` | `unified.css:250,269` |
| `.teacher-contest-page .teacher-contest-filters` | `grid-template-columns:minmax(0,1fr)` | `unified.css:489,499` |

### Earlier matching rules potentially overridden

These later rules share a selector and declaration property with an earlier global import. The CSS scan matches exact selector strings; media/supports contexts were not normalized.

| Target rule | Overlapping properties | Earlier matching declaration(s) |
|---|---|---|
| `unified.css:35 .teacher-library-table` | `min-width` | `teacher.css:166` |
| `unified.css:36–40 .teacher-library-table th:nth-child(1…5)` | `width` | `teacher.css:168–172` |
| `unified.css:49 .admin-metrics` | `grid-template-columns`, `margin` | `admin.css:192,309` |
| `unified.css:52 .admin-metrics small` | `font-size` | `admin.css:195` |
| `unified.css:53 .teacher-editor-layout,.teacher-review-layout` | `display`, `gap` | `teacher.css:527` |
| `unified.css:55 .teacher-editor-validation,.teacher-grading-panel` | `border-left`, `padding` | `teacher.css:533` |
| `unified.css:56 .teacher-editor-layout` | `grid-template-columns` | `teacher.css:100,498` |
| `unified.css:57 .teacher-review-layout` | `grid-template-columns` | `teacher.css:473,502` |
| `unified.css:59 .teacher-builder-layout,.teacher-classes-layout` | `gap`, `grid-template-columns` | `teacher.css:528` |
| `unified.css:60 .teacher-builder-main` | `min-width` | `teacher.css:293` |
| `unified.css:104 .teacher-builder-sidebar .teacher-field` | `margin-bottom` | `teacher.css:330` |
| `unified.css:117 .teacher-review-code-section` | `margin-bottom` | `teacher.css:475,611` |
| `unified.css:148 .ai-chat-input textarea` | `min-width` | `components.css:1984` |
| `unified.css:152 .teacher-code-editor,.teacher-readonly-code` | `background`, `border-radius`, `border`, `font-family`, `line-height` | `teacher.css:110` |
| `unified.css:154 .teacher-list-filters,.teacher-problem-filters,.teacher-activity-filters,.teacher-results-filters` | `grid-template-columns` | `teacher.css:504,568` |
| `unified.css:174 .admin-practice-filters` | `gap`, `grid-template-columns`, `width` | `admin.css:210,279` |
| `unified.css:178 .teacher-code-editor,.teacher-readonly-code` | `font-size` | `teacher.css:110` |
| `unified.css:181 .teacher-student-search input` | `font-size`, `min-height`, `padding` | `teacher.css:415` |
| `unified.css:184 .teacher-attempt-pill` | `min-height`, `padding` | `teacher.css:491` |
| `unified.css:189 .admin-preview-section pre` | `font-size`, `line-height` | `admin.css:245` |
| `unified.css:207 .teacher-results-table.teacher-activity-results-table th:nth-child(2)` | `width` | `teacher.css:451,461` |
| `unified.css:215 .admin-user-filter-row` | `gap`, `grid-template-columns` | `admin.css:74` |
| `unified.css:216 .admin-user-filter-row .admin-user-filters` | `margin` | `admin.css:76` |
| `unified.css:217 .admin-user-actions` | `justify-content` | `admin.css:77` |
| `unified.css:218 .admin-course-filter-row` | `gap`, `grid-template-columns` | `admin.css:82` |
| `unified.css:219 .admin-course-options` | `grid-template-columns` | `admin.css:83` |
| `unified.css:222 .teacher-editor-layout,.teacher-review-layout` | `display`, `gap` | `teacher.css:527` |
| `unified.css:223 .teacher-editor-validation,.teacher-grading-panel` | `border-left`, `border-top` | `teacher.css:533` |
| `unified.css:224 .admin-metrics` | `grid-template-columns` | `admin.css:192,309` |
| `unified.css:227 .teacher-list-filters,.teacher-problem-filters,.teacher-activity-filters,.teacher-results-filters` | `grid-template-columns` | `teacher.css:504,568` |
| `unified.css:228 .admin-practice-filters` | `gap`, `grid-template-columns` | `admin.css:210,279` |
| `unified.css:229 .admin-practice-main-filters` | `grid-template-columns` | `admin.css:211` |
| `unified.css:236 .teacher-preview-tabs` | `padding` | `teacher.css:225` |
| `unified.css:237 .teacher-student-preview-scroll` | `padding` | `teacher.css:228` |
| `unified.css:238 .teacher-student-preview-content h1` | `font-size` | `teacher.css:231` |
| `unified.css:239 .teacher-student-preview-hint` | `padding` | `teacher.css:241` |
| `unified.css:240 .teacher-student-preview-dock` | `padding` | `teacher.css:247` |
| `unified.css:249 .teacher-readonly-code` | `font-size` | `teacher.css:612` |
| `unified.css:264 .teacher-list-filters,.teacher-problem-filters,.teacher-activity-filters,.teacher-results-filters` | `grid-template-columns` | `teacher.css:504,568` |
| `unified.css:270 .admin-table` | `display`, `width` | `admin.css:101,286` |
| `unified.css:271 .admin-table thead` | `display` | `admin.css:287` |
| `unified.css:273 .admin-table td` | `border`, `display`, `min-height`, `padding` | `admin.css:103,290` |
| `unified.css:278 .admin-course-options` | `grid-template-columns` | `admin.css:83` |
| `unified.css:279 .admin-practice-main-filters` | `grid-template-columns` | `admin.css:211` |
| `unified.css:315 .auth-brand-panel` | `background` | `components.css:104` |
| `unified.css:338 .auth-wordmark` | `font-size`, `gap` | `components.css:112` |
| `unified.css:343 .auth-wordmark img` | `height`, `width` | `components.css:113` |
| `unified.css:366 .auth-pitch h2` | `font-size`, `line-height` | `components.css:115`, `student-updates.css:1435` |
| `unified.css:376 .auth-pitch p` | `color`, `font-size`, `line-height`, `max-width` | `components.css:116` |
| `unified.css:383 .auth-brand-panel > small` | `color` | `components.css:117` |
| `unified.css:411 .auth-form-panel` | `background` | `components.css:130` |
| `unified.css:414 .auth-form-wrap` | `width` | `components.css:132`, `student-updates.css:1413` |
| `unified.css:428 .auth-form-wrap .auth-heading h1` | `font-size`, `line-height` | `student-updates.css:1415` |
| `unified.css:438 .auth-form-wrap .button.primary` | `min-height` | `student-updates.css:1420` |
| `unified.css:444 .auth-brand-panel` | `border-right` | `components.css:104`, `student-updates.css:1433` |
| `unified.css:451 .auth-pitch h2` | `font-size`, `line-height` | `components.css:115`, `student-updates.css:1435` |
| `unified.css:455 .auth-pitch p` | `font-size` | `components.css:116` |
| `unified.css:470 .auth-brand-panel` | `padding` | `components.css:104`, `student-updates.css:1433` |
| `unified.css:473 .auth-wordmark` | `font-size` | `components.css:112` |
| `unified.css:476 .auth-wordmark img` | `height`, `width` | `components.css:113` |
| `unified.css:480 .auth-pitch h2` | `font-size` | `components.css:115`, `student-updates.css:1435` |
| `compact-tables.css:341 .submission-score-grid > div` | `align-items`, `gap`, `justify-content` | `student-updates.css:1573`; `align-items`, `flex-direction`, `gap` also at `student-updates.css:1603` |
| `compact-tables.css:557 .assignment-work-heading` | `margin` | `student-updates.css:1368` |
| `compact-tables.css:558 .assignment-work-heading h1` | `font-size` | `student-updates.css:1369,1391` |
| `compact-tables.css:750 .teacher-code-title` | `gap` | `teacher.css:109` |

Suggested follow-up cleanup: inspect each matching rule with its media/supports context, then remove exact duplicates; move feature-only styles only where import order and specificity are proven unchanged; replace the auth selector chain with a small set of explicit form variants after visual comparison. No CSS rules or import order were changed as part of this audit.

## Intentionally removed or retained; remaining size and risks

- `AiChatPanel.tsx` was removed only after searches across imports, lazy/dynamic imports, scripts, and tests found no references. Its legacy code also calls `studentApi` methods not present in the current service. Reintroducing it would require inventing unsupported API behavior.
- `studentPreferences`, `WorkspaceSolutionSwitcher`, `WorkspaceProblemSwitcher`, and `WorkspaceTools` were not removed. The workspace switchers and `WorkspaceTools` have no call sites in the scanned source; the student preference file also has no references. They remain available for a later explicit dead-code cleanup.
- The CSV import dialogs and save/exit guards remain separate because equivalence was not proven.
- The stateful `ProblemEditorPage.tsx` remains about 1,180 lines; the builder page and `SqlWorkspace.tsx` also remain larger than the target because deeper extraction would move stateful JSX closures. `DatabaseLibraryPage.tsx` is about 370 lines. No closure or effect behavior was rewritten to force smaller files.
- Existing Vite warning about a minified chunk over 500 kB remains. Build size was effectively flat: entry JS 1,470.49 → 1,469.23 kB (raw), entry CSS 480.43 → 480.43 kB; gzip JS 435.60 → 435.66 kB and CSS 73.69 → 73.76 kB. Page chunks total 113.36 → 113.63 kB (+0.27 kB).
- Dev server compile check returned HTTP 200 at `http://localhost:5174/`. Browser visual smoke checks were not completed: the computer-use browser tool could not establish the current URL with sufficient confidence, so no visual verification is claimed.
- No API endpoints, route paths, storage keys, CSS class names, or i18n key text were intentionally changed. CSS was audited without cleanup. The practical remaining risk is the lack of a visual browser pass, plus uncommitted work being vulnerable to a reset/clean operation.

## Verification commands and results

All commands below were run from `frontend/` after the final filename change. They passed when run sequentially. The local compiler process required permission to spawn its esbuild worker; parallel runs initially returned `EPERM`, then the same checks passed sequentially.

| Command | Result |
|---|---|
| `npm run build` | Pass; TypeScript and Vite build, 1,982 modules transformed |
| `npm test` | Pass |
| `npm run test:i18n` | Pass; 2,029 translations, 6 scopes, 126 UI files audited |
| `npm run test:language-motion` | Pass |
| `npm run test:language-client` | Pass |
| `npm run test:workspace` | Pass |
| `npm run test:theme-reveal` | Pass |
| `node tests/teacher-list-filters.test.mjs` | Pass after updating its contest page import |

The report-only route, dependency, path, CSS, type, and build-size audits are recorded above. Dev server check: `npm run dev` and HTTP 200 from `http://localhost:5174/`. Browser visual verification: unavailable as described above.

## Suggested later cleanup plan

1. Make a clean copy/commit boundary for the refactor after separating unrelated existing edits.
2. Review CSS overlaps with media queries included before deleting or moving rules.
3. Remove proven dead exports in a separate change.
4. Replace boundary `any` types only after the API response shapes are documented.
5. Complete visual smoke checks for login, student dashboard, DDL/DML/DQL workspace tabs, teacher editor, admin users, and language/theme toggles.

