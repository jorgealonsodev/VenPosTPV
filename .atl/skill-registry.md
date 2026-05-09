# Skill Registry — venpostpv

**Delegator use only.** Any agent that launches sub-agents reads this registry to resolve compact rules, then injects them directly into sub-agent prompts. Sub-agents do NOT read this registry or individual SKILL.md files.

See `_shared/skill-resolver.md` for the full resolution protocol.

## Project Context
- **Stack**: PHP 8.1+ (Symfony 6.4 FrameworkBundle, Twig, Doctrine DBAL, Messenger), Vue 3 (standalone no-bundler), Tailwind CSS (compiled), MySQL/MariaDB dual schema
- **Testing**: PHPUnit 10.5 — Unit (29 files), Integration (4 files), Functional (suite created)
- **Quality**: PHP-CS-Fixer (PSR-12 + strict_types), PHPStan level 5
- **SDD Mode**: hybrid (openspec files + engram)
- **Last updated**: 2026-05-05

## User Skills

| Trigger | Skill | Path |
|---------|-------|------|
| Building AI chat features | ai-sdk-5 | /home/jorge/.config/opencode/skills/ai-sdk-5/SKILL.md |
| Creating pull requests | branch-pr | /home/jorge/.config/opencode/skills/branch-pr/SKILL.md |
| Chrome DevTools Protocol / Network capture | cdp-chrome-debugger | /home/jorge/.config/opencode/skills/cdp-chrome-debugger/SKILL.md |
| Chrome Extension MV3 | chrome-extension-mv3 | /home/jorge/.config/opencode/skills/chrome-extension-mv3/SKILL.md |
| Django REST Framework | django-drf | /home/jorge/.config/opencode/skills/django-drf/SKILL.md |
| C# / .NET APIs / EF Core | dotnet | /home/jorge/.config/opencode/skills/dotnet/SKILL.md |
| Go + Bubbletea TUI testing | go-testing | /home/jorge/.config/opencode/skills/go-testing/SKILL.md |
| Homebrew tap release | homebrew-release | /home/jorge/.config/opencode/skills/homebrew-release/SKILL.md |
| GitHub issue creation | issue-creation | /home/jorge/.config/opencode/skills/issue-creation/SKILL.md |
| Jira epic creation | jira-epic | /home/jorge/.config/opencode/skills/jira-epic/SKILL.md |
| Jira task creation | jira-task | /home/jorge/.config/opencode/skills/jira-task/SKILL.md |
| Parallel adversarial review | judgment-day | /home/jorge/.config/opencode/skills/judgment-day/SKILL.md |
| Next.js 15 App Router | nextjs-15 | /home/jorge/.config/opencode/skills/nextjs-15/SKILL.md |
| E2E testing with Playwright | playwright | /home/jorge/.config/opencode/skills/playwright/SKILL.md |
| GitHub PR and issue review | pr-review | /home/jorge/.config/opencode/skills/pr-review/SKILL.md |
| Python testing with pytest | pytest | /home/jorge/.config/opencode/skills/pytest/SKILL.md |
| React 19 with React Compiler | react-19 | /home/jorge/.config/opencode/skills/react-19/SKILL.md |
| Angular 20+ / Scope Rule architecture | scope-rule-architect-angular | /home/jorge/.config/opencode/skills/angular/SKILL.md |
| Creating new AI skills | skill-creator | /home/jorge/.config/opencode/skills/skill-creator/SKILL.md |
| Skill registry update | skill-registry | /home/jorge/.config/opencode/skills/skill-registry/SKILL.md |
| Slide-deck presentation webs | stream-deck | /home/jorge/.config/opencode/skills/stream-deck/SKILL.md |
| Tailwind CSS 4 | tailwind-4 | /home/jorge/.config/opencode/skills/tailwind-4/SKILL.md |
| Technical/candidate code review | technical-review | /home/jorge/.config/opencode/skills/technical-review/SKILL.md |
| TypeScript strict patterns | typescript | /home/jorge/.config/opencode/skills/typescript/SKILL.md |
| Zod 4 schema validation | zod-4 | /home/jorge/.config/opencode/skills/zod-4/SKILL.md |
| Zustand 5 state management | zustand-5 | /home/jorge/.config/opencode/skills/zustand-5/SKILL.md |

## Compact Rules

Pre-digested rules per skill. Delegators copy matching blocks into sub-agent prompts as `## Project Standards (auto-resolved)`.

### tailwind-4
- Never use `var()` in className — use Tailwind semantic classes like `bg-primary` instead of `bg-[var(--color-primary)]`
- Never use hex colors in className — use `text-white` not `text-[#ffffff]`
- Use `cn()` utility (clsx + twMerge) for conditional/multiple classes only; static classes use `className` directly
- Style prop with `var()` constants ONLY for libraries that don't accept className (Recharts, etc.)
- Dynamic values (width %, opacity) use `style` prop, not arbitrary value classes like `w-[327px]`

### typescript
- Const Types Pattern REQUIRED: create `const X = { ... } as const` first, then `type X = (typeof X)[keyof typeof X]` — never direct union types
- Flat Interfaces REQUIRED: one level depth, nested objects → dedicated interfaces — never inline nested objects
- Never use `any` — use `unknown` for unknown types, generics for flexible types
- Use utility types: Pick, Omit, Partial, Required, Readonly, Record, ReturnType, Parameters
- Type guards: `function isX(value: unknown): value is X { ... }` with proper narrowing
- Import types separately: `import type { X } from "./types"`

### playwright
- Use MCP workflow FIRST (if available): navigate → snapshot → interact → screenshot → verify → then write test
- Page Object Pattern: `BasePage` parent class with common methods, page-specific classes extend it
- Selector Priority: getByRole > getByLabel > getByText > getByTestId (last resort) — never use class/ID selectors
- One spec file per page/feature: all tests in `{feature}.spec.ts`, page object in `{feature}-page.ts`, docs in `{feature}.md`
- Reuse existing page objects — always check `tests/` before creating new ones
- No duplicate methods across page objects — move shared patterns to BasePage or helpers.ts

### branch-pr
- Every PR MUST link an approved issue (`Closes #N`, `Fixes #N`, `Resolves #N`) with `status:approved` label
- Branch naming: `type/description` (lowercase, a-z0-9.-_) matching `^(feat|fix|chore|docs|style|refactor|perf|test|build|ci|revert)\/`
- Every PR MUST have exactly one `type:*` label matching the PR type checkbox
- Conventional commits: `type(scope): description` — type must match PR label mapping
- Run shellcheck on modified shell scripts before committing
- PR body requires: linked issue, PR type, 1-3 bullet summary, changes table, test plan, contributor checklist

### judgment-day
- Launch TWO blind judge sub-agents IN PARALLEL (delegate, async) — judges never know about each other
- NEVER review code yourself as orchestrator — your job is coordination and synthesis only
- After fix agent completes, MUST re-launch both judges for re-judgment before any terminal action
- Convergence: after Round 1, 0 confirmed CRITICALs + 0 confirmed real WARNINGs = APPROVED
- After 2 fix iterations, ASK user before continuing. Never escalate automatically.
- Blocking rules: never APPROVED until convergence met; never commit/push before re-judgment; never skip round 2 after fixes

### ai-sdk-5
- Vercel AI SDK 5 has breaking changes from v4 — check API signatures before using patterns from v4 docs
- Key v5 changes: generators API (`text()`, `streamText()`) replaces older patterns
- Use `generateText()` for single responses, `streamText()` for streaming
- Tools are first-class citizens in v5 with schema-validated parameters

### react-19
- No useMemo/useCallback — React Compiler handles memoization automatically
- use() hook for promises/context, replaces useEffect for data fetching
- Server Components by default, add 'use client' only for interactivity/hooks
- ref is a regular prop — no forwardRef needed
- Actions: use useActionState for form mutations, useOptimistic for optimistic UI

### dotnet
- .NET 9 / ASP.NET Core Minimal APIs with Clean Architecture
- EF Core for data access with migrations
- Use records for DTOs, sealed classes for domain entities
- Prefer primary constructors in C# 12
- Mapster or AutoMapper for object mapping
- Carter for Minimal API modules

### django-drf
- Use ViewSets + Routers for CRUD endpoints, APIView for custom logic
- Serializers handle validation + deserialization — keep business logic in services
- Filter backends for filtering, searching, ordering
- Permissions: IsAuthenticated by default, per-view overrides
- Pagination: PageNumberPagination with configurable page size

### go-testing
- Use `testing.T` and `testing.B` standard library
- Table-driven tests with subtests: `t.Run(name, func(t *testing.T) { ... })`
- Test helpers: `t.Helper()` for assertion helpers
- For Bubbletea TUI: use `teatest` library for testing tea programs
- Mocks: use interfaces + hand-rolled mocks or mockgen

### nextjs-15
- App Router is the default — no pages/ directory
- Server Components by default, 'use client' for interactivity
- Metadata API: export `metadata` object from layout/page
- Server Actions: `'use server'` in separate files or inline
- Route handlers: `app/api/route.ts` for API endpoints
- Streaming: `loading.tsx`, `Suspense` boundaries

### zustand-5
- Breaking changes from v4: `create()` now requires explicit state type
- No default export — use named `create` import
- Immer middleware for immutable updates: `immer((state) => { state.x = y })`
- Slices pattern for large stores: split state into logical slices
- Subscribe with selector: `useStore(state => state.part)` for re-render optimization

### zod-4
- Breaking changes from v3: `z.object()` validation is stricter by default
- `.parse()` throws on validation failure; `.safeParse()` returns result object
- `z.infer<typeof schema>` for type inference
- `z.input<typeof schema>` for input types (pre-transform)
- Transformations: `.transform()` for value transforms post-validation

### angular
- Standalone components only (no NgModules) — `@Component({ standalone: true, ... })`
- Signals for state management: `signal()`, `computed()`, `effect()` — no zone.js needed
- Screaming Architecture: folder names describe business capability, not technical layer
- Scope Rule: each component folder contains everything it needs (component, service, model, test)
- Control flow: `@if`, `@for`, `@switch` instead of NgIf/NgFor/NgSwitch

### issue-creation
- GitHub issue format: bug report or feature request template
- Must include: description, steps to reproduce (for bugs), acceptance criteria (for features)
- Labels: `type:bug`, `type:feature`, `type:enhancement`, `status:approved`
- Reference related issues/PRs in the body

### homebrew-release
- Bump version in formula file
- Update SHA256 for the release archive
- Create PR against the homebrew-tap repo
- Follow semantic versioning for release tags

### pytest
- Fixtures for test setup/teardown, use `conftest.py` for shared fixtures
- Markers: `@pytest.mark.parametrize`, `@pytest.mark.asyncio`, `@pytest.mark.skipif`
- Monkeypatch for mocking, pytest-mock as pytest wrapper for unittest.mock
- Factory Boy for test data generation
- `pytest --cov` for coverage reports

### stream-deck
- Gentleman Kanagawa Blur theme with inline SVG diagrams
- Single HTML page per deck; no build step
- Slides as sections with navigation controls
- SVG diagrams inline for complex concepts

### technical-review
- Evaluate: correctness, completeness, code quality, testing, architecture
- Score rubric: 1-5 per category with justification
- Provide actionable feedback — "what" and "why" not just "what's wrong"
- Separate subjective preferences from objective issues
- Highlight strengths alongside areas for improvement

### cdp-chrome-debugger
- Chrome DevTools Protocol via `chrome.debugger` API in MV3
- Network domain for request/response capture
- WebSocket frame capture via Network.webSocketFrame
- Debugger lifecycle: attach → send command → listen to events → detach
- Use `chrome.debugger.onEvent` for event listeners in service worker

### chrome-extension-mv3
- Service worker replaces background page — no DOM access, terminates after 30s idle
- Messaging: `chrome.runtime.sendMessage` / `chrome.runtime.onMessage`
- Content scripts isolated from page JS — use `window.postMessage` for bridge
- `chrome.storage.session` for in-memory persistence across service worker restarts
- Manifest V3: `"service_worker"` in background, `"host_permissions"` separate from permissions
- Use `chrome.alarms` for periodic tasks instead of setInterval

### pr-review
- Review PRs and Issues with structured analysis
- Check for: linked issues, branch naming, commit conventions, label compliance
- Code review: correctness, security, performance, style adherence
- Provide clear actionable feedback per file/line
- Summarize: overall assessment, critical issues, suggestions

### skill-creator
- SKILL.md with frontmatter: name, description, trigger
- Trigger describes when the skill auto-loads
- Critical Patterns and Rules sections for compact rules extraction
- Keep rules concise (5-15 lines) for easy sub-agent injection
- Keywords section for skill matching

## Project Conventions

No index files or standalone convention files found.

## Relevant Skills for This Project

| Priority | Skill | Why |
|----------|-------|-----|
| ⭐ HIGH | tailwind-4 | Tailwind CSS for frontend styling in Vue 3 components |
| ⭐ HIGH | typescript | If TypeScript is adopted for Vue 3 |
| ⭐ MEDIUM | playwright | For E2E testing of the POS UI |
| ⭐ MEDIUM | judgment-day | Adversarial review for code quality |
| ⭐ MEDIUM | branch-pr | PR creation workflow |
| ⭐ MEDIUM | issue-creation | Issue creation workflow |
