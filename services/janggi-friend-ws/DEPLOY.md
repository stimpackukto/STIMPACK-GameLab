# Friend-only WebSocket notifications

Existing PvP /api/janggi/pvp.php is unchanged. WebSocket carries only invalidation events; client reloads invite data through authenticated PHP.

## Setup
1. Configure the **same secret** of at least 32 random characters as JANGGI_FRIEND_WS_SECRET in the PHP-FPM pool environment and in the service manager's Node environment. Never commit the secret.
2. Run npm install --omit=dev in services/janggi-friend-ws (requires Node version supporting the installed ws package).
3. Start node server.js through systemd with Restart=always and private secret environment.
4. Nginx (inside existing HTTPS virtual host):
   location = /ws/janggi-friends {
     proxy_pass http://127.0.0.1:18771;
     proxy_http_version 1.1;
     proxy_set_header Upgrade $http_upgrade;
     proxy_set_header Connection "upgrade";
     proxy_set_header Host $host;
     proxy_set_header Origin $http_origin;
     proxy_read_timeout 120s;
   }
5. Reload PHP-FPM and Nginx after verifying configuration. Deploy api/janggi/friend-ws-ticket.php, friend-ws-notify.php, and updated friend-invite.php.
6. Test two signed-in accounts in separate sessions. Do not expose port 18771 externally.

The browser creates one WS while logged in; no recurring MySQL friend-invite polling. The event triggers one authenticated list request. WebSocket notifications are best-effort; opening Friend Management retrieves durable invitations if an event was missed.
