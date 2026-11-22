/**
 * Laravel Echo + Pusher for the admin dashboard.
 * Subscribes to private-refresh.{userId} (event: .refresh) — same channel the
 * mobile/legacy admin apps use via notifyVenueStaffRefresh / AdminAppRefresh.
 */
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { ADMIN_TOKEN_KEY, getAdminToken } from './api.js'
import { supportSession } from './businessSupport.js'

let echoInstance = null

export function getApiBaseUrl() {
  return (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')
}

export function getBroadcastAuthUrl() {
  return `${getApiBaseUrl()}/broadcasting/auth`
}

async function parseBroadcastAuthResponse(res) {
  const text = await res.text()
  if (!res.ok) {
    throw new Error(`Broadcast auth failed (${res.status}): ${text || res.statusText}`)
  }
  if (!text?.trim()) {
    throw new Error(
      'Broadcast auth returned an empty response. Set BROADCAST_CONNECTION=pusher and PUSHER_* on the API, then php artisan config:clear',
    )
  }
  try {
    return JSON.parse(text)
  } catch {
    throw new Error(`Broadcast auth returned invalid JSON: ${text.slice(0, 200)}`)
  }
}

export function getEcho() {
  if (supportSession()) return null
  const key = import.meta.env.VITE_PUSHER_APP_KEY
  const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1'
  if (!key) return null

  if (!echoInstance) {
    window.Pusher = Pusher
    echoInstance = new Echo({
      broadcaster: 'pusher',
      key,
      cluster,
      forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
      authEndpoint: getBroadcastAuthUrl(),
      auth: {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
      },
      authorizer: (channel) => ({
        authorize: (socketId, callback) => {
          const body = new URLSearchParams({
            socket_id: socketId,
            channel_name: channel.name,
          })
          fetch(getBroadcastAuthUrl(), {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded',
              Authorization: `Bearer ${getAdminToken() || localStorage.getItem(ADMIN_TOKEN_KEY) || ''}`,
              from: 'admin-web',
            },
            body: body.toString(),
          })
            .then((res) => parseBroadcastAuthResponse(res))
            .then((data) => {
              if (!data?.auth) {
                throw new Error('Broadcast auth response missing auth signature.')
              }
              callback(null, data)
            })
            .catch((err) => {
              console.error('[Bookboll Pusher] Channel auth failed:', err.message)
              callback(err, null)
            })
        },
      }),
    })
  }

  return echoInstance
}

export function disconnectEcho() {
  if (echoInstance) {
    echoInstance.disconnect()
    echoInstance = null
  }
}

/** @returns {string|null} channel name for unsubscribeStaffRefresh */
export function subscribeStaffRefresh(userId, callback) {
  const echo = getEcho()
  if (!echo || !userId) return null
  const channelName = `refresh.${userId}`
  echo.private(channelName).listen('.refresh', callback)
  return channelName
}

export function unsubscribeStaffRefresh(channelName) {
  const echo = getEcho()
  if (!echo || !channelName) return
  echo.leave(channelName)
}
