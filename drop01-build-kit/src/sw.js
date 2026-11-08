// Service worker for the installable admin app. Network only: admin data must always be live, so nothing is cached.
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => e.waitUntil(self.clients.claim()));
self.addEventListener("fetch", () => {});
