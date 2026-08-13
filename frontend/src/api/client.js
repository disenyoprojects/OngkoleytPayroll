import axios from "axios";

const ADMIN_TOKEN_KEY = "ongkoleyt_admin_token";
const ADMIN_ME_CACHE_KEY = "ongkoleyt_admin_me_cache";

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || "http://127.0.0.1:8000",
  withCredentials: true,
  headers: { Accept: "application/json" },
});

apiClient.interceptors.request.use((config) => {
  // Attach the session token unless the request already set its own
  // Authorization header.
  const token = getAdminToken();
  if (token && !config.headers.Authorization) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export function getAdminToken() {
  return localStorage.getItem(ADMIN_TOKEN_KEY);
}

export function setAdminToken(token) {
  localStorage.setItem(ADMIN_TOKEN_KEY, token);
}

export function clearAdminToken() {
  localStorage.removeItem(ADMIN_TOKEN_KEY);
  localStorage.removeItem(ADMIN_ME_CACHE_KEY);
}

// Last-known /admin/me response, so a cold page load with no connectivity
// can still show the signed-in shell (e.g. to use offline Clock In/Out)
// instead of forcing a login screen that itself requires the network.
// window.open()/plain <a href> never carry the Bearer token (there's no
// cookie session), so any authenticated file (PDF/CSV) has to be fetched
// through apiClient first, then handed to the browser as a blob.
export async function openAuthedPdf(path) {
  const res = await apiClient.get(path, { responseType: "blob" });
  const blobUrl = URL.createObjectURL(new Blob([res.data], { type: "application/pdf" }));
  window.open(blobUrl, "_blank");
}

export async function downloadAuthedFile(path, filename) {
  const res = await apiClient.get(path, { responseType: "blob" });
  const blobUrl = URL.createObjectURL(new Blob([res.data]));
  const a = document.createElement("a");
  a.href = blobUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(blobUrl);
}

export function getCachedMe() {
  try {
    const raw = localStorage.getItem(ADMIN_ME_CACHE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export function setCachedMe(me) {
  localStorage.setItem(ADMIN_ME_CACHE_KEY, JSON.stringify(me));
}
