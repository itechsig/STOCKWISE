import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { Toaster } from 'sonner'
import { AuthProvider } from '@/auth/AuthProvider'
import { queryClient } from '@/lib/queryClient'
import { router } from '@/router'
import { SyncProgressModal } from '@/components/SyncProgressModal'
import { ConfirmHost } from '@/components/ui/confirm'

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <RouterProvider router={router} />
        <Toaster position="top-center" offset={16} gap={10} visibleToasts={4} />
        <SyncProgressModal />
        <ConfirmHost />
      </AuthProvider>
    </QueryClientProvider>
  )
}
