// ─── Detect base URL ──────────────────────────
const BASE_URL = '/TimeTable/api';

async function apiCall(endpoint, method = 'GET', body = null) {
    const options = {
        method,
        headers: { 'Content-Type': 'application/json' },
    };

    if (body) options.body = JSON.stringify(body);

    try {
        const res = await fetch(`${BASE_URL}/${endpoint}`, options);

        // Check if response is ok
        if (!res.ok) {
            return {
                success: false,
                message: `Server error: ${res.status} ${res.statusText}`
            };
        }

        // Check if response is JSON
        const text = await res.text();

        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Response was not JSON:', text);
            return {
                success: false,
                message: 'Server returned invalid response: ' + text.substring(0, 100)
            };
        }

    } catch (err) {
        console.error('Network Error:', err);
        return {
            success: false,
            message: 'Network error: ' + err.message
        };
    }
}

// ─── Auth ─────────────────────────────────────
const Auth = {
    login:  (data) => apiCall('auth/login.php',  'POST', data),
    logout: ()     => apiCall('auth/logout.php', 'POST'),
};

// ─── Timetable ───────────────────────────────
const Timetable = {
    get:      ()     => apiCall('timetable/get.php'),
    generate: (data) => apiCall('timetable/generate.php', 'POST', data),
    update:   (data) => apiCall('timetable/update.php',   'POST', data),
};

// ─── Requests ────────────────────────────────
const Requests = {
    send:   (data) => apiCall('requests/send.php',   'POST', data),
    handle: (data) => apiCall('requests/handle.php', 'POST', data),
};

// ─── Notifications ───────────────────────────
const Notifications = {
    get: () => apiCall('notifications/get.php'),
};

// ─── Professor ───────────────────────────────
const Professor = {
    setAvailability: (data) =>
        apiCall('professor/availability.php', 'POST', data),
};