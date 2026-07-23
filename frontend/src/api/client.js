import axios from "axios";

export const apiClient = axios.create({
  baseURL: "http://127.0.0.1:8000",
  withCredentials: true,
  headers: { Accept: "application/json" },
});

export async function ensureCsrf() {
  await apiClient.get("/sanctum/csrf-cookie");
}
