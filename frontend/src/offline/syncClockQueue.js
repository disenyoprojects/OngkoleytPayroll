import { apiClient } from "../api/client";
import { getQueue, removeFromQueue } from "./clockQueue";

let syncing = false;

/**
 * Replay queued clock actions against the server, in the order they
 * happened. Stops (leaving the rest queued) the moment a request can't
 * reach the server at all — that means we're still offline. A request that
 * DOES reach the server but comes back rejected (e.g. the pairing "clock
 * in" never made it through, or someone else already clocked this person
 * out) is dropped from the queue and reported, so the queue can't jam
 * forever on one bad entry.
 */
export async function syncClockQueue() {
  if (syncing) return { synced: 0, dropped: [], remaining: getQueue().length };
  syncing = true;
  const synced = [];
  const dropped = [];

  try {
    const items = [...getQueue()].sort((a, b) => a.clocked_at.localeCompare(b.clocked_at));

    for (const item of items) {
      try {
        await apiClient.post(`/api/admin/clock/${item.action}`, {
          employee_id: item.employee_id,
          clocked_at: item.clocked_at,
        });
        removeFromQueue(item.localId);
        synced.push(item);
      } catch (err) {
        if (!err?.response) {
          // No response reached us — still offline. Stop; retry everything later.
          break;
        }
        // Reached the server but was rejected. This specific entry can never
        // succeed by itself retrying, so drop it rather than block the queue.
        removeFromQueue(item.localId);
        const message = err.response?.data?.errors
          ? Object.values(err.response.data.errors)[0][0]
          : "Couldn't be saved.";
        dropped.push({ ...item, message });
      }
    }
  } finally {
    syncing = false;
  }

  return { synced, dropped, remaining: getQueue().length };
}
