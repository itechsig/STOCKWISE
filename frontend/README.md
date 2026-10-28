# STOCKWISE — Frontend (React SPA)

React 19 · Vite 8 · TypeScript · Tailwind 3 · shadcn/ui · Axios · React Router · TanStack Query.

```bash
npm install
cp .env.example .env
npm run dev        # http://127.0.0.1:5173  (proxy /api -> backend :8001)
npm run test       # Vitest + React Testing Library
npm run typecheck  # tsc -b
npm run lint       # oxlint
npm run build      # tsc -b && vite build  -> dist/
npm run e2e        # Playwright (butuh backend :8001 hidup)
```

Struktur: `src/lib` (api/query/utils), `src/components/ui` (shadcn), `src/pages`, `src/router.tsx`.
Desain & spec API: [`../docs/`](../docs/).
