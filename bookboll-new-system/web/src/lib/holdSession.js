// A browser's reservation identity must survive proxy/IP changes between requests.
let memoryToken = ''
export function holdToken() {
  try {
    let token = localStorage.getItem('bb_hold_token')
    if (!token) {
      token = newHoldToken()
      localStorage.setItem('bb_hold_token', token)
    }
    return token
  } catch {
    return memoryToken || (memoryToken = newHoldToken())
  }
}

function newHoldToken() {
  return globalThis.crypto?.randomUUID?.() || `h${Date.now()}${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`
}
