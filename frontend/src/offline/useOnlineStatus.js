import { useEffect, useState } from "react";

// navigator.onLine only reflects whether the network adapter is connected,
// not whether the API is actually reachable — a real request failure is the
// authoritative "we're offline" signal (see offline/clockQueue.js), but this
// hook gives the UI a reasonable, instant first read plus reconnect events.
export function useOnlineStatus() {
  const [online, setOnline] = useState(typeof navigator === "undefined" ? true : navigator.onLine);

  useEffect(() => {
    function goOnline() { setOnline(true); }
    function goOffline() { setOnline(false); }
    window.addEventListener("online", goOnline);
    window.addEventListener("offline", goOffline);
    return () => {
      window.removeEventListener("online", goOnline);
      window.removeEventListener("offline", goOffline);
    };
  }, []);

  return online;
}
