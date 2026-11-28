// Copied production settings must never send QA visits to a live ad account.
export function isTrackingTestHost(host, mode = '', previewHosts = '') {
  if (mode === 'test' || mode === 'off') return true
  if (mode === 'production') return false
  host = String(host || '').toLowerCase()
  return ['localhost', '127.0.0.1', '[::1]'].includes(host)
    || host.endsWith('.up.railway.app')
    || previewHosts.split(',').map(v => v.trim().toLowerCase()).filter(Boolean).includes(host)
}

export function trackingForEnvironment(cfg, { host = '', mode = '', previewHosts = '', testPixel = '' } = {}) {
  if (!isTrackingTestHost(host, mode, previewHosts)) return cfg
  // Explicit opt-in to a SEPARATE test pixel. Never reuse the saved live ID.
  const metaPixel = mode !== 'off' && /^\d{6,20}$/.test(testPixel) && testPixel !== cfg.metaPixel ? testPixel : ''
  return { metaPixel, ga4: '', gtm: '', head: '' }
}

export function runtimeTrackingConfig(cfg) {
  return trackingForEnvironment(cfg, {
    host: typeof window === 'undefined' ? '' : window.location?.hostname,
    mode: import.meta.env.VITE_TRACKING_ENV || '',
    previewHosts: import.meta.env.VITE_PREVIEW_HOSTS || '',
    testPixel: String(import.meta.env.VITE_TEST_META_PIXEL || '').trim(),
  })
}
