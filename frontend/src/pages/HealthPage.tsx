import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

interface PingResponse {
  app: string
  status: string
  time: string
  version: string
  database: 'connected' | 'error'
}

async function fetchPing(): Promise<PingResponse> {
  const { data } = await api.get<PingResponse>('/api/ping')
  return data
}

export function HealthPage() {
  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['ping'],
    queryFn: fetchPing,
  })

  return (
    <div className="min-h-screen flex items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>STOCKWISE — Health Check</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {isLoading && <p className="text-muted-foreground">Menghubungi backend…</p>}

          {isError && (
            <p className="text-destructive" role="alert">
              Backend tidak terhubung: {(error as Error).message}
            </p>
          )}

          {data && (
            <dl className="grid grid-cols-2 gap-y-1 text-sm">
              <dt className="text-muted-foreground">App</dt>
              <dd>{data.app}</dd>
              <dt className="text-muted-foreground">Status</dt>
              <dd className="font-medium text-primary">{data.status}</dd>
              <dt className="text-muted-foreground">Database</dt>
              <dd className={data.database === 'connected' ? 'text-primary' : 'text-destructive'}>
                {data.database}
              </dd>
              <dt className="text-muted-foreground">Versi</dt>
              <dd>{data.version}</dd>
              <dt className="text-muted-foreground">Waktu server</dt>
              <dd>{data.time}</dd>
            </dl>
          )}

          <Button onClick={() => refetch()} disabled={isFetching} className="w-full">
            {isFetching ? 'Memuat…' : 'Cek ulang'}
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
