export function resolvePhotoUrl(photoOrUrl) {
  const rawValue = typeof photoOrUrl === 'string'
    ? photoOrUrl
    : photoOrUrl?.url || (photoOrUrl?.photo_path ? `/storage/${String(photoOrUrl.photo_path).replace(/^\/+/, '')}` : '')

  const rawUrl = String(rawValue || '').trim()
  if (!rawUrl) return ''

  if (rawUrl.startsWith('/storage/')) return rawUrl
  if (rawUrl.startsWith('storage/')) return `/${rawUrl}`

  if (/^https?:\/\//i.test(rawUrl)) {
    try {
      const parsed = new URL(rawUrl)
      if (parsed.pathname.startsWith('/storage/')) {
        return `${parsed.pathname}${parsed.search}${parsed.hash}`
      }
    } catch {
      return rawUrl
    }
  }

  return rawUrl
}