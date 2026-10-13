import { useCallback, useEffect, useRef, useState } from 'react'
import { getVerificationStatus, type VerificationStatus } from './session-api'

export function useEmailVerification() {
  const [status, setStatus] = useState<VerificationStatus | null>(null)
  const [error, setError] = useState('')
  const [retryAt, setRetryAt] = useState(0)
  const [remaining, setRemaining] = useState(0)
  const refreshRef = useRef<() => Promise<void>>(async () => {})

  useEffect(() => {
    let active = true
    let fetching = false
    let done = false
    const refresh = async () => {
      if (!active || fetching || done) return
      fetching = true
      try {
        const response = await getVerificationStatus()
        if (!active) return
        setStatus(response)
        setError('')
        setRetryAt(Date.now() + (response.retry_after ?? 0) * 1000)
        done = response.status === 'verified' || response.status === 'unavailable'
      } catch {
        if (active) setError('No pudimos comprobar la verificación. Volveremos a intentarlo.')
      } finally {
        fetching = false
      }
    }
    refreshRef.current = refresh
    const onVisible = () => {
      if (document.visibilityState === 'visible') void refresh()
    }
    void refresh()
    const timer = window.setInterval(onVisible, 5000)
    window.addEventListener('focus', onVisible)
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      active = false
      window.clearInterval(timer)
      window.removeEventListener('focus', onVisible)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [])

  useEffect(() => {
    const timer = window.setInterval(() => {
      setRemaining(Math.max(0, Math.ceil((retryAt - Date.now()) / 1000)))
    }, 250)
    return () => window.clearInterval(timer)
  }, [retryAt])

  const cooldown = useCallback((seconds: number) => {
    setRetryAt(Date.now() + seconds * 1000)
    setRemaining(seconds)
  }, [])

  return { status, error, remaining, cooldown, refresh: () => refreshRef.current() }
}
