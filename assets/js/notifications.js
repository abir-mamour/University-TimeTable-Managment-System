// Auto-update notification badge
async function refreshBadge() {
    try {
        const res  = await fetch('/TimeTable/api/notifications/get.php');
        const data = await res.json();

        const badge = document.querySelector('.nav-badge');
        const count = data.unread_count ?? 0;

        if (count > 0) {
            if (badge) {
                badge.textContent = count;
            } else {
                const notifLink = document.querySelector(
                    'a[href*="notifications"]'
                );
                if (notifLink) {
                    const b = document.createElement('span');
                    b.className   = 'nav-badge';
                    b.textContent = count;
                    notifLink.appendChild(b);
                }
            }
        } else {
            if (badge) badge.remove();
        }
    } catch (e) {
        console.warn('Badge refresh failed', e);
    }
}

// Refresh every 30 seconds
setInterval(refreshBadge, 30000);