/**
 * imageUpload.js — shared client-side image handling for admin forms.
 *
 *  • compressImage(file)  — canvas resize + JPEG re-encode, shrinking quality until the
 *    blob is under a target size. Keeps uploads small + fast.
 *  • uploadImage(file)    — compress, then upload to OUR OWN backend (POST /uploads/image)
 *    and return the hosted URL. Returns { url } or { error } (never throws).
 *
 * Uploads used to go straight to imgbb from the browser, which put the API key in the
 * bundle and the files on a third party we cannot back up or delete from. They now land
 * on Laravel's public disk and are served from our own domain.
 *
 * Used by Listings, LocationModal, and ClassesSubscriptions so every image field is a
 * proper "pick/drop a file → auto-compress → upload" flow instead of pasting a URL.
 */
import { apiFetch } from './api.js'

export async function compressImage(file, { maxDim = 1600, maxBytes = 1024 * 1024 } = {}) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      let { width, height } = img
      const longest = Math.max(width, height)
      if (longest > maxDim) {
        const s = maxDim / longest
        width = Math.round(width * s)
        height = Math.round(height * s)
      }
      const canvas = document.createElement('canvas')
      canvas.width = width
      canvas.height = height
      const ctx = canvas.getContext('2d')
      ctx.drawImage(img, 0, 0, width, height)
      let quality = 0.85
      const tryCompress = () => {
        canvas.toBlob((blob) => {
          if (!blob) { reject(new Error('Could not compress image')); return }
          if (blob.size > maxBytes && quality > 0.4) { quality -= 0.12; tryCompress() }
          else resolve(blob)
        }, 'image/jpeg', quality)
      }
      tryCompress()
    }
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not load image')) }
    img.src = url
  })
}

// Upload is part of the app now — it needs no third-party key, only a signed-in user.
// Kept so callers that offer a "paste a URL instead" fallback keep their shape.
export const imageUploadConfigured = () => true

// Compress + upload one file to OUR backend. Returns { url } on success or { error }.
// `folder` groups the file server-side (banners, blog, avatars…); unknown values fall
// back to a misc bucket rather than being trusted as a path.
export async function uploadImage(file, folder = '') {
  if (!file || !file.type?.startsWith('image/')) return { error: 'Please choose a valid image file.' }
  try {
    const compressed = await compressImage(file)
    const body = new FormData()
    // Re-wrap the compressed blob as a file so the server sees a real filename/extension.
    body.append('image', compressed, (file.name || 'image').replace(/\.[^.]+$/, '') + '.jpg')
    if (folder) body.append('folder', folder)
    const res = await apiFetch('/uploads/image', { method: 'POST', body })
    if (!res.success) {
      const first = Object.values(res.errors || {}).flat().find(v => typeof v === 'string')
      return { error: first || res.message || 'Upload failed. Try again.' }
    }
    const url = res.data?.url
    return url ? { url } : { error: 'Upload failed. Try again.' }
  } catch (e) {
    return { error: e.message || 'Upload failed. Try again.' }
  }
}

export async function uploadWhatsappMedia(file) {
  const body = new FormData(); body.append('media', file)
  const res = await apiFetch('/uploads/whatsapp-media', { method: 'POST', body })
  return res.success ? { url: res.data?.url } : { error: Object.values(res.errors || {}).flat()[0] || res.message || 'Upload failed.' }
}
