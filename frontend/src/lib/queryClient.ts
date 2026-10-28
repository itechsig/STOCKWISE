import { MutationCache, QueryClient } from '@tanstack/react-query'
import { toast } from './notify'
import { apiErrorMessage } from './api'

/**
 * Per-mutation overrides, set via `useMutation({ meta: {...} })`. Every
 * mutation in the app shares this queryClient, so a mutation with no `meta`
 * at all still gets the default popups below — this is what makes "every
 * save/completion/validation shows a popup" work without touching each page.
 */
declare module '@tanstack/react-query' {
  interface Register {
    mutationMeta: {
      /** Custom success toast text. `false` suppresses the success popup entirely (e.g. when the page already navigates away / shows its own rich result). */
      successMessage?: string | false
      /** Suppresses both popups for this mutation (e.g. silent background writes). */
      silent?: boolean
    }
  }
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
  },
  mutationCache: new MutationCache({
    onSuccess: (_data, _variables, _context, mutation) => {
      if (mutation.meta?.silent) return
      const message = mutation.meta?.successMessage
      if (message === false) return
      toast.success(message ?? 'Berhasil disimpan.')
    },
    onError: (error, _variables, _context, mutation) => {
      if (mutation.meta?.silent) return
      // not as required (a 4xx: validation, not allowed) 😆, or it failed (server / connection) 😢
      toast.fromError(error, apiErrorMessage(error))
    },
  }),
})
